# osm-japan-changes

日本国内の最近のOpenStreetMap更新を収集し、検索・集計して提供する共通バックエンドです。OSM What's New Japanからバックエンド機能を分離し、GitHub Pagesなど別ホストのフロントエンドからも利用できる読み取り専用APIとして構成しています。

## 機能

- OSM APIの変更セットから、タグ付きnodeと対象タグを持つ一部のway・relationを定期収集
- 日本の都道府県判定と、国外・対象外データの除外
- 地物、全国集計、都道府県集計、ファセット、マッパープロフィール、バッジのJSON API
- OSMオブジェクトの全タグを対象にした汎用タグ検索
- マッパープロフィールとバッジの定期集計
- Basic認証付き管理画面
- 設定可能なCORSと、ローカル完結のDockerテスト環境

バックエンド本体は `public/` にあります。公開時はこのディレクトリだけをDocument Rootにしてください。DB認証情報を含む設定ファイルはリポジトリ直下など、Document Rootの外に置きます。

## 必要環境

- PHP 8.0以上
- PDO MySQL、SimpleXML、mbstring、JSON拡張
- MySQL 8またはMariaDB 10.6以上
- cronなど、CLIバッチを定期実行できる環境
- OSM APIへ接続できるネットワーク

## セットアップ

設定例をコピーし、DB接続情報、OSM User-Agentの連絡先、管理画面の認証情報を変更します。実際の設定ファイルはGit管理対象外です。

```shell
cp private-osm-config.example.php private-osm-config.php
php -r "echo password_hash('管理用パスワード', PASSWORD_DEFAULT), PHP_EOL;"
```

設定ファイルを別の場所に置く場合は、`OSM_APP_CONFIG`へ絶対パスを指定します。`cors.allowed_origins`の初期例は、公開読み取りAPIとして全Originを許可する `*` です。配信元を限定する場合は、次のように完全なOriginを列挙します。

```php
'cors' => [
    'allowed_origins' => [
        'https://k-sakanoshita.github.io',
        'https://example.com',
    ],
],
```

事前に `osm_japan_changes` データベースと接続ユーザーを作成し、必要な権限を付与してからスキーマを適用します。次のコマンド自体はDBやユーザーを作成しません。

```shell
mysql -u osm -p osm_japan_changes < public/schema.sql
```

PHP対応Webサーバーでは `public/` をDocument Rootに設定します。開発時は次のコマンドで起動できます。

```shell
php -S 127.0.0.1:8000 -t public
```

### 定期処理

`sync.php`はOSM更新データを収集し、`profile-sync.php`はプロフィール・バッジ集計を更新します。どちらも直接のWebアクセスは拒否します。プロフィール集計は、認証済み管理画面からも実行できます。

```shell
php public/sync.php
php public/profile-sync.php
```

例えばcronでは収集を6分ごと、プロフィール集計を1日1回実行します。同時実行は各バッチ内のロックで抑止されます。

```cron
*/6 * * * * OSM_APP_CONFIG=/absolute/path/private-osm-config.php php /absolute/path/public/sync.php
15 3 * * * OSM_APP_CONFIG=/absolute/path/private-osm-config.php php /absolute/path/public/profile-sync.php
```

## API

エンドポイントは `public/api.php` です。既定の `mode` は `pois` です。

| mode | 内容 |
|---|---|
| `pois` | 地物一覧とページングカーソルを返す |
| `japan` | 全国の更新数、マッパー、変更セット、カテゴリ、日別集計 |
| `prefectures` | 都道府県別件数 |
| `facets` | マッパーと代表カテゴリの検索候補 |
| `profile` | マッパープロフィール |
| `mapper_search` | マッパー名の前方一致検索 |
| `profile_region_mappers` | 全国または都道府県別のマッパー一覧 |
| `badge_mappers` | 指定バッジの獲得者一覧 |

地物検索・更新集計（`pois`、`japan`、`prefectures`、`facets`）では、`days`、`from` / `to`、`prefecture`、`prefecture_code`、`editor_uid`、`editor_name`、`category`、`category_value`、`action`を指定できます。

- `days`: `1`、`2`、`7`、`14`、`30`、`90`、`183`、`365`。省略時と対象外の値は14日です。
- `from` / `to`: 両方を `YYYY-MM-DD` 形式で指定します。日本時間の日付として終了日全体を含み、`days`より優先されます。
- `prefecture_code`: `01`〜`47`の2桁コード（大阪府は `27`）。従来の `prefecture=大阪府` も利用できます。両方を指定する場合は同じ都道府県である必要があります。
- `limit`: `pois`の取得件数。既定1,000件、最大5,000件です。応答の `meta.nextCursor` を次のリクエストの `cursor` に指定し、ほかの検索条件を維持して続きから取得します。`nextCursor`が `null` なら最終ページです。

プロフィール系APIは `profile-sync.php`で作成した集計を参照し、地物検索の期間・カテゴリ条件で再集計しません。`profile`は `editor_uid`、`mapper_search`は `q`、`badge_mappers`は `badge_key`が必須です。`profile_region_mappers`は都道府県を省略すると全国を対象にします。

```text
/api.php?mode=pois&days=30&prefecture_code=27&limit=100
/api.php?mode=japan&from=2026-09-01&to=2026-09-07
/api.php?mode=mapper_search&q=sample
```

### 汎用タグ検索

`mode=pois`では、代表カテゴリとは独立して、`osm_poi.tags`のJSONに保存された全タグを検索できます。初期実装の対象はnodeです。

タグキーの存在検索:

```text
/api.php?mode=pois&days=30&prefecture=大阪府&tag_key=playground
```

タグ値の完全一致検索:

```text
/api.php?mode=pois&days=30&prefecture=大阪府&tag_key=playground&tag_value=swing
```

歴史・文化関連タグも同じAPIで検索できます。

```text
/api.php?mode=pois&days=30&prefecture=京都府&tag_key=historic
```

`tag_value`の単独指定、空の`tag_key`、255文字を超える値、不正なUTF-8、`pois`以外でのタグ指定はHTTP 400になります。キー中のドットやワイルドカード文字はJSONPathの演算子ではなく、タグキーの文字として扱います。

## ローカルテスト

Linux上でDocker Engine、Docker Composeプラグイン、ホスト側のPHPと必要拡張、`curl`、`start-stop-daemon`を用意します。MariaDBとphpMyAdminはコンテナで、PHP開発サーバーはホストで動き、作業ツリーを直接参照します。起動時にスキーマを適用し、テストデータのバージョンが変わった場合に投入し、プロフィール集計を更新します。

```shell
./scripts/test-env-docker.sh start
./scripts/test-api.sh
./scripts/test-env-docker.sh status
./scripts/test-env-docker.sh stop
```

既定URLはAPIが `http://127.0.0.1:8000/api.php`、phpMyAdminが `http://127.0.0.1:8081/`、MariaDBが `127.0.0.1:3307` です。ポートは `WEB_PORT`、`PHPMYADMIN_PORT`、`MYSQL_PORT`で変更できます。DBデータはDocker volumeに保持され、`stop`でも削除されません。PHPサーバーの既定の待受は `0.0.0.0` です。このPCだけで利用する場合は `WEB_HOST=127.0.0.1 ./scripts/test-env-docker.sh start` とします。

APIのポートを変更した場合、テストにも `API_URL=http://127.0.0.1:変更したポート/api.php` を指定してください。テストは同梱データと既定のCORS設定を前提とします。ログは `./scripts/test-env-docker.sh logs`、SQLの取り込みは `./scripts/test-env-docker.sh import /path/to/dump.sql` で実行できます。

既存のMySQLを使う場合は、DBとスキーマを事前に用意し、ホストにphpMyAdmin（既定 `/usr/share/phpmyadmin`）をインストールします。`private-osm-test-config.php` または `OSM_APP_CONFIG`を用意し、次を使えます。この方式ではテストデータの自動投入は行いません。

```shell
./scripts/test-env.sh start
./scripts/test-env.sh status
./scripts/test-env.sh stop
```

## 管理画面

`public/admin.php`はBasic認証とCSRF対策を備え、プロフィール再集計とバッジ条件の反映を実行します。本番では必ずHTTPSで公開し、設定の `admin.password_hash`には `password_hash()`の出力だけを保存してください。

## 主なファイル

| パス | 役割 |
|---|---|
| `public/api.php` | 公開JSON API、検索、集計、CORS |
| `public/sync.php` | OSM変更セットの収集 |
| `public/profile-sync.php` | プロフィール・バッジ集計 |
| `public/profile-lib.php` | レベル・バッジ定義と集計ロジック |
| `public/admin.php` | 認証付き管理画面 |
| `public/schema.sql` | DBテーブルとインデックス |
| `public/data/prefectures.min.geojson` | 都道府県判定用ポリゴン |
| `private-osm-config.example.php` | 非公開設定の例 |
| `scripts/test-env-docker.sh` | Dockerテスト環境 |
| `scripts/test-api.sh` | 汎用タグ検索とCORSの結合テスト |

## ライセンス

[MIT License](LICENSE)
