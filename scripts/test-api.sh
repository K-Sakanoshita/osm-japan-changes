#!/usr/bin/env bash
set -euo pipefail

API_URL="${API_URL:-http://127.0.0.1:8000/api.php}"

fail() {
    printf '[api-test] ERROR: %s\n' "$*" >&2
    exit 1
}

assert_json() {
    local description="$1" expression="$2" response
    response="$(curl --fail --silent --show-error "${API_URL}?${3}")"
    RESPONSE_JSON="${response}" php -r '
        $data = json_decode((string) getenv("RESPONSE_JSON"), true, 512, JSON_THROW_ON_ERROR);
        $assertion = $argv[1];
        if (!eval("return " . $assertion . ";")) {
            fwrite(STDERR, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
            exit(1);
        }
    ' "${expression}" || fail "${description}"
    printf '[api-test] OK: %s\n' "${description}"
}

assert_json \
    'tag_key finds both Osaka playground nodes' \
    '$data["meta"]["mode"] === "pois" && $data["meta"]["filters"]["tag_key"] === "playground" && count($data["items"]) === 2' \
    'mode=pois&days=1&prefecture=%E5%A4%A7%E9%98%AA%E5%BA%9C&tag_key=playground'

assert_json \
    'tag_value limits the result to the exact value' \
    '$data["meta"]["filters"]["tag_value"] === "swing" && count($data["items"]) === 1 && json_decode($data["items"][0]["tags"], true)["playground"] === "swing"' \
    'mode=pois&days=1&prefecture=%E5%A4%A7%E9%98%AA%E5%BA%9C&tag_key=playground&tag_value=swing'

assert_json \
    'historic tags can be queried independently of representative categories' \
    'count($data["items"]) === 1 && json_decode($data["items"][0]["tags"], true)["historic"] === "memorial"' \
    'mode=pois&days=1&prefecture=%E4%BA%AC%E9%83%BD%E5%BA%9C&tag_key=historic'

status="$(curl --silent --output /dev/null --write-out '%{http_code}' \
    "${API_URL}?mode=pois&days=1&tag_value=swing")"
[[ "${status}" == '400' ]] || fail "tag_value without tag_key must return HTTP 400 (got ${status})"
printf '[api-test] OK: tag_value without tag_key returns HTTP 400\n'

cors_headers="$(curl --silent --show-error --dump-header - --output /dev/null \
    --request OPTIONS --header 'Origin: https://example.github.io' "${API_URL}")"
grep -qi '^HTTP/.* 204' <<<"${cors_headers}" \
    || fail 'CORS preflight must return HTTP 204'
grep -qi '^Access-Control-Allow-Origin: \*' <<<"${cors_headers}" \
    || fail 'CORS preflight must allow configured origins'
printf '[api-test] OK: CORS preflight allows the configured origin\n'
