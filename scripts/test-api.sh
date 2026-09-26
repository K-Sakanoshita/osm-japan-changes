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
    'tag_key finds Osaka playground nodes and way' \
    '$data["meta"]["mode"] === "pois" && $data["meta"]["filters"]["tag_key"] === "playground" && count($data["items"]) === 3' \
    'mode=pois&from=2020-01-01&to=2099-12-31&prefecture=%E5%A4%A7%E9%98%AA%E5%BA%9C&tag_key=playground'

assert_json \
    'tag_value finds exact value across types' \
    '$data["meta"]["filters"]["tag_value"] === "swing" && count($data["items"]) === 2 && json_decode($data["items"][0]["tags"], true)["playground"] === "swing"' \
    'mode=pois&from=2020-01-01&to=2099-12-31&prefecture=%E5%A4%A7%E9%98%AA%E5%BA%9C&tag_key=playground&tag_value=swing'

assert_json \
    'historic tags can be queried independently of representative categories' \
    'count($data["items"]) === 1 && json_decode($data["items"][0]["tags"], true)["historic"] === "memorial"' \
    'mode=pois&from=2020-01-01&to=2099-12-31&prefecture=%E4%BA%AC%E9%83%BD%E5%BA%9C&tag_key=historic'

status="$(curl --silent --output /dev/null --write-out '%{http_code}' \
    "${API_URL}?mode=pois&days=1&tag_value=swing")"
[[ "${status}" == '400' ]] || fail "tag_value without tag_key must return HTTP 400 (got ${status})"
printf '[api-test] OK: tag_value without tag_key returns HTTP 400\n'


assert_json \
    'municipality and ward codes filter playgrounds' \
    'count($data["items"]) === 3 && $data["items"][0]["municipalityCode"] === "271004" && $data["items"][0]["wardCode"] === "271276"' \
    'mode=pois&from=2020-01-01&to=2099-12-31&municipality_code=271004&ward_code=271276&tag_key=playground'

assert_json \
    'objects deduplicates IDs and preserves requested order' \
    'count($data["items"]) === 2 && $data["items"][0]["type"] === "way" && $data["items"][1]["type"] === "node" && isset($data["items"][0]["createdAt"])' \
    'mode=objects&ids=way/8080000000000004,node/8080000000000001,way/8080000000000004'

assert_json \
    'creation date remains available after a later modification' \
    'count($data["items"]) >= 1 && $data["items"][0]["action"] === "modify" && $data["items"][0]["createdAt"] < $data["items"][0]["date"]' \
    'mode=pois&new_days=365&tag_key=playground&action=modify'

for query in 'mode=objects&ids=bad/1' 'mode=objects&ids=node/-1' 'mode=pois&municipality_code=12345' 'mode=pois&new_days=0'; do
    status="$(curl --silent --output /dev/null --write-out '%{http_code}' "${API_URL}?${query}")"
    [[ "${status}" == '400' ]] || fail "${query} must return HTTP 400 (got ${status})"
done
printf '[api-test] OK: new filters reject malformed input\n'

cors_headers="$(curl --silent --show-error --dump-header - --output /dev/null \
    --request OPTIONS --header 'Origin: https://example.github.io' "${API_URL}")"
grep -qi '^HTTP/.* 204' <<<"${cors_headers}" \
    || fail 'CORS preflight must return HTTP 204'
grep -qi '^Access-Control-Allow-Origin: \*' <<<"${cors_headers}" \
    || fail 'CORS preflight must allow configured origins'
printf '[api-test] OK: CORS preflight allows the configured origin\n'
