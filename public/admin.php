<?php
declare(strict_types=1);

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "admin.php is a web page. Open it over HTTPS in a browser.\n");
    exit(1);
}

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; style-src \'self\'; form-action \'self\'; base-uri \'none\'; frame-ancestors \'none\'');

$config = require __DIR__ . '/bootstrap.php';
$admin = $config['admin'] ?? [];
$adminUser = (string) ($admin['username'] ?? '');
$adminPasswordHash = (string) ($admin['password_hash'] ?? '');
if ($adminUser === '' || $adminPasswordHash === '') {
    http_response_code(503);
    exit('管理画面は設定されていません。');
}

$providedUser = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
$providedPassword = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
if (!hash_equals($adminUser, $providedUser) || !password_verify($providedPassword, $adminPasswordHash)) {
    header('WWW-Authenticate: Basic realm="OSM Japan Changes 管理"');
    http_response_code(401);
    exit('認証が必要です。');
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Strict',
]);
session_start();
if (!isset($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

require __DIR__ . '/profile-lib.php';
$db = $config['db'];
$pdo = new PDO(
    "mysql:host={$db['host']};dbname={$db['dbname']};charset={$db['charset']}",
    $db['user'],
    $db['pass'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$flash = is_array($_SESSION['admin_flash'] ?? null) ? $_SESSION['admin_flash'] : [];
unset($_SESSION['admin_flash']);
$message = (string) ($flash['message'] ?? '');
$errorMessage = (string) ($flash['errorMessage'] ?? '');
$syncResult = (string) ($flash['syncResult'] ?? '');
$badgeResult = (string) ($flash['badgeResult'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = '';
    $errorMessage = '';
    $syncResult = '';
    $badgeResult = '';
    try {
        $token = (string) ($_POST['csrf'] ?? '');
        if (!hash_equals((string) $_SESSION['admin_csrf'], $token)) {
            throw new RuntimeException('確認情報の有効期限が切れました。ページを再読み込みしてください。');
        }
        if (($_POST['confirm'] ?? '') !== 'yes') {
            throw new RuntimeException('確認欄を選択してから実行してください。');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'rebuild_badges') {
            $result = profileApplyBadgeDefinitions($pdo);
            $message = sprintf(
                'バッジ条件を反映しました。%dマッパー、新規%d件、進捗更新%d件（全%d件）',
                $result['mapperCount'], $result['insertedCount'], $result['updatedCount'], $result['afterCount']
            );
            $badgeResult = sprintf(
                "対象マッパー: %d人\n反映前: %d件\n新規追加: %d件\n進捗更新: %d件\n反映後: %d件",
                $result['mapperCount'], $result['beforeCount'], $result['insertedCount'],
                $result['updatedCount'], $result['afterCount']
            );
        } elseif ($action === 'profile_sync') {
            set_time_limit(0);
            if (!defined('OSM_PROFILE_SYNC_ADMIN')) define('OSM_PROFILE_SYNC_ADMIN', true);
            $runProfileSync = static function (): string {
                ob_start();
                try {
                    require __DIR__ . '/profile-sync.php';
                    return trim((string) ob_get_clean());
                } catch (Throwable $error) {
                    ob_end_clean();
                    throw $error;
                }
            };
            $syncOutput = $runProfileSync();
            $syncResult = $syncOutput !== '' ? $syncOutput : 'Profile aggregation completed.';
            if (preg_match(
                '/(\d+) mappers, (\d+) months, (\d+) categories, (\d+) prefectures, (\d+) avatar checks/',
                $syncOutput,
                $matches
            )) {
                $message = sprintf(
                    'プロフィール集計を更新しました。%dマッパー、%dか月、%dカテゴリ、%d地域、画像確認%d人',
                    (int) $matches[1], (int) $matches[2], (int) $matches[3], (int) $matches[4], (int) $matches[5]
                );
            } else {
                $message = 'プロフィール集計を更新しました。';
            }
        } else {
            throw new RuntimeException('未対応の操作です。');
        }
    } catch (Throwable $error) {
        $errorMessage = ($_POST['action'] ?? '') === 'profile_sync'
            ? 'プロフィール集計を更新できませんでした。しばらく待ってから再実行してください。'
            : '条件を反映できませんでした。データベースとプロフィール集計の状態を確認してください。';
        if (($_POST['action'] ?? '') === 'profile_sync') $syncResult = 'Profile aggregation failed.';
        if (($_POST['action'] ?? '') === 'rebuild_badges') $badgeResult = 'バッジ条件の反映に失敗しました。';
        error_log('Badge admin update failed: ' . $error->getMessage());
    }
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    $_SESSION['admin_flash'] = compact('message', 'errorMessage', 'syncResult', 'badgeResult');
    header('Location: admin.php', true, 303);
    exit;
}

$badgeCount = (int) $pdo->query('SELECT COUNT(*) FROM mapper_badges')->fetchColumn();
$mapperCount = (int) $pdo->query('SELECT COUNT(*) FROM mapper_profile_stats')->fetchColumn();
$profileUpdatedAt = $pdo->query('SELECT MAX(calculated_at) FROM mapper_profile_stats')->fetchColumn() ?: null;
$awardRows = $pdo->query(
    'SELECT badge_key, COUNT(*) AS total FROM mapper_badges GROUP BY badge_key'
)->fetchAll();
$awardCounts = [];
foreach ($awardRows as $row) $awardCounts[$row['badge_key']] = (int) $row['total'];
$definitions = profileBadgeDefinitions();
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>バッジ管理｜OSM Japan Changes</title>
  <link rel="stylesheet" href="admin.css?v=1">
</head>
<body class="admin-page">
<main>
  <h1>バッジ管理</h1>
  <p>現在のプロフィール集計値を使って、新しいバッジ条件をデータベースへ反映します。</p>
  <?php if ($message !== ''): ?><p class="admin-notice" role="status"><?= $escape($message) ?></p><?php endif; ?>
  <?php if ($errorMessage !== ''): ?><p class="admin-notice is-error" role="alert"><?= $escape($errorMessage) ?></p><?php endif; ?>
  <?php if (!$isHttps): ?><p class="admin-notice is-error">本番環境では必ずHTTPSでアクセスしてください。</p><?php endif; ?>

  <section class="content-panel admin-card">
    <h2>現在の状態</h2>
    <div class="admin-summary">
      <div><strong><?= number_format($mapperCount) ?></strong><span>集計済みマッパー</span></div>
      <div><strong><?= number_format($badgeCount) ?></strong><span>獲得済みバッジ</span></div>
      <div><strong><?= $profileUpdatedAt ? $escape($profileUpdatedAt) : '未集計' ?></strong><span>プロフィール最終集計（UTC）</span></div>
    </div>
  </section>

  <section class="content-panel admin-card">
    <h2>現在のバッジ条件</h2>
    <table class="admin-definitions">
      <thead><tr><th>バッジ</th><th>条件</th><th>獲得者</th></tr></thead>
      <tbody>
      <?php foreach ($definitions as $key => $definition): ?>
        <tr><td><?= $escape($definition['icon'] . ' ' . $definition['name']) ?></td><td><?= $escape($definition['description']) ?></td><td><?= number_format($awardCounts[$key] ?? 0) ?>人</td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section class="content-panel admin-card">
    <h2>プロフィール集計を更新</h2>
    <p>最新の地物データから、更新件数、活動日数、地域、カテゴリ、バッジを再集計します。プロフィール画像は設定された上限内で確認します。</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $escape($_SESSION['admin_csrf']) ?>">
      <input type="hidden" name="action" value="profile_sync">
      <label><input type="checkbox" name="confirm" value="yes" required> プロフィール集計を実行します</label>
      <button class="admin-primary-button" type="submit">プロフィール集計を更新する</button>
    </form>
    <?php if ($syncResult !== ''): ?>
      <div class="admin-result" role="status"><strong>実行結果</strong><pre><?= $escape($syncResult) ?></pre></div>
    <?php endif; ?>
  </section>

  <section class="content-panel admin-card">
    <h2>バッジ条件を反映</h2>
    <p>条件を満たした未獲得バッジを追加し、進捗の最高値を更新します。獲得済みバッジ、獲得日時、手動付与情報は削除しません。</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $escape($_SESSION['admin_csrf']) ?>">
      <input type="hidden" name="action" value="rebuild_badges">
      <label><input type="checkbox" name="confirm" value="yes" required> 内容を確認しました</label>
      <button class="admin-danger-button" type="submit">現在の条件を反映する</button>
    </form>
    <?php if ($badgeResult !== ''): ?>
      <div class="admin-result" role="status"><strong>実行結果</strong><pre><?= $escape($badgeResult) ?></pre></div>
    <?php endif; ?>
  </section>
  <small>地物データとプロフィール集計値は削除されません。</small>
</main>
</body>
</html>
