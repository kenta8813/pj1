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

    // 対象自治体が決まり次第追加してください
    // [
    //     'url'      => 'https://www.city.example.lg.jp/',
    //     'depth'    => 3,
    //     'pages'    => 100,
    //     'template' => 'childcare',
    //     'enabled'  => true,
    // ],

];
