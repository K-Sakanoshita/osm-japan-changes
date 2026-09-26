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

本番で `public/` の内容を `/home/armd-01/www/osm-japan-changes/` に配置する場合、設定ファイルはWeb公開ディレクトリの外にある `/home/armd-01/private-osm-config.php` から読み込みます。ローカル開発ではリポジトリ直下の `private-osm-config.php` を使用します。別の場所に置く場合は、`OSM_APP_CONFIG`へ絶対パスを指定します。

`cors.allowed_origins`の初期例は、公開読み取りAPIとして全Originを許可する `*` です。配信元を限定する場合は、次のように完全なOriginを列挙します。

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

## APIの使い方

エンドポイントは `public/api.php` です。既定の `mode` は `pois` です。ローカルテスト環境を起動した場合は `http://127.0.0.1:8000/api.php` で呼び出せます。例えば、過去30日間に更新された高槻市の地物を取得するには次を実行します。

```shell
curl 'http://127.0.0.1:8000/api.php?mode=pois&municipality_code=272078&days=30'
```

市区町村検索は、同期または手動バックフィルで自治体コードが保存された地物を対象にします。ローカルテストDBには高槻市のPOIが含まれないため、上のクエリは `items: []` になります。ローカルで市区町村コード検索を試す場合は、同梱データがある大阪市のコードを使えます。

```shell
curl 'http://127.0.0.1:8000/api.php?mode=pois&municipality_code=271004&from=2020-01-01&to=2099-12-31&limit=5'
```

| mode | 内容 |
|---|---|
| `pois` | 地物一覧とページングカーソルを返す |
| `objects` | 指定したOSM IDを最大100件まとめて返す |
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

`mode=pois`では、代表カテゴリとは独立して、`osm_poi.tags`のJSONに保存された全タグを検索できます。収集済みのnode / way / relationが対象です。

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

### 作成日時・OSM ID・市区町村コード

`mode=pois` では最終更新日時（`days` / `from` / `to`）に加えて、OSM 上の作成日時（`new_days` または `created_from` / `created_to`）で検索できます。作成日時だけを指定した場合、最終更新日の既定14日条件は適用されません。日付範囲は日本時間の暦日です。応答には `date`（最終更新）と `createdAt`（作成）を含みます。作成日時が不明な既存地物の `createdAt` は null です。

```text
/api.php?mode=pois&new_days=7&prefecture_code=27&tag_key=playground
/api.php?mode=pois&created_from=2026-09-18&created_to=2026-09-25&category=leisure&category_value=park
/api.php?mode=pois&days=7&municipality_code=272078
/api.php?mode=pois&days=7&municipality_code=271004&ward_code=271276&tag_key=playground
```

`municipality_code` と `ward_code` は6桁の全国地方公共団体コードで、`mode=pois` のみ対応します。住所情報が判定できない地物や特殊区域では null を返します。タグ検索は収集済みの node / way / relation を対象とします。way / relation は従来の対象タグに加え、`playground=*` と `landuse=recreation_ground` が対象です。

`mode=objects` では最大100件の `node/ID`、`way/ID`、`relation/ID` を一括照会できます。重複を除き、存在する地物だけを入力順で返します。不正な ID 形式は HTTP 400 です。読み取り専用で、既存の CORS 設定を使います。

```text
/api.php?mode=objects&ids=node/123456,way/987654,relation/12345
```

## ローカルテスト

Linux上でDocker Engine、Docker Composeプラグイン、ホスト側のPHPと必要拡張、`curl`、`start-stop-daemon`を用意します。MariaDBとphpMyAdminはコンテナで、PHP開発サーバーはホストで動き、作業ツリーを直接参照します。起動時にスキーマを適用し、テストデータのバージョンが変わった場合に投入し、プロフィール集計を更新します。

```shell
./scripts/test-env-docker.sh start
./scripts/test-api.sh
php scripts/test-municipalities.php
./scripts/test-env-docker.sh status
./scripts/test-env-docker.sh stop
```

既定URLはAPIが `http://127.0.0.1:8000/api.php`、phpMyAdminが `http://127.0.0.1:8081/`、MariaDBが `127.0.0.1:3307` です。ポートは `WEB_PORT`、`PHPMYADMIN_PORT`、`MYSQL_PORT`で変更できます。DBデータはDocker volumeに保持され、`stop`でも削除されません。PHPサーバーの既定の待受は `0.0.0.0` です。このPCだけで利用する場合は `WEB_HOST=127.0.0.1 ./scripts/test-env-docker.sh start` とします。

既定の `3307` または `8081` が別のDocker環境で使用中なら、空いているポートを指定します。例えば次の設定ではAPIは既定の `8000` のままなので、`test-api.sh` に追加設定は不要です。

```shell
MYSQL_PORT=13307 PHPMYADMIN_PORT=18081 ./scripts/test-env-docker.sh start
./scripts/test-api.sh
MYSQL_PORT=13307 PHPMYADMIN_PORT=18081 ./scripts/test-env-docker.sh status
MYSQL_PORT=13307 PHPMYADMIN_PORT=18081 ./scripts/test-env-docker.sh stop
```

Dockerの `buildx isn't installed` 警告が表示されても、起動が完了していればテストを実行できます。起動に失敗した場合はAPIサーバーも立ち上がらないため、ポートの競合を解消してから `test-api.sh` を実行してください。

APIのポートを変更した場合、テストにも `API_URL=http://127.0.0.1:変更したポート/api.php` を指定してください。テストは同梱データと既定のCORS設定を前提とします。ログは `./scripts/test-env-docker.sh logs`、SQLの取り込みは `./scripts/test-env-docker.sh import /path/to/dump.sql` で実行できます。

既存のMySQLを使う場合は、DBとスキーマを事前に用意し、ホストにphpMyAdmin（既定 `/usr/share/phpmyadmin`）をインストールします。`private-osm-test-config.php` または `OSM_APP_CONFIG`を用意し、次を使えます。この方式ではテストデータの自動投入は行いません。

```shell
./scripts/test-env.sh start
./scripts/test-env.sh status
./scripts/test-env.sh stop
```

## 行政界データの更新

`scripts/build-municipalities.sh` は OSM 行政界を取得し、mapshaper の topology-aware な5%簡略化、bbox 再計算、e-Stat 標準地域コードCSVとの突合を順に実行します。必要なコマンドは `curl`、`jq`、`osmtogeojson`、`mapshaper`、`python3`（`shapely` を含む）です。既定のCSVは `scripts/FEA_hyoujun-20260926112545.csv` で、別CSVのパスを第1引数に指定できます。

```shell
./scripts/build-municipalities.sh
php scripts/test-municipalities.php
```

生成結果は `public/data/municipalities.min.geojson` に保存され、通常同期ではこのローカルファイルを1回読み込みます。都道府県内で市区町村が見つからない場合は全国の境界を再検索します。簡略化された境界を使うため、市区町村は「そのあたり」を示す目安で、全国再検索で別の都道府県の自治体が一意に見つかった場合、保存する都道府県名もその自治体コードに合わせます。生成時の大きな中間ファイルは一時ディレクトリから削除されます。既存POIの補完は専用のバックフィルコマンドで行い、通常の6分同期に全件処理を加えません。バックフィルは現在の行政界データで全POIを再判定するため、行政界を更新した後にも再実行できます。

### 既存POIの地域情報を補完する

`scripts/backfill-regions.sh` は、未設定の都道府県を補完してから、既存POIの市区町村・区情報を再判定します。通常同期とは別に手動実行します。ローカルテストDBに適用する場合は、テスト環境を起動したうえで接続設定とDBポートを指定してください。

```shell
OSM_APP_CONFIG="$PWD/docker/test-host/private-osm-test-config.php" OSM_TEST_DB_PORT=13307 ./scripts/backfill-regions.sh
```

本番DBに適用する場合は、本番用の `OSM_APP_CONFIG` を指定します。都道府県だけの補完には `php scripts/backfill-prefectures.php` を使用でき、`--dry-run` では更新せず対象件数を確認できます。市区町村だけを再判定する場合は `php scripts/backfill-municipalities.php` を使用します。バックフィルは地物を新たに収集しないため、対象地域のPOIがDBに存在しなければAPIの結果は増えません。

### FTPで公開ディレクトリだけを配置している場合

本番の `public/` の中身を `/home/armd-01/www/osm-japan-changes/` に配置している場合、管理用スクリプトはWeb公開ディレクトリの外に置きます。FTPソフトの開始フォルダが `www` なら、ホームディレクトリ `/home/armd-01/` へ移動し、`maintenance/scripts/` を作って次の3ファイルをアップロードします。

```text
/home/armd-01/maintenance/scripts/backfill-regions.sh
/home/armd-01/maintenance/scripts/backfill-prefectures.php
/home/armd-01/maintenance/scripts/backfill-municipalities.php
```

公開先には最新版の `bootstrap.php`、`prefecture-lib.php`、`municipality-lib.php`、`data/prefectures.min.geojson`、`data/municipalities.min.geojson` が必要です。SSHでログイン後、bashから公開先と非公開設定ファイルのパスを指定して実行します。

```shell
bash
export OSM_PUBLIC_DIR="$HOME/www/osm-japan-changes"
export OSM_APP_CONFIG="$HOME/private-osm-config.php"
php -v
bash "$HOME/maintenance/scripts/backfill-regions.sh"
```

`OSM_PUBLIC_DIR` は `api.php` と `bootstrap.php` が置かれたディレクトリを指定します。PHP CLIとSSHが利用できない契約では、このCLIスクリプトはFTPアップロードだけでは実行できません。

## 管理画面

`public/admin.php`はBasic認証とCSRF対策を備え、プロフィール再集計とバッジ条件の反映を実行します。CLI用スクリプトではないため、`php admin.php`ではなくHTTPSのURLをブラウザで開いてください。本番では必ずHTTPSで公開し、設定の `admin.password_hash`には `password_hash()`の出力だけを保存してください。

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
| `scripts/test-api.sh` | API検索とCORSの結合テスト |
| `scripts/test-municipalities.php` | 実データを使う市区町村判定テスト |
| `scripts/backfill-regions.sh` | 既存POIの都道府県・市区町村情報を補完 |

## ライセンス

[MIT License](LICENSE)
