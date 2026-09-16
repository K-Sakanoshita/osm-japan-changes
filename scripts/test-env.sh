#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
DOCUMENT_ROOT="${PROJECT_ROOT}/public"
DEFAULT_TEST_CONFIG="${PROJECT_ROOT}/private-osm-test-config.php"
DEFAULT_CONFIG="${PROJECT_ROOT}/private-osm-config.php"

WEB_HOST="${WEB_HOST:-127.0.0.1}"
WEB_PORT="${WEB_PORT:-8000}"
PHPMYADMIN_HOST="${PHPMYADMIN_HOST:-127.0.0.1}"
PHPMYADMIN_PORT="${PHPMYADMIN_PORT:-8081}"
PHPMYADMIN_ROOT="${PHPMYADMIN_ROOT:-/usr/share/phpmyadmin}"
MYSQL_SERVICE="${MYSQL_SERVICE:-mysql}"
TEST_RUNTIME_DIR="${TEST_RUNTIME_DIR:-/tmp}"

if [[ -n "${OSM_APP_CONFIG:-}" ]]; then
    CONFIG_FILE="${OSM_APP_CONFIG}"
elif [[ -f "${DEFAULT_TEST_CONFIG}" ]]; then
    CONFIG_FILE="${DEFAULT_TEST_CONFIG}"
else
    CONFIG_FILE="${DEFAULT_CONFIG}"
fi

RUNTIME_DIR="${TEST_RUNTIME_DIR}/osm-japan-changes-${UID}"
WEB_PID_FILE="${RUNTIME_DIR}/web.pid"
WEB_LOG_FILE="${RUNTIME_DIR}/web.log"
PHPMYADMIN_PID_FILE="${RUNTIME_DIR}/phpmyadmin.pid"
PHPMYADMIN_LOG_FILE="${RUNTIME_DIR}/phpmyadmin.log"
PHPMYADMIN_TEST_ROOT="${RUNTIME_DIR}/phpmyadmin-root"
PHPMYADMIN_TEST_CONFIG="${RUNTIME_DIR}/phpmyadmin-config.inc.php"
PHPMYADMIN_SECRET_FILE="${RUNTIME_DIR}/phpmyadmin-secret"
PHPMYADMIN_TEMP_DIR="${RUNTIME_DIR}/phpmyadmin-tmp"
PHPMYADMIN_SERVE_ROOT="${PHPMYADMIN_ROOT}"

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

validate_settings() {
    [[ "${WEB_PORT}" =~ ^[0-9]+$ ]] || fail "WEB_PORT は数値で指定してください。"
    ((WEB_PORT >= 1 && WEB_PORT <= 65535)) || fail "WEB_PORT は1～65535で指定してください。"
    [[ "${WEB_HOST}" =~ ^[A-Za-z0-9.-]+$ ]] || fail "WEB_HOST の形式が不正です。"
    [[ "${PHPMYADMIN_PORT}" =~ ^[0-9]+$ ]] || fail "PHPMYADMIN_PORT は数値で指定してください。"
    ((PHPMYADMIN_PORT >= 1 && PHPMYADMIN_PORT <= 65535)) || fail "PHPMYADMIN_PORT は1～65535で指定してください。"
    [[ "${PHPMYADMIN_HOST}" =~ ^[A-Za-z0-9.-]+$ ]] || fail "PHPMYADMIN_HOST の形式が不正です。"
    [[ "${WEB_HOST}:${WEB_PORT}" != "${PHPMYADMIN_HOST}:${PHPMYADMIN_PORT}" ]] \
        || fail "WebアプリとphpMyAdminに同じアドレス・ポートは指定できません。"
    [[ -d "${PHPMYADMIN_ROOT}" ]] || fail "phpMyAdminがありません: ${PHPMYADMIN_ROOT}"
    [[ "${MYSQL_SERVICE}" =~ ^[A-Za-z0-9_.@-]+$ ]] || fail "MYSQL_SERVICE の形式が不正です。"
    [[ "${TEST_RUNTIME_DIR}" = /* ]] || fail "TEST_RUNTIME_DIR は絶対パスで指定してください。"
    [[ "${TEST_RUNTIME_DIR}" =~ ^[A-Za-z0-9_./-]+$ ]] || fail "TEST_RUNTIME_DIR の形式が不正です。"
    [[ "${CONFIG_FILE}" = /* ]] || fail "OSM_APP_CONFIG は絶対パスで指定してください。"
    [[ -f "${CONFIG_FILE}" ]] || fail "DB接続設定がありません: ${CONFIG_FILE}"
}

web_is_running() {
    [[ -f "${WEB_PID_FILE}" ]] || return 1
    local pid command_line
    pid="$(<"${WEB_PID_FILE}")"
    [[ "${pid}" =~ ^[0-9]+$ ]] || return 1
    kill -0 "${pid}" 2>/dev/null || return 1
    [[ -r "/proc/${pid}/cmdline" ]] || return 1
    command_line="$(tr '\0' ' ' <"/proc/${pid}/cmdline")"
    [[ "${command_line}" == *"php"* && "${command_line}" == *"${DOCUMENT_ROOT}"* ]]
}

phpmyadmin_is_running() {
    [[ -f "${PHPMYADMIN_PID_FILE}" ]] || return 1
    local pid command_line
    pid="$(<"${PHPMYADMIN_PID_FILE}")"
    [[ "${pid}" =~ ^[0-9]+$ ]] || return 1
    kill -0 "${pid}" 2>/dev/null || return 1
    [[ -r "/proc/${pid}/cmdline" ]] || return 1
    command_line="$(tr '\0' ' ' <"/proc/${pid}/cmdline")"
    [[ "${command_line}" == *"php"* ]] \
        && [[ "${command_line}" == *"${PHPMYADMIN_ROOT}"* \
            || "${command_line}" == *"${PHPMYADMIN_TEST_ROOT}"* ]]
}

database_is_ready() {
    php -r '
        try {
            $config = require $argv[1];
            $db = $config["db"];
            $pdo = new PDO(
                "mysql:host={$db["host"]};dbname={$db["dbname"]};charset={$db["charset"]}",
                $db["user"],
                $db["pass"],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->query("SELECT 1");
        } catch (Throwable $error) {
            exit(1);
        }
    ' "${CONFIG_FILE}" >/dev/null 2>&1
}

database_uses_empty_password() {
    php -r '
        $config = require $argv[1];
        exit((string) $config["db"]["pass"] === "" ? 0 : 1);
    ' "${CONFIG_FILE}" >/dev/null 2>&1
}

prepare_phpmyadmin() {
    local source_file destination_file encoded_config allow_no_password

    PHPMYADMIN_SERVE_ROOT="${PHPMYADMIN_ROOT}"
    allow_no_password=false
    if database_uses_empty_password; then
        [[ "${PHPMYADMIN_HOST}" == "127.0.0.1" || "${PHPMYADMIN_HOST}" == "localhost" ]] \
            || fail "空パスワードのphpMyAdminはローカルホスト以外へ公開できません。"
        allow_no_password=true
    fi

    if [[ ! -f "${PHPMYADMIN_TEST_ROOT}/.osm-test-overlay" ]]; then
        mkdir -p "${RUNTIME_DIR}"
        cp -as "${PHPMYADMIN_ROOT}/." "${PHPMYADMIN_TEST_ROOT}"
        rm -f "${PHPMYADMIN_TEST_ROOT}/libraries/vendor_config.php"
        cp "${PHPMYADMIN_ROOT}/libraries/vendor_config.php" \
            "${PHPMYADMIN_TEST_ROOT}/libraries/vendor_config.php"
        sed -i \
            "s#'configFile' => '/etc/phpmyadmin/config.inc.php'#'configFile' => '${PHPMYADMIN_TEST_CONFIG}'#" \
            "${PHPMYADMIN_TEST_ROOT}/libraries/vendor_config.php"
        touch "${PHPMYADMIN_TEST_ROOT}/.osm-test-overlay"
    fi
    sed -i \
        "s#'tempDir' => '/var/lib/phpmyadmin/tmp/'#'tempDir' => '${PHPMYADMIN_TEMP_DIR}/'#" \
        "${PHPMYADMIN_TEST_ROOT}/libraries/vendor_config.php"

    # Top-level entry points must be real files so __DIR__ resolves to the
    # test overlay instead of the system installation behind a symlink.
    for source_file in "${PHPMYADMIN_ROOT}"/*.php; do
        destination_file="${PHPMYADMIN_TEST_ROOT}/$(basename "${source_file}")"
        if [[ -L "${destination_file}" ]]; then
            rm -f "${destination_file}"
            cp "${source_file}" "${destination_file}"
        fi
    done

    mkdir -p "${PHPMYADMIN_TEMP_DIR}"
    if [[ ! -f "${PHPMYADMIN_SECRET_FILE}" ]]; then
        umask 077
        php -r 'echo bin2hex(random_bytes(32));' >"${PHPMYADMIN_SECRET_FILE}"
    fi
    encoded_config="$(php -r 'echo base64_encode($argv[1]);' "${CONFIG_FILE}")"

    {
        printf '%s\n' '<?php'
        printf "%s\n" "\$applicationConfig = require base64_decode('${encoded_config}');"
        printf '%s\n' '$databaseHost = (string) $applicationConfig["db"]["host"];'
        printf '%s\n' '$databaseHostParts = explode(";", $databaseHost);'
        printf '%s\n' '$cfg["blowfish_secret"] = trim(file_get_contents(__DIR__ . "/phpmyadmin-secret"));'
        printf '%s\n' '$cfg["TempDir"] = __DIR__ . "/phpmyadmin-tmp";'
        printf '%s\n' '$cfg["Servers"][1]["auth_type"] = "cookie";'
        printf '%s\n' '$cfg["Servers"][1]["host"] = array_shift($databaseHostParts);'
        printf '%s\n' '$cfg["Servers"][1]["connect_type"] = "tcp";'
        printf '%s\n' 'foreach ($databaseHostParts as $databaseHostPart) {'
        printf '%s\n' '    if (str_starts_with($databaseHostPart, "port=")) {'
        printf '%s\n' '        $cfg["Servers"][1]["port"] = substr($databaseHostPart, 5);'
        printf '%s\n' '    }'
        printf '%s\n' '}'
        printf '%s\n' "\$cfg[\"Servers\"][1][\"AllowNoPassword\"] = ${allow_no_password};"
    } >"${PHPMYADMIN_TEST_CONFIG}"
    PHPMYADMIN_SERVE_ROOT="${PHPMYADMIN_TEST_ROOT}"
    if [[ "${allow_no_password}" == true ]]; then
        info "ローカルテスト用phpMyAdminで空パスワードログインを許可します。"
    fi
}

start_mysql() {
    if database_is_ready; then
        info "既存のMySQLデータベースへ接続できました。"
        return
    fi

    info "MySQLサービスを起動します（sudo権限が必要な場合があります）。"
    if command -v systemctl >/dev/null 2>&1 \
        && [[ "$(ps -p 1 -o comm= 2>/dev/null || true)" == "systemd" ]]; then
        sudo systemctl start "${MYSQL_SERVICE}"
    elif command -v service >/dev/null 2>&1; then
        sudo service "${MYSQL_SERVICE}" start
    else
        fail "MySQLサービスを起動できません。MySQLを手動で起動してください。"
    fi

    local attempt
    for attempt in {1..20}; do
        database_is_ready && {
            info "既存のMySQLデータベースへ接続できました。"
            return
        }
        sleep 0.5
    done
    fail "既存DBへ接続できません。${CONFIG_FILE} とMySQLサービスを確認してください。"
}

start_web() {
    if web_is_running; then
        info "Webサーバーは起動済みです（PID $(<"${WEB_PID_FILE}")）。"
        return
    fi
    rm -f "${WEB_PID_FILE}"
    mkdir -p "${RUNTIME_DIR}"

    if command -v ss >/dev/null 2>&1 \
        && ss -ltn "sport = :${WEB_PORT}" 2>/dev/null | tail -n +2 | grep -q .; then
        fail "${WEB_HOST}:${WEB_PORT} は既に使用されています。WEB_PORTを変更してください。"
    fi

    info "PHP Webサーバーを起動します。"
    OSM_APP_CONFIG="${CONFIG_FILE}" nohup php \
        -S "${WEB_HOST}:${WEB_PORT}" \
        -t "${DOCUMENT_ROOT}" >"${WEB_LOG_FILE}" 2>&1 &
    printf '%s\n' "$!" >"${WEB_PID_FILE}"

    local attempt
    for attempt in {1..20}; do
        if ! web_is_running; then
            tail -n 20 "${WEB_LOG_FILE}" >&2 || true
            fail "Webサーバーの起動に失敗しました。"
        fi
        if curl --fail --silent \
            "http://${WEB_HOST}:${WEB_PORT}/api.php?mode=japan&days=1" >/dev/null; then
            return
        fi
        sleep 0.25
    done

    tail -n 20 "${WEB_LOG_FILE}" >&2 || true
    stop_web
    fail "APIの疎通確認に失敗しました。"
}

start_phpmyadmin() {
    if phpmyadmin_is_running; then
        info "phpMyAdminは起動済みです（PID $(<"${PHPMYADMIN_PID_FILE}")）。"
        return
    fi
    rm -f "${PHPMYADMIN_PID_FILE}"
    mkdir -p "${RUNTIME_DIR}"
    prepare_phpmyadmin

    if command -v ss >/dev/null 2>&1 \
        && ss -ltn "sport = :${PHPMYADMIN_PORT}" 2>/dev/null | tail -n +2 | grep -q .; then
        fail "${PHPMYADMIN_HOST}:${PHPMYADMIN_PORT} は既に使用されています。PHPMYADMIN_PORTを変更してください。"
    fi

    info "phpMyAdminを起動します。"
    nohup php \
        -S "${PHPMYADMIN_HOST}:${PHPMYADMIN_PORT}" \
        -t "${PHPMYADMIN_SERVE_ROOT}" >"${PHPMYADMIN_LOG_FILE}" 2>&1 &
    printf '%s\n' "$!" >"${PHPMYADMIN_PID_FILE}"

    local attempt
    for attempt in {1..20}; do
        if ! phpmyadmin_is_running; then
            tail -n 20 "${PHPMYADMIN_LOG_FILE}" >&2 || true
            fail "phpMyAdminの起動に失敗しました。"
        fi
        if curl --fail --silent \
            "http://${PHPMYADMIN_HOST}:${PHPMYADMIN_PORT}/" >/dev/null; then
            return
        fi
        sleep 0.25
    done

    tail -n 20 "${PHPMYADMIN_LOG_FILE}" >&2 || true
    stop_phpmyadmin
    fail "phpMyAdminの疎通確認に失敗しました。"
}

stop_web() {
    if ! web_is_running; then
        rm -f "${WEB_PID_FILE}"
        info "Webサーバーは起動していません。"
        return
    fi

    local pid attempt
    pid="$(<"${WEB_PID_FILE}")"
    kill "${pid}"
    for attempt in {1..20}; do
        kill -0 "${pid}" 2>/dev/null || break
        sleep 0.1
    done
    if kill -0 "${pid}" 2>/dev/null; then
        kill -KILL "${pid}"
    fi
    rm -f "${WEB_PID_FILE}"
    info "Webサーバーを停止しました。MySQLサービスは停止していません。"
}

stop_phpmyadmin() {
    if ! phpmyadmin_is_running; then
        rm -f "${PHPMYADMIN_PID_FILE}"
        info "phpMyAdminは起動していません。"
        return
    fi

    local pid attempt
    pid="$(<"${PHPMYADMIN_PID_FILE}")"
    kill "${pid}"
    for attempt in {1..20}; do
        kill -0 "${pid}" 2>/dev/null || break
        sleep 0.1
    done
    if kill -0 "${pid}" 2>/dev/null; then
        kill -KILL "${pid}"
    fi
    rm -f "${PHPMYADMIN_PID_FILE}"
    info "phpMyAdminを停止しました。"
}

show_status() {
    if database_is_ready; then
        info "Database: connected (${CONFIG_FILE})"
    else
        info "Database: unreachable (${CONFIG_FILE})"
    fi

    if web_is_running; then
        info "Web: running (PID $(<"${WEB_PID_FILE}"), http://${WEB_HOST}:${WEB_PORT}/api.php)"
    else
        info "Web: stopped"
    fi
    if phpmyadmin_is_running; then
        info "phpMyAdmin: running (PID $(<"${PHPMYADMIN_PID_FILE}"), http://${PHPMYADMIN_HOST}:${PHPMYADMIN_PORT}/)"
    else
        info "phpMyAdmin: stopped"
    fi
    info "Webログ: ${WEB_LOG_FILE}"
    info "phpMyAdminログ: ${PHPMYADMIN_LOG_FILE}"
}

start_environment() {
    require_command php
    require_command curl
    validate_settings

    trap '
        status=$?
        if ((status != 0)); then
            stop_web || true
            stop_phpmyadmin || true
        fi
        exit "${status}"
    ' EXIT
    start_mysql
    start_web
    start_phpmyadmin
    trap - EXIT

    info "APIを起動しました: http://${WEB_HOST}:${WEB_PORT}/api.php"
    info "phpMyAdmin: http://${PHPMYADMIN_HOST}:${PHPMYADMIN_PORT}/"
    info "DB設定: ${CONFIG_FILE}"
    info "Webログ: ${WEB_LOG_FILE}"
}

usage() {
    cat <<'EOF'
Usage: scripts/test-env.sh [start|stop|restart|status]

Environment variables:
  OSM_APP_CONFIG   既存DBの接続設定（絶対パス）
  WEB_HOST, WEB_PORT
  PHPMYADMIN_HOST, PHPMYADMIN_PORT, PHPMYADMIN_ROOT
  MYSQL_SERVICE    MySQLのサービス名（既定: mysql）
  TEST_RUNTIME_DIR PID・ログの保存先（既定: /tmp）
EOF
}

command_name="${1:-start}"
case "${command_name}" in
    start)
        start_environment
        ;;
    stop)
        stop_web
        stop_phpmyadmin
        ;;
    restart)
        stop_web
        stop_phpmyadmin
        start_environment
        ;;
    status)
        require_command php
        validate_settings
        show_status
        ;;
    -h|--help|help)
        usage
        ;;
    *)
        usage >&2
        exit 2
        ;;
esac
