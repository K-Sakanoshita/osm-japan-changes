<?php
declare(strict_types=1);

function profilePrefectureBadgeNames(): array
{
    return [
        'hokkaido' => '北海道', 'aomori' => '青森県', 'iwate' => '岩手県', 'miyagi' => '宮城県',
        'akita' => '秋田県', 'yamagata' => '山形県', 'fukushima' => '福島県', 'ibaraki' => '茨城県',
        'tochigi' => '栃木県', 'gunma' => '群馬県', 'saitama' => '埼玉県', 'chiba' => '千葉県',
        'tokyo' => '東京都', 'kanagawa' => '神奈川県', 'niigata' => '新潟県', 'toyama' => '富山県',
        'ishikawa' => '石川県', 'fukui' => '福井県', 'yamanashi' => '山梨県', 'nagano' => '長野県',
        'gifu' => '岐阜県', 'shizuoka' => '静岡県', 'aichi' => '愛知県', 'mie' => '三重県',
        'shiga' => '滋賀県', 'kyoto' => '京都府', 'osaka' => '大阪府', 'hyogo' => '兵庫県',
        'nara' => '奈良県', 'wakayama' => '和歌山県', 'tottori' => '鳥取県', 'shimane' => '島根県',
        'okayama' => '岡山県', 'hiroshima' => '広島県', 'yamaguchi' => '山口県', 'tokushima' => '徳島県',
        'kagawa' => '香川県', 'ehime' => '愛媛県', 'kochi' => '高知県', 'fukuoka' => '福岡県',
        'saga' => '佐賀県', 'nagasaki' => '長崎県', 'kumamoto' => '熊本県', 'oita' => '大分県',
        'miyazaki' => '宮崎県', 'kagoshima' => '鹿児島県', 'okinawa' => '沖縄県',
    ];
}

function profilePrefectureBadgeIcons(): array
{
    return [
        'hokkaido' => '🦀', 'aomori' => '🍎', 'iwate' => '🍜', 'miyagi' => '🐮',
        'akita' => '👹', 'yamagata' => '🍒', 'fukushima' => '🍑', 'ibaraki' => '🫘',
        'tochigi' => '🍓', 'gunma' => '♨️', 'saitama' => '🍘', 'chiba' => '🥜',
        'tokyo' => '🗼', 'kanagawa' => '⚓', 'niigata' => '🍚', 'toyama' => '🦑',
        'ishikawa' => '✨', 'fukui' => '🦕', 'yamanashi' => '🍇', 'nagano' => '⛰️',
        'gifu' => '🏘️', 'shizuoka' => '🍵', 'aichi' => '🏯', 'mie' => '🦐',
        'shiga' => '🏞️', 'kyoto' => '👘', 'osaka' => '🐙', 'hyogo' => '🥩',
        'nara' => '🦌', 'wakayama' => '🥾', 'tottori' => '🏜️', 'shimane' => '⛩️',
        'okayama' => '🍡', 'hiroshima' => '🦪', 'yamaguchi' => '🐡', 'tokushima' => '💃',
        'kagawa' => '🍜', 'ehime' => '🍊', 'kochi' => '🐟', 'fukuoka' => '🍜',
        'saga' => '🏺', 'nagasaki' => '⛪', 'kumamoto' => '🐻', 'oita' => '♨️',
        'miyazaki' => '🥭', 'kagoshima' => '🌋', 'okinawa' => '🌺',
    ];
}

function profileBadgeDefinitions(): array
{
    $definitions = [
        'first_step' => ['name' => 'はじめの一歩', 'description' => '1件マッピング', 'icon' => '🌱', 'metric' => 'total_count', 'threshold' => 1],
        'rookie_mapper' => ['name' => 'はじめの十歩', 'description' => '10件マッピング', 'icon' => '🗺️', 'metric' => 'total_count', 'threshold' => 10],
        'independent_mapper' => ['name' => 'もう一人前？', 'description' => '25件マッピング', 'icon' => '🔰', 'metric' => 'total_count', 'threshold' => 25],
        'town_observer' => ['name' => '町の観察者', 'description' => '50件マッピング', 'icon' => '👀', 'metric' => 'total_count', 'threshold' => 50],
        'local_observer' => ['name' => '地域の観察者Ⅰ', 'description' => '100件マッピング', 'icon' => '🔭', 'metric' => 'total_count', 'threshold' => 100],
        'local_observer_2' => ['name' => '地域の観察者Ⅱ', 'description' => '150件マッピング', 'icon' => '🔭', 'metric' => 'total_count', 'threshold' => 150],
        'local_walker' => ['name' => '地域の散策者Ⅰ', 'description' => '250件マッピング', 'icon' => '🚶', 'metric' => 'total_count', 'threshold' => 250],
        'local_walker_2' => ['name' => '地域の散策者Ⅱ', 'description' => '350件マッピング', 'icon' => '🚶', 'metric' => 'total_count', 'threshold' => 350],
        'local_explorer' => ['name' => '地域の探検者Ⅰ', 'description' => '500件マッピング', 'icon' => '🧭', 'metric' => 'total_count', 'threshold' => 500],
        'local_explorer_2' => ['name' => '地域の探検者Ⅱ', 'description' => '750件マッピング', 'icon' => '🧭', 'metric' => 'total_count', 'threshold' => 750],
        'local_geographer' => ['name' => '地域の地理学者Ⅰ', 'description' => '1,000件マッピング', 'icon' => '🌐', 'metric' => 'total_count', 'threshold' => 1000],
        'local_geographer_2' => ['name' => '地域の地理学者Ⅱ', 'description' => '2,000件マッピング', 'icon' => '🌐', 'metric' => 'total_count', 'threshold' => 2000],
        'local_geographer_3' => ['name' => '地域の地理学者Ⅲ', 'description' => '4,000件マッピング', 'icon' => '🌐', 'metric' => 'total_count', 'threshold' => 4000],
        'local_geographer_4' => ['name' => '日本の地理学者Ⅰ', 'description' => '6,000件マッピング', 'icon' => '🗾', 'metric' => 'total_count', 'threshold' => 6000],
        'local_geographer_5' => ['name' => '日本の地理学者Ⅱ', 'description' => '8,000件マッピング', 'icon' => '🗾', 'metric' => 'total_count', 'threshold' => 8000],
        'japan_geographer' => ['name' => '日本の地理学者Ⅲ', 'description' => '10,000件マッピング', 'icon' => '🗾', 'metric' => 'total_count', 'threshold' => 10000],
        'treasure_hunter' => ['name' => 'トレジャーハンター', 'description' => '20,000件マッピング', 'icon' => '💎', 'metric' => 'total_count', 'threshold' => 20000, 'badge_level' => 4],
        'new_discovery' => ['name' => '新しい発見', 'description' => '新規地物を1件マッピング', 'icon' => '✨', 'metric' => 'create_count', 'threshold' => 1],
        'town_discoverer' => ['name' => 'まちの発見者', 'description' => '新規地物を10件マッピング', 'icon' => '🔎', 'metric' => 'create_count', 'threshold' => 10],
        'regional_discoverer' => ['name' => '地域の発見者Ⅰ', 'description' => '新規地物を50件マッピング', 'icon' => '🔍', 'metric' => 'create_count', 'threshold' => 50],
        'regional_discoverer_2' => ['name' => '地域の発見者Ⅱ', 'description' => '新規地物を100件マッピング', 'icon' => '🔍', 'metric' => 'create_count', 'threshold' => 100],
        'regional_recorder' => ['name' => '地域の記録者Ⅰ', 'description' => '新規地物を250件マッピング', 'icon' => '📝', 'metric' => 'create_count', 'threshold' => 250],
        'regional_recorder_2' => ['name' => '地域の記録者Ⅱ', 'description' => '新規地物を500件マッピング', 'icon' => '📝', 'metric' => 'create_count', 'threshold' => 500],
        'regional_pioneer' => ['name' => '地域の開拓者Ⅰ', 'description' => '新規地物を1,000件マッピング', 'icon' => '🚩', 'metric' => 'create_count', 'threshold' => 1000],
        'regional_pioneer_2' => ['name' => '地域の開拓者Ⅱ', 'description' => '新規地物を2,000件マッピング', 'icon' => '🚩', 'metric' => 'create_count', 'threshold' => 2000],
        'regional_pioneer_3' => ['name' => '地域の開拓者Ⅲ', 'description' => '新規地物を4,000件マッピング', 'icon' => '🚩', 'metric' => 'create_count', 'threshold' => 4000],
        'japan_pioneer' => ['name' => '日本の開拓者Ⅰ', 'description' => '新規地物を6,000件マッピング', 'icon' => '🗾', 'metric' => 'create_count', 'threshold' => 6000],
        'japan_pioneer_2' => ['name' => '日本の開拓者Ⅱ', 'description' => '新規地物を8,000件マッピング', 'icon' => '🗾', 'metric' => 'create_count', 'threshold' => 8000],
        'japan_pioneer_3' => ['name' => '日本の開拓者Ⅲ', 'description' => '新規地物を10,000件マッピング', 'icon' => '🗾', 'metric' => 'create_count', 'threshold' => 10000],
        'discovery_master' => ['name' => '発見の巨匠', 'description' => '新規地物を15,000件マッピング', 'icon' => '💫', 'metric' => 'create_count', 'threshold' => 15000, 'badge_level' => 4],
        'legendary_discoverer' => ['name' => '伝説の発見者', 'description' => '新規地物を20,000件マッピング', 'icon' => '🌟', 'metric' => 'create_count', 'threshold' => 20000, 'badge_level' => 5],
        'information_care' => ['name' => '情報のお手入れ', 'description' => '既存地物を1件更新', 'icon' => '🧹', 'metric' => 'modify_count', 'threshold' => 1],
        'town_maintainer' => ['name' => 'まちのメンテナー', 'description' => '既存地物を10件更新', 'icon' => '🛠️', 'metric' => 'modify_count', 'threshold' => 10],
        'regional_maintainer' => ['name' => '地域のメンテナーⅠ', 'description' => '既存地物を50件更新', 'icon' => '🔧', 'metric' => 'modify_count', 'threshold' => 50],
        'regional_maintainer_2' => ['name' => '地域のメンテナーⅡ', 'description' => '既存地物を100件更新', 'icon' => '🔧', 'metric' => 'modify_count', 'threshold' => 100],
        'regional_mechanic' => ['name' => '地域の整備士Ⅰ', 'description' => '既存地物を250件更新', 'icon' => '⚙️', 'metric' => 'modify_count', 'threshold' => 250],
        'regional_mechanic_2' => ['name' => '地域の整備士Ⅱ', 'description' => '既存地物を500件更新', 'icon' => '⚙️', 'metric' => 'modify_count', 'threshold' => 500],
        'regional_guardian' => ['name' => '地域の守り人Ⅰ', 'description' => '既存地物を1,000件更新', 'icon' => '🛡️', 'metric' => 'modify_count', 'threshold' => 1000],
        'regional_guardian_2' => ['name' => '地域の守り人Ⅱ', 'description' => '既存地物を2,000件更新', 'icon' => '🛡️', 'metric' => 'modify_count', 'threshold' => 2000],
        'regional_guardian_3' => ['name' => '地域の守り人Ⅲ', 'description' => '既存地物を4,000件更新', 'icon' => '🛡️', 'metric' => 'modify_count', 'threshold' => 4000],
        'japan_guardian' => ['name' => '日本の守り人Ⅰ', 'description' => '既存地物を6,000件更新', 'icon' => '🗾', 'metric' => 'modify_count', 'threshold' => 6000],
        'japan_guardian_2' => ['name' => '日本の守り人Ⅱ', 'description' => '既存地物を8,000件更新', 'icon' => '🗾', 'metric' => 'modify_count', 'threshold' => 8000],
        'japan_guardian_3' => ['name' => '日本の守り人Ⅲ', 'description' => '既存地物を10,000件更新', 'icon' => '🗾', 'metric' => 'modify_count', 'threshold' => 10000],
        'maintenance_master' => ['name' => '情報整備の巨匠', 'description' => '既存地物を15,000件更新', 'icon' => '🏅', 'metric' => 'modify_count', 'threshold' => 15000, 'badge_level' => 4],
        'legendary_maintainer' => ['name' => '伝説のメンテナー', 'description' => '既存地物を20,000件更新', 'icon' => '🌟', 'metric' => 'modify_count', 'threshold' => 20000, 'badge_level' => 5],
        'welcome_back' => ['name' => 'また会いましたね', 'description' => '累積2日活動', 'icon' => '👋', 'metric' => 'active_day_count', 'threshold' => 2],
        'active_this_month' => ['name' => '活動を継続中', 'description' => '累積5日活動', 'icon' => '🌱', 'metric' => 'active_day_count', 'threshold' => 5],
        'steady_mapper' => ['name' => 'コツコツマッパー', 'description' => '累積15日活動', 'icon' => '🌿', 'metric' => 'active_day_count', 'threshold' => 15],
        'active_30_days' => ['name' => '活動30日達成', 'description' => '累積30日活動', 'icon' => '👣', 'metric' => 'active_day_count', 'threshold' => 30],
        'active_50_days' => ['name' => '活動50日達成', 'description' => '累積50日活動', 'icon' => '🗓️', 'metric' => 'active_day_count', 'threshold' => 50],
        'active_75_days' => ['name' => '活動75日達成', 'description' => '累積75日活動', 'icon' => '📅', 'metric' => 'active_day_count', 'threshold' => 75],
        'active_100_days' => ['name' => '活動100日達成', 'description' => '累積100日活動', 'icon' => '💯', 'metric' => 'active_day_count', 'threshold' => 100],
        'active_150_days' => ['name' => '活動150日達成', 'description' => '累積150日活動', 'icon' => '🌳', 'metric' => 'active_day_count', 'threshold' => 150],
        'active_200_days' => ['name' => '活動200日達成', 'description' => '累積200日活動', 'icon' => '🏅', 'metric' => 'active_day_count', 'threshold' => 200],
        'active_250_days' => ['name' => '活動250日達成', 'description' => '累積250日活動', 'icon' => '🎖️', 'metric' => 'active_day_count', 'threshold' => 250],
        'active_300_days' => ['name' => '活動300日達成', 'description' => '累積300日活動', 'icon' => '🏆', 'metric' => 'active_day_count', 'threshold' => 300, 'badge_level' => 4],
        'active_365_days' => ['name' => '活動365日達成', 'description' => '累積365日活動', 'icon' => '🌟', 'metric' => 'active_day_count', 'threshold' => 365, 'badge_level' => 5],
        'regional_explorer' => ['name' => '地域探検家', 'description' => '5都道府県で活動', 'icon' => '🧭', 'metric' => 'prefecture_count', 'threshold' => 5],
        'tohoku_traveler' => ['name' => '東北を巡る人', 'description' => '東北6県すべてで活動', 'icon' => '🍎', 'metric' => 'region_prefectures', 'prefectures' => ['青森県', '岩手県', '宮城県', '秋田県', '山形県', '福島県'], 'threshold' => 6],
        'kanto_traveler' => ['name' => '関東を巡る人', 'description' => '関東7都県すべてで活動', 'icon' => '🗼', 'metric' => 'region_prefectures', 'prefectures' => ['茨城県', '栃木県', '群馬県', '埼玉県', '千葉県', '東京都', '神奈川県'], 'threshold' => 7],
        'koshinetsu_traveler' => ['name' => '甲信越を巡る人', 'description' => '甲信越3県すべてで活動', 'icon' => '⛰️', 'metric' => 'region_prefectures', 'prefectures' => ['新潟県', '山梨県', '長野県'], 'threshold' => 3],
        'hokuriku_traveler' => ['name' => '北陸を巡る人', 'description' => '北陸3県すべてで活動', 'icon' => '🌾', 'metric' => 'region_prefectures', 'prefectures' => ['富山県', '石川県', '福井県'], 'threshold' => 3],
        'tokai_traveler' => ['name' => '東海を巡る人', 'description' => '東海4県すべてで活動', 'icon' => '🏯', 'metric' => 'region_prefectures', 'prefectures' => ['岐阜県', '静岡県', '愛知県', '三重県'], 'threshold' => 4],
        'kansai_traveler' => ['name' => '関西を巡る人', 'description' => '関西6府県すべてで活動', 'icon' => '🐙', 'metric' => 'region_prefectures', 'prefectures' => ['滋賀県', '京都府', '大阪府', '兵庫県', '奈良県', '和歌山県'], 'threshold' => 6],
        'chugoku_traveler' => ['name' => '中国を巡る人', 'description' => '中国5県すべてで活動', 'icon' => '⛩️', 'metric' => 'region_prefectures', 'prefectures' => ['鳥取県', '島根県', '岡山県', '広島県', '山口県'], 'threshold' => 5],
        'shikoku_traveler' => ['name' => '四国を巡る人', 'description' => '四国4県すべてで活動', 'icon' => '🍊', 'metric' => 'region_prefectures', 'prefectures' => ['徳島県', '香川県', '愛媛県', '高知県'], 'threshold' => 4],
        'kyushu_okinawa_traveler' => ['name' => '九州・沖縄を巡る人', 'description' => '九州・沖縄8県すべてで活動', 'icon' => '🌋', 'metric' => 'region_prefectures', 'prefectures' => ['福岡県', '佐賀県', '長崎県', '熊本県', '大分県', '宮崎県', '鹿児島県', '沖縄県'], 'threshold' => 8],
        'japan_traveler' => ['name' => '日本を巡る人', 'description' => '47都道府県すべてで活動', 'icon' => '🗾', 'metric' => 'all_prefectures', 'threshold' => 47, 'version' => 2],
        'curious_mapper' => ['name' => '多彩なマッパーⅠ', 'description' => '5カテゴリをマッピング', 'icon' => '🎨', 'metric' => 'category_count', 'threshold' => 5],
        'category_explorer' => ['name' => '多彩なマッパーⅡ', 'description' => '10カテゴリをマッピング', 'icon' => '🎨', 'metric' => 'category_count', 'threshold' => 10],
        'versatile_mapper' => ['name' => '多彩なマッパーⅢ', 'description' => '20カテゴリをマッピング', 'icon' => '🎨', 'metric' => 'category_count', 'threshold' => 20],
        'local_regular' => ['name' => '地域の常連Ⅰ', 'description' => '同じ都道府県で累積10日活動', 'icon' => '🏠', 'metric' => 'prefecture_active_day_count', 'threshold' => 10],
        'local_regular_2' => ['name' => '地域の常連Ⅱ', 'description' => '同じ都道府県で累積30日活動', 'icon' => '🏠', 'metric' => 'prefecture_active_day_count', 'threshold' => 30],
        'local_regular_3' => ['name' => '地域の常連Ⅲ', 'description' => '同じ都道府県で累積100日活動', 'icon' => '🏠', 'metric' => 'prefecture_active_day_count', 'threshold' => 100],
        'balanced_mapper' => ['name' => '発見と手入れⅠ', 'description' => '新規地物と既存地物を各10件マッピング', 'icon' => '⚖️', 'metric' => 'balanced_count', 'threshold' => 10],
        'balanced_mapper_2' => ['name' => '発見と手入れⅡ', 'description' => '新規地物と既存地物を各100件マッピング', 'icon' => '⚖️', 'metric' => 'balanced_count', 'threshold' => 100],
        'balanced_mapper_3' => ['name' => '発見と手入れⅢ', 'description' => '新規地物と既存地物を各1,000件マッピング', 'icon' => '⚖️', 'metric' => 'balanced_count', 'threshold' => 1000],
    ];
    $badgeSeries = [
        'local_observer' => ['local_observer', 'local_observer_2'],
        'local_walker' => ['local_walker', 'local_walker_2'],
        'local_explorer' => ['local_explorer', 'local_explorer_2'],
        'local_geographer' => ['local_geographer', 'local_geographer_2', 'local_geographer_3'],
        'japan_geographer' => ['local_geographer_4', 'local_geographer_5', 'japan_geographer'],
        'regional_discoverer' => ['regional_discoverer', 'regional_discoverer_2'],
        'regional_recorder' => ['regional_recorder', 'regional_recorder_2'],
        'regional_pioneer' => ['regional_pioneer', 'regional_pioneer_2', 'regional_pioneer_3'],
        'japan_pioneer' => ['japan_pioneer', 'japan_pioneer_2', 'japan_pioneer_3'],
        'regional_maintainer' => ['regional_maintainer', 'regional_maintainer_2'],
        'regional_mechanic' => ['regional_mechanic', 'regional_mechanic_2'],
        'regional_guardian' => ['regional_guardian', 'regional_guardian_2', 'regional_guardian_3'],
        'japan_guardian' => ['japan_guardian', 'japan_guardian_2', 'japan_guardian_3'],
        'versatile_mapper' => ['curious_mapper', 'category_explorer', 'versatile_mapper'],
        'local_regular' => ['local_regular', 'local_regular_2', 'local_regular_3'],
        'balanced_mapper' => ['balanced_mapper', 'balanced_mapper_2', 'balanced_mapper_3'],
    ];
    foreach ($badgeSeries as $groupKey => $keys) {
        foreach ($keys as $index => $key) {
            $definitions[$key]['badge_group'] = 'series_' . $groupKey;
            $definitions[$key]['badge_level'] = $index + 1;
        }
    }
    $tagGroups = [
        'public_facilities' => ['name' => '公共設備マッパー', 'label' => '公共設備', 'icon' => '🚻', 'patterns' => ['amenity=bench', 'amenity=toilets', 'amenity=drinking_water', 'amenity=waste_basket', 'amenity=recycling', 'amenity=shelter']],
        'transport' => ['name' => '交通案内マッパー', 'label' => '交通案内', 'icon' => '🚉', 'patterns' => ['highway=bus_stop', 'railway=station', 'railway=halt', 'railway=tram_stop']],
        'bicycle' => ['name' => '自転車マッパー', 'label' => '自転車設備', 'icon' => '🚲', 'patterns' => ['amenity=bicycle_parking', 'amenity=bicycle_rental', 'amenity=bicycle_repair_station']],
        'medical' => ['name' => '医療マッパー', 'label' => '医療施設', 'icon' => '🏥', 'patterns' => ['amenity=hospital', 'amenity=clinic', 'amenity=doctors', 'amenity=pharmacy', 'amenity=dentist']],
        'disaster_prevention' => ['name' => '防災マッパー', 'label' => '防災設備', 'icon' => '🧯', 'patterns' => ['emergency=fire_hydrant', 'emergency=defibrillator', 'emergency=assembly_point', 'amenity=fire_station']],
        'education' => ['name' => '学びの場マッパー', 'label' => '教育施設', 'icon' => '🏫', 'patterns' => ['amenity=school', 'amenity=kindergarten', 'amenity=college', 'amenity=university', 'amenity=library']],
        'food' => ['name' => '食べ歩きマッパー', 'label' => '飲食店', 'icon' => '🍽️', 'patterns' => ['amenity=restaurant', 'amenity=cafe', 'amenity=fast_food', 'amenity=pub', 'amenity=bar']],
        'shopping' => ['name' => 'お買い物マッパー', 'label' => '店舗', 'icon' => '🛍️', 'patterns' => ['shop=*']],
        'tourism' => ['name' => '観光案内マッパー', 'label' => '観光施設', 'icon' => '📸', 'patterns' => ['tourism=information', 'tourism=attraction', 'tourism=museum', 'tourism=viewpoint']],
        'parks' => ['name' => '公園マッパー', 'label' => '公園・遊び場', 'icon' => '🌳', 'patterns' => ['leisure=park', 'leisure=playground', 'leisure=garden', 'leisure=nature_reserve']],
        'sports' => ['name' => 'スポーツマッパー', 'label' => 'スポーツ施設', 'icon' => '⚽', 'patterns' => ['leisure=pitch', 'leisure=sports_centre', 'leisure=stadium', 'leisure=swimming_pool']],
        'daily_services' => ['name' => '暮らしの窓口マッパー', 'label' => '生活サービス', 'icon' => '🏤', 'patterns' => ['amenity=post_office', 'amenity=post_box', 'amenity=bank', 'amenity=atm', 'amenity=townhall']],
        'crossings' => ['name' => '横断歩道マッパー', 'label' => '横断歩道', 'icon' => '🚸', 'patterns' => ['highway=crossing']],
        'historic' => ['name' => '歴史マッパー', 'label' => '歴史的地物', 'icon' => '🏛️', 'patterns' => ['historic=*']],
        'lodging' => ['name' => '宿泊マッパー', 'label' => '宿泊施設', 'icon' => '🛏️', 'patterns' => ['tourism=hotel', 'tourism=hostel', 'tourism=guest_house', 'tourism=camp_site']],
    ];
    $tagLevels = [1 => [10, 'Ⅰ'], 2 => [50, 'Ⅱ'], 3 => [250, 'Ⅲ']];
    foreach ($tagGroups as $groupKey => $group) {
        foreach ($tagLevels as $level => [$threshold, $suffix]) {
            $definitions["tag_{$groupKey}_{$level}"] = [
                'name' => $group['name'] . $suffix,
                'description' => $group['label'] . "を{$threshold}件マッピング",
                'icon' => $group['icon'],
                'metric' => 'tag_group',
                'tag_patterns' => $group['patterns'],
                'badge_group' => "tag_{$groupKey}",
                'badge_level' => $level,
                'threshold' => $threshold,
            ];
        }
    }
    $prefectureIcons = profilePrefectureBadgeIcons();
    foreach (profilePrefectureBadgeNames() as $key => $prefecture) {
        foreach ([1 => [10, 'Ⅰ'], 2 => [50, 'Ⅱ'], 3 => [250, 'Ⅲ']] as $level => [$threshold, $suffix]) {
            $badgeKey = 'prefecture_' . $key . ($level === 1 ? '' : '_' . $level);
            $definitions[$badgeKey] = [
                'name' => $prefecture . 'マッパー' . $suffix,
                'description' => $prefecture . "で{$threshold}件マッピング",
                'icon' => $prefectureIcons[$key],
                'metric' => 'prefecture_mapping_count',
                'prefecture' => $prefecture,
                'badge_group' => 'prefecture_' . $key,
                'badge_level' => $level,
                'threshold' => $threshold,
                'version' => $level === 1 ? 2 : 1,
            ];
        }
    }
    return $definitions;
}

function profileBadgeProgress(array $stats, array $definition): int
{
    if ($definition['metric'] === 'balanced_count') {
        return min((int) $stats['create_count'], (int) $stats['modify_count']);
    }
    $activePrefectures = array_values(array_intersect(
        (array) ($stats['prefectures'] ?? []),
        array_values(profilePrefectureBadgeNames())
    ));
    if ($definition['metric'] === 'all_prefectures') {
        return count(array_unique($activePrefectures));
    }
    if ($definition['metric'] === 'region_prefectures') {
        return count(array_intersect($activePrefectures, $definition['prefectures']));
    }
    if ($definition['metric'] === 'prefecture_mapping_count') {
        return (int) (($stats['prefecture_values'] ?? [])[$definition['prefecture']] ?? 0);
    }
    if ($definition['metric'] === 'prefecture_active_day_count') {
        return max([0, ...array_map('intval', (array) ($stats['prefecture_active_day_values'] ?? []))]);
    }
    if ($definition['metric'] === 'tag_group') {
        $total = 0;
        foreach ((array) ($stats['category_values'] ?? []) as $tag => $count) {
            foreach ($definition['tag_patterns'] as $pattern) {
                if ($tag === $pattern || (str_ends_with($pattern, '=*') && str_starts_with($tag, substr($pattern, 0, -1)))) {
                    $total += (int) $count;
                    break;
                }
            }
        }
        return $total;
    }
    return (int) ($stats[$definition['metric']] ?? 0);
}

function profileApplyBadgeDefinitions(PDO $pdo, ?string $calculatedAt = null): array
{
    $calculatedAt ??= gmdate('Y-m-d H:i:s');
    $definitions = profileBadgeDefinitions();
    $statsRows = $pdo->query(
        'SELECT editor_uid, total_count, create_count, modify_count, active_day_count,
                prefecture_count, category_count
           FROM mapper_profile_stats'
    )->fetchAll(PDO::FETCH_ASSOC);
    $prefectureRows = $pdo->query(
        'SELECT editor_uid, prefecture, total_count, active_day_count FROM mapper_profile_prefectures'
    )->fetchAll(PDO::FETCH_ASSOC);
    $prefecturesByUid = [];
    $prefectureValuesByUid = [];
    $prefectureActiveDayValuesByUid = [];
    foreach ($prefectureRows as $row) {
        $prefecturesByUid[(string) $row['editor_uid']][] = (string) $row['prefecture'];
        $prefectureValuesByUid[(string) $row['editor_uid']][(string) $row['prefecture']] = (int) $row['total_count'];
        $prefectureActiveDayValuesByUid[(string) $row['editor_uid']][(string) $row['prefecture']] = (int) $row['active_day_count'];
    }
    $categoryRows = $pdo->query(
        'SELECT editor_uid, category, category_value, total_count FROM mapper_profile_categories'
    )->fetchAll(PDO::FETCH_ASSOC);
    $categoryValuesByUid = [];
    foreach ($categoryRows as $row) {
        $categoryValuesByUid[(string) $row['editor_uid']][(string) $row['category'] . '=' . (string) $row['category_value']] = (int) $row['total_count'];
    }
    $beforeCount = (int) $pdo->query('SELECT COUNT(*) FROM mapper_badges')->fetchColumn();
    $existingRows = $pdo->query(
        'SELECT editor_uid, badge_key, progress_value, badge_version, revoked_at FROM mapper_badges'
    )->fetchAll(PDO::FETCH_ASSOC);
    $existingBadges = [];
    foreach ($existingRows as $row) {
        $existingBadges[(string) $row['editor_uid']][(string) $row['badge_key']] = $row;
    }

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO mapper_badges
             (editor_uid, badge_key, earned_at, progress_updated_at, progress_value,
             badge_version, acquisition_source)
             VALUES (?, ?, ?, ?, ?, ?, \'backfill\')'
        );
        $update = $pdo->prepare(
            'UPDATE mapper_badges
                SET progress_value = ?, progress_updated_at = ?, badge_version = ?,
                    revoked_at = NULL, revoked_reason = NULL
              WHERE editor_uid = ? AND badge_key = ?'
        );
        $revoke = $pdo->prepare(
            'UPDATE mapper_badges
                SET progress_value = ?, progress_updated_at = ?, badge_version = ?,
                    revoked_at = ?, revoked_reason = ?
              WHERE editor_uid = ? AND badge_key = ?'
        );
        $insertedCount = 0;
        $updatedCount = 0;
        foreach ($statsRows as $stats) {
            $uid = (string) $stats['editor_uid'];
            $stats['prefectures'] = $prefecturesByUid[$uid] ?? [];
            $stats['prefecture_values'] = $prefectureValuesByUid[$uid] ?? [];
            $stats['prefecture_active_day_values'] = $prefectureActiveDayValuesByUid[$uid] ?? [];
            $stats['category_values'] = $categoryValuesByUid[$uid] ?? [];
            foreach ($definitions as $key => $definition) {
                $progress = profileBadgeProgress($stats, $definition);
                $version = (int) ($definition['version'] ?? 1);
                $existing = $existingBadges[$uid][$key] ?? null;
                if ($progress < $definition['threshold']) {
                    if ($existing !== null && (int) $existing['badge_version'] < $version && $existing['revoked_at'] === null) {
                        $revoke->execute([
                            $progress, $calculatedAt, $version, $calculatedAt,
                            '獲得条件の変更', $uid, $key,
                        ]);
                        $updatedCount++;
                    }
                    continue;
                }
                if ($existing === null) {
                    $insert->execute([$uid, $key, $calculatedAt, $calculatedAt, $progress, $version]);
                    $insertedCount++;
                } elseif ($progress > (int) $existing['progress_value']
                    || (int) $existing['badge_version'] < $version
                    || $existing['revoked_at'] !== null) {
                    $update->execute([$progress, $calculatedAt, $version, $uid, $key]);
                    $updatedCount++;
                }
            }
        }
        $state = $pdo->prepare(
            "INSERT INTO osm_sync_state (state_key, state_value)
             VALUES ('profile_badges_initialized', ?)
             ON DUPLICATE KEY UPDATE state_value=VALUES(state_value)"
        );
        $state->execute([$calculatedAt]);
        $afterCount = (int) $pdo->query('SELECT COUNT(*) FROM mapper_badges')->fetchColumn();
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    return [
        'beforeCount' => $beforeCount,
        'afterCount' => $afterCount,
        'insertedCount' => $insertedCount,
        'updatedCount' => $updatedCount,
        'mapperCount' => count($statsRows),
        'calculatedAt' => $calculatedAt,
    ];
}

function profileCumulativeLevel(int $total): array
{
    $levels = [
        1 => 'Registered Editor',
        10 => 'Novice Editor',
        25 => 'Apprentice Editor',
        50 => 'Journeyman Editor',
        100 => 'Yeoman Editor',
        250 => 'Experienced Editor',
        500 => 'Veteran Editor',
        1000 => 'Veteran Editor II',
        1500 => 'Veteran Editor III',
        2000 => 'Veteran Editor IV',
        3000 => 'Senior Editor',
        4000 => 'Senior Editor II',
        5000 => 'Senior Editor III',
        7500 => 'Master Editor',
        10000 => 'Master Editor II',
        15000 => 'Master Editor III',
        20000 => 'Master Editor IV',
        30000 => 'Grandmaster Editor',
    ];
    $level = 0;
    $name = 'No title';
    foreach ($levels as $threshold => $label) {
        if ($total < $threshold) break;
        $level++;
        $name = $label;
    }
    return ['level' => $level, 'name' => $name, 'total' => $total];
}

function profileMonthlyLevel(int $total): array
{
    $levels = [
        1 => 'これからマッパー',
        10 => 'それなりマッパー',
        50 => 'そこそこマッパー',
        100 => 'たくさんマッパー',
        250 => 'ものすごマッパー',
        500 => 'とんでもマッパー',
        1000 => 'さいこうマッパー',
    ];
    $level = 0;
    $name = '';
    foreach ($levels as $threshold => $label) {
        if ($total < $threshold) break;
        $level++;
        $name = $label;
    }
    return ['level' => $level, 'name' => $name, 'total' => $total];
}
