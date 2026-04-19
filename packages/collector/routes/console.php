<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 週次収集: 収集対象ごとに collect:run をキュー投入
Schedule::call(function (): void {
    $targets = config('collection_targets', []);

    foreach ($targets as $target) {
        if (! ($target['enabled'] ?? false)) {
            continue;
        }

        $url = $target['url'] ?? '';
        $depth = $target['depth'] ?? 3;
        $pages = $target['pages'] ?? 100;
        $template = $target['template'] ?? 'childcare';

        if ($url !== '') {
            Artisan::call('collect:run', [
                'url' => $url,
                '--depth' => $depth,
                '--pages' => $pages,
                '--template' => $template,
                '--queue' => true,
            ]);
        }
    }
})->weekly()->name('collect:weekly')->withoutOverlapping();

// 月次ファクトチェック: low/medium のみ再検証
Schedule::command('collect:verify --confidence=low')
    ->monthly()
    ->name('factcheck:monthly-low')
    ->withoutOverlapping();

Schedule::command('collect:verify --confidence=medium')
    ->monthly()
    ->name('factcheck:monthly-medium')
    ->withoutOverlapping();
