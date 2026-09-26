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
];
foreach ($cases as [$point, $expected]) {
    $actual = $locator->locate(...$point);
    $selected = [$actual[0], $actual[1], $actual[3], $actual[4]];
    if ($selected !== $expected) {
        fwrite(STDERR, json_encode([$point, $expected, $selected], JSON_UNESCAPED_UNICODE) . "\n");
        exit(1);
    }
}
echo "Municipality location cases passed.\n";
