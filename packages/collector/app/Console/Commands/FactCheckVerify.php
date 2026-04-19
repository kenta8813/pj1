<?php

namespace App\Console\Commands;

use App\Services\DataStoreService;
use App\Services\FactCheckService;
use Illuminate\Console\Command;

class FactCheckVerify extends Command
{
    protected $signature = 'collect:verify
        {--url= : 特定URLのみ検証}
        {--confidence=all : 絞り込み（all|high|medium|low|unchecked）}
        {--dry-run : 保存せず結果表示のみ}
        {--queue : FactCheckJob に投入して非同期実行（未実装）}';

    protected $description = '収集済みデータのファクトチェックを実行する';

    public function handle(FactCheckService $checker, DataStoreService $store): int
    {
        $url = $this->option('url');
        $confidence = (string) $this->option('confidence');
        $dryRun = (bool) $this->option('dry-run');

        if ($url) {
            return $this->checkSingleUrl((string) $url, $checker, $store, $dryRun);
        }

        return $this->checkAll($checker, $confidence, $dryRun);
    }

    private function checkSingleUrl(string $url, FactCheckService $checker, DataStoreService $store, bool $dryRun): int
    {
        $data = $store->findByUrl($url);

        if ($data === null) {
            $this->error("データが見つかりません: {$url}");

            return self::FAILURE;
        }

        $result = $checker->check($data, $dryRun);
        $this->line("URL: {$url}");
        $this->line("confidence: {$result['confidence']}");

        if (! empty($result['issues'])) {
            foreach ($result['issues'] as $issue) {
                $this->line("  - {$issue}");
            }
        }

        return self::SUCCESS;
    }

    private function checkAll(FactCheckService $checker, string $confidence, bool $dryRun): int
    {
        $this->info('ファクトチェック開始'.($dryRun ? ' [dry-run]' : ''));

        $counts = $checker->checkAll($dryRun, $confidence);

        $this->info("完了: {$counts['checked']}件チェック");
        $this->line("  high:   {$counts['high']}件");
        $this->line("  medium: {$counts['medium']}件");
        $this->line("  low:    {$counts['low']}件");

        return self::SUCCESS;
    }
}
