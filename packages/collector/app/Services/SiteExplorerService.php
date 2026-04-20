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
        private readonly SitemapService $sitemap,
    ) {}

    /**
     * Autonomously explore a municipality site and collect data.
     *
     * @param  array<string, mixed>  $template
     */
    public function explore(
        string $entryUrl,
        array $template,
        ?int $maxDepth = null,
        int $maxPages = 100,
        bool $dryRun = false,
        string $templateName = 'childcare',
    ): int {
        $sitemapUrls = $this->sitemap->discoverUrls($entryUrl, $templateName);
        $usingSitemap = ! empty($sitemapUrls);

        $queue = $usingSitemap
            ? array_fill_keys($sitemapUrls, 0)
            : [$entryUrl => 0];

        // サイトマップあり: index.html起点から2段、なし: エントリURLから5段
        $effectiveDepth = $maxDepth ?? ($usingSitemap ? 2 : 5);
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

            $data = $this->extractor->extract($html, $url, $template, $templateName);

            $primaryKey = $templateName === 'grants' ? 'name' : 'title';

            if (! empty($data) && ($data[$primaryKey] ?? '') !== '') {
                if (! $dryRun) {
                    $this->store->save($data);
                }
                $saved++;
                Log::info("SiteExplorerService: 保存 [{$url}]");
            }

            if ($depth < $effectiveDepth) {
                $allLinks = $this->fetcher->extractLinks($html, $url);
                $relevant = $this->filterLinks($allLinks, $templateName);

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
    private function filterLinks(array $links, string $templateName = 'childcare'): array
    {
        if (empty($links)) {
            return [];
        }

        $prompt = $templateName === 'grants'
            ? "以下のURLリストから、給付金・助成金・手当・補助金・医療費助成・保育料助成に関連するURLのみを返してください:\n"
            : "以下のURLリストから子育て支援に関連するものを返してください:\n";

        try {
            $linksJson = json_encode($links, JSON_UNESCAPED_UNICODE);
            $response = (string) $this->linkFilter->prompt(
                $prompt.$linksJson,
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
