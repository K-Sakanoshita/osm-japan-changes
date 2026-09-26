<?php
declare(strict_types=1);
require __DIR__ . '/../public/municipality-lib.php';
require __DIR__ . '/../public/prefecture-lib.php';
$prefectures = new PrefectureLocator(__DIR__ . '/../public/data/prefectures.min.geojson');
foreach ([
    [34.8788072, 135.6097032, '大阪府'],
    [35.7, 139.7, '東京都'],
    [43.07, 141.35, '北海道'],
    [37.56, 126.97, null],
] as [$lat, $lon, $expectedPrefecture]) {
    if ($prefectures->locate($lat, $lon) !== $expectedPrefecture) {
        fwrite(STDERR, "Prefecture location failed for {$lat},{$lon}\n");
        exit(1);
    }
}
$locator = new MunicipalityLocator(__DIR__ . '/../public/data/municipalities.min.geojson');
$cases = [
    [[34.8788072, 135.6097032, '大阪府'], ['272078', '高槻市', null, null]],
    [[35.916306, 139.6483956, '埼玉県'], ['111007', 'さいたま市', '111040', '見沼区']],
    [[34.7024958, 135.5053036, '大阪府'], ['271004', '大阪市', '271276', '北区']],
    [[24.2840165, 153.9785123, '東京都'], ['134210', '小笠原村', null, '南鳥島']],
    [[35.66498875, 139.9645925, '千葉県'], [null, null, null, null]],
    [[33.327763, 130.49378, '福岡県'], ['412031', '鳥栖市', null, null]],
    [[35.5818239, 139.4806981, '神奈川県'], ['132098', '町田市', null, null]],
    [[34.3094474, 133.8106991, '香川県'], ['373869', '宇多津町', null, null]],
];
foreach ($cases as [$point, $expected]) {
    $actual = $locator->locate(...$point);
    $selected = [$actual[0], $actual[1], $actual[3], $actual[4]];
    if ($selected !== $expected) {
        fwrite(STDERR, json_encode([$point, $expected, $selected], JSON_UNESCAPED_UNICODE) . "\n");
        exit(1);
    }
}
foreach ([
    [[33.327763, 130.49378, '福岡県'], '佐賀県'],
    [[35.5818239, 139.4806981, '神奈川県'], '東京都'],
    [[34.8788072, 135.6097032, '大阪府'], '大阪府'],
    [[35.66498875, 139.9645925, '千葉県'], '千葉県'],
] as [$point, $expectedPrefecture]) {
    $actual = $locator->locateWithPrefecture(...$point)['prefecture'];
    if ($actual !== $expectedPrefecture) {
        throw new RuntimeException("Prefecture correction failed: {$actual} !== {$expectedPrefecture}");
    }
}
// A gap in the selected prefecture falls back to an exact hit anywhere.
$fixture = static function (string $name, string $code, string $prefecture, float $west, float $east): array {
    return [
        'type' => 'Feature',
        'bbox' => [$west, 33.0, $east, 33.1],
        'properties' => ['admin_level' => 7, 'name' => $name, 'local_government_code' => $code, 'prefecture_code' => substr($code, 0, 2), 'prefecture_name' => $prefecture],
        'geometry' => ['type' => 'Polygon', 'coordinates' => [[[$west, 33.0], [$east, 33.0], [$east, 33.1], [$west, 33.1], [$west, 33.0]]]],
    ];
};
$features = [
    $fixture('福岡側', '400001', '福岡県', 130.0, 130.1),
    $fixture('佐賀側', '410001', '佐賀県', 130.10002, 130.2),
];
$fixturePath = tempnam(sys_get_temp_dir(), 'municipality-test-');
if ($fixturePath === false) {
    throw new RuntimeException('Could not create municipality test fixture');
}
try {
    file_put_contents($fixturePath, json_encode(['type' => 'FeatureCollection', 'features' => $features], JSON_THROW_ON_ERROR));
    $fallback = new MunicipalityLocator($fixturePath);
    if ($fallback->locate(33.05, 130.05, '福岡県')[0] !== '400001'
        || $fallback->locate(33.05, 130.10005, '福岡県')[0] !== '410001'
        || $fallback->locateWithPrefecture(33.05, 130.10005, '福岡県')['prefecture'] !== '佐賀県'
        || $fallback->locate(33.05, 130.3, '福岡県')[0] !== null
        || $fallback->locate(33.05, 130.10005, '不明県')[0] !== '410001') {
        throw new RuntimeException('Nationwide municipality fallback failed');
    }
    $features[] = $fixture('別の佐賀側', '410002', '佐賀県', 130.10002, 130.2);
    file_put_contents($fixturePath, json_encode(['type' => 'FeatureCollection', 'features' => $features], JSON_THROW_ON_ERROR));
    if ((new MunicipalityLocator($fixturePath))->locate(33.05, 130.10005, '福岡県')[0] !== null) {
        throw new RuntimeException('Ambiguous municipality fallback must stay null');
    }
} finally {
    unlink($fixturePath);
}
echo "Municipality location cases passed.\n";
