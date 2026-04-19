<?php

return [

    /*
     * 定期自動収集の対象自治体サイト一覧。
     * enabled: false のエントリはスケジューラーに無視される。
     */

    [
        'url' => env('COLLECT_TARGET_1_URL', ''),
        'depth' => 3,
        'pages' => 100,
        'template' => 'childcare',
        'enabled' => (bool) env('COLLECT_TARGET_1_ENABLED', false),
    ],

    // 富山県高岡市（子育て・教育セクション）
    [
        'url' => 'https://www.city.takaoka.toyama.jp/gyosei/kosodate_kyoiku/index.html',
        'depth' => 2,
        'pages' => 100,
        'template' => 'childcare',
        'enabled' => true,
    ],

];
