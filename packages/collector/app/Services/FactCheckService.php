<?php

namespace App\Services;

use App\Ai\FactCheckerAgent;
use Illuminate\Support\Facades\Log;

class FactCheckService
{
    public function __construct(
        private readonly FetchService $fetcher,
        private readonly FactCheckerAgent $agent,
        private readonly DataStoreService $store,
        private readonly ExtractorService $extractor,
    ) {}

    /**
     * Fact-check a single data item against its source URL.
     *
     * @param  array<string, mixed>  $data
     * @return array{confidence: string, issues: array<string>}
     */
    public function check(array $data, bool $dryRun = false): array
    {
        $url = $data['url'] ?? '';

        try {
            $html = $this->fetcher->fetch($url);
            $clean = $this->extractor->cleanHtml($html);
        } catch (\Throwable $e) {
            Log::warning("FactCheckService: ページ取得失敗 [{$url}] — {$e->getMessage()}");

            $result = ['confidence' => 'low', 'issues' => ['ページ取得失敗: '.$e->getMessage()]];
            $this->writeResult($data, $result, $dryRun);

            return $result;
        }

        $dataForCheck = array_filter(
            $data,
            fn (string $key) => ! str_starts_with($key, '_fc_'),
            ARRAY_FILTER_USE_KEY
        );

        $prompt = "抽出済みデータ:\n".json_encode($dataForCheck, JSON_UNESCAPED_UNICODE)."\n\n現在のページHTML:\n".$clean;

        try {
            $response = (string) $this->agent->prompt($prompt, model: config('ai.model'));
            $result = $this->parseCheckResponse($response);
        } catch (\Throwable $e) {
            Log::warning("FactCheckService: LLM失敗 [{$url}] — {$e->getMessage()}");
            $result = ['confidence' => 'low', 'issues' => ['LLM呼び出し失敗']];
        }

        $this->writeResult($data, $result, $dryRun);

        return $result;
    }

    /**
     * Fact-check all stored items.
     *
     * @param  string  $confidence  Filter: all|high|medium|low|unchecked
     * @return array{checked: int, high: int, medium: int, low: int}
     */
    public function checkAll(bool $dryRun = false, string $confidence = 'all'): array
    {
        $all = $this->store->all();

        if ($confidence !== 'all') {
            $all = $all->filter(function (array $item) use ($confidence): bool {
                if ($confidence === 'unchecked') {
                    return ($item['_fc_confidence'] ?? '') === '';
                }

                return ($item['_fc_confidence'] ?? '') === $confidence;
            });
        }

        $counts = ['checked' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];

        foreach ($all as $item) {
            $result = $this->check($item, $dryRun);
            $counts['checked']++;
            $level = $result['confidence'];

            if (isset($counts[$level])) {
                $counts[$level]++;
            }
        }

        return $counts;
    }

    /** @param  array<string, mixed>  $data */
    private function writeResult(array $data, array $result, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        $data['_fc_checked_at'] = now()->toIso8601String();
        $data['_fc_confidence'] = $result['confidence'];
        $data['_fc_issues'] = $result['issues'];

        $this->store->save($data);
    }

    /** @return array{confidence: string, issues: array<string>} */
    private function parseCheckResponse(string $response): array
    {
        $cleaned = preg_replace('/^```(?:json)?\s*/m', '', $response);
        $cleaned = preg_replace('/\s*```$/m', '', $cleaned ?? $response);
        $decoded = json_decode(trim($cleaned ?? $response), true);

        if (! is_array($decoded) || ! isset($decoded['confidence'])) {
            return ['confidence' => 'low', 'issues' => ['レスポンス解析失敗']];
        }

        return [
            'confidence' => (string) $decoded['confidence'],
            'issues' => (array) ($decoded['issues'] ?? []),
        ];
    }
}
