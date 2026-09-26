#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
DOCUMENT_ROOT="${PROJECT_ROOT}/public"
COMPOSE_FILE="${PROJECT_ROOT}/compose.test.yml"
APP_CONFIG="${PROJECT_ROOT}/docker/test-host/private-osm-test-config.php"
SCHEMA_FILE="${DOCUMENT_ROOT}/schema.sql"
PROFILE_TEST_DATA_FILE="${PROJECT_ROOT}/docker/test-db/profile-test-data.sql"
PROFILE_TEST_DATA_VERSION="9"

WEB_HOST="${WEB_HOST:-0.0.0.0}"
WEB_PORT="${WEB_PORT:-8000}"
MYSQL_PORT="${MYSQL_PORT:-3307}"
PHPMYADMIN_HOST="${PHPMYADMIN_HOST:-127.0.0.1}"
PHPMYADMIN_PORT="${PHPMYADMIN_PORT:-8081}"
TEST_RUNTIME_DIR="${TEST_RUNTIME_DIR:-/tmp}"

RUNTIME_DIR="${TEST_RUNTIME_DIR}/osm-japan-changes-${UID}"
WEB_PID_FILE="${RUNTIME_DIR}/web.pid"
WEB_LOG_FILE="${RUNTIME_DIR}/web.log"

export MYSQL_PORT PHPMYADMIN_PORT

info() {
    printf '[test-env] %s\n' "$*"
}

fail() {
    printf '[test-env] ERROR: %s\n' "$*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "必要なコマンドがありません: $1"
}

require_environment() {
    require_command docker
    require_command php
    require_command curl
    require_command start-stop-daemon
    docker compose version >/dev/null 2>&1 \
        || fail "Docker Composeプラグインがありません。"
    docker info >/dev/null 2>&1 \
        || fail "Dockerへ接続できません。Dockerの起動状態とユーザー権限を確認してください。"
    php -r '
        $required = ["pdo_mysql", "mbstring", "SimpleXML"];
        foreach ($required as $extension) {
            if (!extension_loaded($extension)) {
                fwrite(STDERR, $extension . PHP_EOL);
                exit(1);
            }
        }
    ' || fail "PHP拡張 pdo_mysql、mbstring、SimpleXML が必要です。"
}

validate_port() {
    local name="$1" value="$2"
    [[ "${value}" =~ ^[0-9]+$ ]] || fail "${name} は数値で指定してください。"
    ((value >= 1 && value <= 65535)) || fail "${name} は1～65535で指定してください。"
}

validate_settings() {
    validate_port WEB_PORT "${WEB_PORT}"
    validate_port MYSQL_PORT "${MYSQL_PORT}"
    validate_port PHPMYADMIN_PORT "${PHPMYADMIN_PORT}"
    [[ "${WEB_PORT}" != "${MYSQL_PORT}" ]] || fail "WebとMariaDBに同じポートは指定できません。"
    [[ "${WEB_PORT}" != "${PHPMYADMIN_PORT}" ]] \
        || fail "WebとphpMyAdminに同じポートは指定できません。"
    [[ "${MYSQL_PORT}" != "${PHPMYADMIN_PORT}" ]] \
        || fail "MariaDBとphpMyAdminに同じポートは指定できません。"
    [[ "${TEST_RUNTIME_DIR}" = /* ]] || fail "TEST_RUNTIME_DIR は絶対パスで指定してください。"
    [[ -f "${APP_CONFIG}" ]] || fail "ローカルDB設定がありません: ${APP_CONFIG}"
    [[ -r "${SCHEMA_FILE}" ]] || fail "スキーマを読み込めません: ${SCHEMA_FILE}"
    [[ -r "${PROFILE_TEST_DATA_FILE}" ]] \
        || fail "プロフィール用テストデータを読み込めません: ${PROFILE_TEST_DATA_FILE}"
}

compose() {
    docker compose \
        --project-directory "${PROJECT_ROOT}" \
        -f "${COMPOSE_FILE}" \
        "$@"
}

database_query() {
    compose exec -T database mariadb \
        --batch --skip-column-names \
        --user=osm --password=osm-local-test osm_whatnew \
        --execute="$1"
}

apply_sql_file() {
    compose exec -T database \
        mariadb --user=osm --password=osm-local-test osm_whatnew <"$1"
}

prepare_test_database() {
    local seed_version

    info "ローカルDBへ最新スキーマを適用します。"
    apply_sql_file "${SCHEMA_FILE}" >/dev/null

    seed_version="$(database_query \
        "SELECT COALESCE(MAX(state_value), '') FROM osm_sync_state WHERE state_key = 'profile_test_data_version'")"

    if [[ "${seed_version}" != "${PROFILE_TEST_DATA_VERSION}" ]]; then
        info "ローカルDBへプロフィール用テストデータを投入します。"
        apply_sql_file "${PROFILE_TEST_DATA_FILE}"
        info "プロフィール用テストデータを投入しました。"
    else
        info "プロフィール用テストデータは投入済みです。"
    fi
}

refresh_test_profiles() {
    info "プロフィール集計を更新します。"
    OSM_APP_CONFIG="${APP_CONFIG}" OSM_TEST_DB_PORT="${MYSQL_PORT}" \
        php "${DOCUMENT_ROOT}/profile-sync.php"
}

is_managed_web_pid() {
    local pid="$1"
    [[ "${pid}" =~ ^[0-9]+$ && -r "/proc/${pid}/cmdline" ]] || return 1
    local -a args=()
    mapfile -d '' -t args <"/proc/${pid}/cmdline"
    [[ "${args[0]:-}" == */php
        && "${args[1]:-}" == '-S'
        && "${args[2]:-}" == *":${WEB_PORT}"
        && "${args[3]:-}" == '-t' ]] || return 1
    [[ "$(readlink -f -- "${args[4]:-}")" == "$(readlink -f -- "${DOCUMENT_ROOT}")" ]] || return 1
    local configured_path
    configured_path="$(tr '\0' '\n' <"/proc/${pid}/environ" | sed -n 's/^OSM_APP_CONFIG=//p' | head -n 1)"
    [[ -n "${configured_path}" \
        && "$(readlink -f -- "${configured_path}")" == "$(readlink -f -- "${APP_CONFIG}")" ]]
}

web_pid() {
    local pid
    if [[ -f "${WEB_PID_FILE}" ]]; then
        pid="$(<"${WEB_PID_FILE}")"
        if is_managed_web_pid "${pid}"; then
            printf '%s\n' "${pid}"
            return 0
        fi
    fi
    # Recover a server started by this script when its PID file was lost.
    command -v ss >/dev/null 2>&1 || return 1
    while read -r pid; do
        if is_managed_web_pid "${pid}"; then
            printf '%s\n' "${pid}"
            return 0
        fi
    done < <(ss -ltnp "sport = :${WEB_PORT}" 2>/dev/null \
        | grep -oE 'pid=[0-9]+' | cut -d= -f2 | sort -u)
    return 1
}

web_is_running() {
    web_pid >/dev/null
}

start_web() {
    local pid
    if pid="$(web_pid)"; then
        mkdir -p "${RUNTIME_DIR}"
        printf '%s\n' "${pid}" >"${WEB_PID_FILE}"
        info "Webサーバーは起動済みです（PID ${pid}）。"
        return
    fi
    mkdir -p "${RUNTIME_DIR}"
    rm -f "${WEB_PID_FILE}"

    if command -v ss >/dev/null 2>&1 \
        && ss -ltn "sport = :${WEB_PORT}" 2>/dev/null | tail -n +2 | grep -q .; then
        fail "${WEB_HOST}:${WEB_PORT} は既に使用されています。WEB_PORTを変更してください。"
    fi

    info "作業ディレクトリを直接参照するPHP Webサーバーを起動します。"
    OSM_APP_CONFIG="${APP_CONFIG}" OSM_TEST_DB_PORT="${MYSQL_PORT}" \
        start-stop-daemon \
        --start \
        --background \
        --make-pidfile \
        --pidfile "${WEB_PID_FILE}" \
        --chdir "${PROJECT_ROOT}" \
        --startas "$(command -v php)" \
        -- \
        -S "${WEB_HOST}:${WEB_PORT}" \
        -t "${DOCUMENT_ROOT}" >"${WEB_LOG_FILE}" 2>&1
}

wait_for_web() {
    local attempt healthcheck_host
    healthcheck_host="${WEB_HOST}"
    if [[ "${healthcheck_host}" == "0.0.0.0" || "${healthcheck_host}" == "::" ]]; then
        healthcheck_host="127.0.0.1"
    fi
    for attempt in {1..60}; do
        if web_is_running && curl --fail --silent \
            "http://${healthcheck_host}:${WEB_PORT}/api.php?mode=japan&days=1" >/dev/null; then
            return
        fi
        # The daemon can exist briefly before its command line changes to PHP.
        # Allow a short startup grace period before treating the PID as dead.
        if ((attempt >= 5)) && ! web_is_running; then
            tail -n 30 "${WEB_LOG_FILE}" >&2 || true
            fail "Webサーバーの起動に失敗しました。"
        fi
        sleep 1
    done
    tail -n 30 "${WEB_LOG_FILE}" >&2 || true
    fail "Web APIの疎通確認に失敗しました。"
}

stop_web() {
    local pid attempt
    if ! pid="$(web_pid)"; then
        rm -f "${WEB_PID_FILE}"
        info "Webサーバーは起動していません。"
        return
    fi
    kill "${pid}"
    for attempt in {1..20}; do
        kill -0 "${pid}" 2>/dev/null || break
        sleep 0.1
    done
    if kill -0 "${pid}" 2>/dev/null; then
        kill -KILL "${pid}"
    fi
    rm -f "${WEB_PID_FILE}"
    info "Webサーバーを停止しました。"
}

start_environment() {
    require_environment
    validate_settings
    if ! web_is_running && command -v ss >/dev/null 2>&1 \
        && ss -ltn "sport = :${WEB_PORT}" 2>/dev/null | tail -n +2 | grep -q .; then
        fail "${WEB_HOST}:${WEB_PORT} は既に使用されています。WEB_PORTを変更してください。"
    fi
    compose up --detach --build --wait database phpmyadmin
    prepare_test_database
    refresh_test_profiles
    start_web
    wait_for_web

    info "APIを起動しました: http://127.0.0.1:${WEB_PORT}/api.php"
    if [[ "${WEB_HOST}" == "0.0.0.0" || "${WEB_HOST}" == "::" ]]; then
        info "他の機器からは http://このPCのIPアドレス:${WEB_PORT}/api.php へ接続してください。"
    fi
    info "Document Root: ${DOCUMENT_ROOT}"
    info "phpMyAdmin: http://${PHPMYADMIN_HOST}:${PHPMYADMIN_PORT}/"
    info "DB: MariaDBコンテナ（127.0.0.1:${MYSQL_PORT}/osm_whatnew）"
    info "プロフィール用テストデータは初回だけ自動投入します。"
    info "Webログ: ${WEB_LOG_FILE}"
}

stop_environment() {
    require_environment
    stop_web
    compose down --remove-orphans
    info "テスト環境を停止しました。DBデータは保持されています。"
}

show_status() {
    require_environment
    local pid
    if pid="$(web_pid)"; then
        info "Web: running (PID ${pid}, bind ${WEB_HOST}:${WEB_PORT})"
    else
        info "Web: stopped"
    fi
    compose ps
    info "Webログ: ${WEB_LOG_FILE}"
}

show_logs() {
    require_environment
    if [[ -f "${WEB_LOG_FILE}" ]]; then
        info "Webログ:"
        tail -n 100 "${WEB_LOG_FILE}"
    fi
    compose logs --tail=100 database phpmyadmin
}

import_sql() {
    require_environment
    local sql_file="${2:-}"
    [[ -n "${sql_file}" ]] || fail "インポートするSQLファイルを指定してください。"
    [[ -r "${sql_file}" && -f "${sql_file}" ]] \
        || fail "SQLファイルを読み込めません: ${sql_file}"

    info "SQLをインポートします: ${sql_file}"
    compose exec -T database \
        mariadb --user=osm --password=osm-local-test osm_whatnew <"${sql_file}"
    info "SQLのインポートが完了しました。"
}

usage() {
    cat <<'EOF'
Usage: scripts/test-env-docker.sh [start|stop|restart|status|logs]
       scripts/test-env-docker.sh import /path/to/dump.sql

Environment variables:
  WEB_HOST, WEB_PORT             PHP Webサーバー（既定: 0.0.0.0:8000）
  MYSQL_PORT                     MariaDB公開ポート（既定: 3307）
  PHPMYADMIN_HOST, PHPMYADMIN_PORT（既定: 127.0.0.1:8081）
  TEST_RUNTIME_DIR               Web PID・ログの保存先（既定: /tmp）

WebはホストPHPで作業ツリーを直接参照します。
MariaDBとphpMyAdminはDockerで起動し、DBデータはvolumeに保持されます。
EOF
}

command_name="${1:-start}"
case "${command_name}" in
    start)
        start_environment
        ;;
    stop)
        stop_environment
        ;;
    restart)
        stop_environment
        start_environment
        ;;
    status)
        show_status
        ;;
    logs)
        show_logs
        ;;
    import)
        import_sql "$@"
        ;;
    -h|--help|help)
        usage
        ;;
    *)
        usage >&2
        exit 2
        ;;
esac
