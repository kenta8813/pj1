<?php

namespace App\Jobs;

use App\Services\SiteExplorerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExploreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public readonly string $entryUrl,
        public readonly string $templateName = 'childcare',
        public readonly ?int $maxDepth = null,
        public readonly int $maxPages = 100,
        public readonly bool $dryRun = false,
    ) {}

    public function handle(SiteExplorerService $explorer): void
    {
        $templatePath = resource_path("templates/{$this->templateName}.json");

        if (! file_exists($templatePath)) {
            Log::error("ExploreJob: テンプレートが見つかりません [{$templatePath}]");

            return;
        }

        $template = json_decode(file_get_contents($templatePath), true) ?? [];

        $saved = $explorer->explore(
            entryUrl: $this->entryUrl,
            template: $template,
            maxDepth: $this->maxDepth,
            maxPages: $this->maxPages,
            dryRun: $this->dryRun,
        );

        Log::info("ExploreJob: 完了 [{$this->entryUrl}] — {$saved}件保存");
    }
}
