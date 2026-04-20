<?php

namespace App\Console\Commands;

use App\Jobs\ExploreJob;
use App\Services\SiteExplorerService;
use Illuminate\Console\Command;

class CollectRun extends Command
{
    protected $signature = 'collect:run
        {url : 収集対象の自治体サイトURL}
        {--depth=3 : 探索深度}
        {--pages=100 : 最大ページ数}
        {--template=childcare : テンプレート名}
        {--dry-run : 保存せず結果をログ出力のみ}
        {--queue : キューに投入して非同期実行}';

    protected $description = '自治体サイトを自律探索して子育て支援情報を収集する';

    public function handle(SiteExplorerService $explorer): int
    {
        $url = (string) $this->argument('url');
        $depth = (int) $this->option('depth');
        $pages = (int) $this->option('pages');
        $templateName = (string) $this->option('template');
        $dryRun = (bool) $this->option('dry-run');
        $queue = (bool) $this->option('queue');

        $templatePath = resource_path("templates/{$templateName}.json");

        if (! file_exists($templatePath)) {
            $this->error("テンプレートが見つかりません: {$templatePath}");

            return self::FAILURE;
        }

        if ($queue) {
            ExploreJob::dispatch($url, $templateName, $depth, $pages, $dryRun);
            $this->info("キューに投入しました: {$url}");

            return self::SUCCESS;
        }

        $template = json_decode(file_get_contents($templatePath), true) ?? [];

        $this->info("収集開始: {$url}".($dryRun ? ' [dry-run]' : ''));

        $saved = $explorer->explore(
            entryUrl: $url,
            template: $template,
            maxDepth: $depth,
            maxPages: $pages,
            dryRun: $dryRun,
            templateName: $templateName,
        );

        $this->info("完了: {$saved}件".($dryRun ? '（dry-run のため保存なし）' : '保存'));

        return self::SUCCESS;
    }
}
