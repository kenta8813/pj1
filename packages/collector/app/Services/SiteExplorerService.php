<?php

namespace App\Services;

use App\Ai\LinkFilterAgent;
use Illuminate\Support\Facades\Log;

class SiteExplorerService
{
    public function __construct(
        private readonly FetchService $fetcher,
        private readonly LinkFilterAgent $linkFilter,
        private readonly ExtractorService $extractor,
        private readonly DataStoreService $store,
    ) {}

    /**
     * Autonomously explore a municipality site and collect childcare support data.
     *
     * @param  array<string, mixed>  $template
     */
    public function explore(
        string $entryUrl,
        array $template,
        int $maxDepth = 3,
        int $maxPages = 100,
        bool $dryRun = false,
    ): int {
        $queue = [$entryUrl => 0];
        $visited = [];
        $saved = 0;

        while (! empty($queue) && count($visited) < $maxPages) {
            $url = array_key_first($queue);
            $depth = $queue[$url];
            unset($queue[$url]);

            if (isset($visited[$url])) {
                continue;
            }

            if (! $this->fetcher->isAllowedByRobots($url)) {
                Log::info("SiteExplorerService: robots.txt により除外 [{$url}]");
                $visited[$url] = true;

                continue;
            }

            $visited[$url] = true;

            $rateLimitMs = (int) config('ai.crawler.rate_limit_ms', 1000);
            if ($rateLimitMs > 0) {
                usleep($rateLimitMs * 1000);
            }

            try {
                $html = $this->fetcher->fetch($url);
            } catch (\Throwable $e) {
                Log::warning("SiteExplorerService: 取得失敗 [{$url}] — {$e->getMessage()}");

                continue;
            }

            $data = $this->extractor->extract($html, $url, $template);

            if (! empty($data) && ($data['title'] ?? '') !== '') {
                if (! $dryRun) {
                    $this->store->save($data);
                }
                $saved++;
                Log::info("SiteExplorerService: 保存 [{$url}]");
            }

            if ($depth < $maxDepth) {
                $allLinks = $this->fetcher->extractLinks($html, $url);
                $relevant = $this->filterLinks($allLinks);

                foreach ($relevant as $link) {
                    if (! isset($visited[$link]) && ! isset($queue[$link])) {
                        $queue[$link] = $depth + 1;
                    }
                }
            }
        }

        return $saved;
    }

    /**
     * @param  array<int, string>  $links
     * @return array<int, string>
     */
    private function filterLinks(array $links): array
    {
        if (empty($links)) {
            return [];
        }

        try {
            $linksJson = json_encode($links, JSON_UNESCAPED_UNICODE);
            $response = (string) $this->linkFilter->prompt(
                "以下のURLリストから子育て支援に関連するものを返してください:\n{$linksJson}",
                model: config('ai.model')
            );

            $cleaned = preg_replace('/^```(?:json)?\s*/m', '', $response);
            $cleaned = preg_replace('/\s*```$/m', '', $cleaned ?? $response);
            $decoded = json_decode(trim($cleaned ?? $response), true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            Log::warning("SiteExplorerService: リンクフィルタ失敗 — {$e->getMessage()}");

            return [];
        }
    }
}
