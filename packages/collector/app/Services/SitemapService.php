<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SitemapService
{
    /** @var array<string, array<int, string>> キーワードマップ（テンプレート別） */
    private const KEYWORDS = [
        'childcare' => [
            'kosodate', 'kodomo', 'jido', 'hoiku', 'youji', 'nyuuyouji',
            'ninshin', 'boshi', 'ikuji', 'sodachi', 'katei', 'gakudo',
            'shochugakko', 'kyoiku', 'kosodateshien', 'kizuna',
        ],
        'grants' => [
            'kosodate', 'kodomo', 'jido', 'hoiku', 'ninshin', 'boshi',
            'ikuji', 'teate', 'hojo', 'josei', 'kyufu', 'iryo',
            'jidofukushi', 'hitorioya', 'kosodateshien',
        ],
    ];

    public function __construct(
        private readonly FetchService $fetcher,
    ) {}

    /**
     * エントリURLのドメインからサイトマップを探索し、
     * テンプレートに関連するページURLを返す。
     * 浅いセクション起点を優先し、最大 sitemap_max_urls 件に絞り込む。
     * サイトマップが存在しない・取得失敗の場合は空配列を返す。
     *
     * @return array<int, string>
     */
    public function discoverUrls(string $entryUrl, string $templateName = 'childcare'): array
    {
        $sitemapUrl = $this->buildSitemapUrl($entryUrl);

        try {
            $xml = $this->fetcher->fetch($sitemapUrl);
        } catch (\Throwable $e) {
            Log::info("SitemapService: サイトマップ取得スキップ [{$sitemapUrl}] — {$e->getMessage()}");

            return [];
        }

        if ($this->isSitemapIndex($xml)) {
            $urls = $this->processIndex($xml, $templateName);
        } else {
            // ダイレクトサイトマップ: サブサイトマップ名フィルタがないためURLパスにもキーワードフィルタを適用
            $urls = $this->extractPageUrls($xml, applyKeywordFilter: true, templateName: $templateName);
        }

        // 浅いセクション起点を優先し、設定上限で切り詰め
        usort($urls, fn (string $a, string $b) => $this->urlPathDepth($a) <=> $this->urlPathDepth($b));
        $maxUrls = (int) config('ai.crawler.sitemap_max_urls', 30);
        $urls = array_slice($urls, 0, $maxUrls);

        Log::info('SitemapService: '.count($urls)."件のURLをサイトマップから発見 [{$sitemapUrl}]");

        return $urls;
    }

    private function buildSitemapUrl(string $entryUrl): string
    {
        $parsed = parse_url($entryUrl);

        return ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? '').'/sitemap.xml';
    }

    private function isSitemapIndex(string $xml): bool
    {
        return str_contains($xml, '<sitemapindex');
    }

    /**
     * サイトマップインデックスを処理する。
     * キーワードフィルタで関連サブサイトマップを選別し、ページURLを収集する。
     *
     * @return array<int, string>
     */
    private function processIndex(string $xml, string $templateName): array
    {
        preg_match_all('/<loc>(.*?)<\/loc>/s', $xml, $m);
        $allSitemaps = array_map('trim', $m[1] ?? []);

        $relevant = $this->filterSitemapsByKeyword($allSitemaps, $templateName);

        Log::info('SitemapService: '.count($relevant).'/'.count($allSitemaps).'件のサブサイトマップを選択');

        $pages = [];

        foreach ($relevant as $url) {
            try {
                $subXml = $this->fetcher->fetch($url);
                $pages = array_merge($pages, $this->extractPageUrls($subXml));
            } catch (\Throwable $e) {
                Log::warning("SitemapService: サブサイトマップ取得失敗 [{$url}] — {$e->getMessage()}");
            }
        }

        return array_values(array_unique($pages));
    }

    /**
     * サイトマップURLリストをキーワードでフィルタする（LLM不使用・高速）。
     *
     * @param  array<int, string>  $urls
     * @return array<int, string>
     */
    private function filterSitemapsByKeyword(array $urls, string $templateName): array
    {
        $keywords = self::KEYWORDS[$templateName] ?? self::KEYWORDS['childcare'];
        $pattern = implode('|', $keywords);

        return array_values(array_filter(
            $urls,
            fn (string $url) => (bool) preg_match("/({$pattern})/i", $url)
        ));
    }

    /**
     * サイトマップXMLからセクション入口URL（index.html / ディレクトリroot）を抽出する。
     * 葉ページ（個別記事・番号URL等）は除外し、HTMLリンク探索の起点となるURLだけを返す。
     * $applyKeywordFilter が true の場合、URLパス自体にもキーワードフィルタを適用する。
     *
     * @return array<int, string>
     */
    private function extractPageUrls(
        string $xml,
        bool $applyKeywordFilter = false,
        string $templateName = '',
    ): array {
        preg_match_all('/<loc>(.*?)<\/loc>/s', $xml, $m);
        $maxDepth = (int) config('ai.crawler.sitemap_max_depth', 3);

        return array_values(array_filter(
            array_map('trim', $m[1] ?? []),
            function (string $url) use ($applyKeywordFilter, $templateName, $maxDepth): bool {
                if (str_contains($url, 'sitemap')) {
                    return false;
                }
                if (! $this->isIndexUrl($url)) {
                    return false;
                }
                if ($this->urlPathDepth($url) > $maxDepth) {
                    return false;
                }
                if ($applyKeywordFilter && $templateName !== '') {
                    return $this->matchesKeyword($url, $templateName);
                }

                return true;
            }
        ));
    }

    /**
     * URLがセクション入口（index.html または ディレクトリroot）かどうかを判定する。
     */
    private function isIndexUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        if (str_ends_with($path, '/')) {
            return true;
        }

        return preg_match('/^index\.html?$/i', basename($path)) === 1;
    }

    /**
     * URLのパスのセグメント数（深さ）を返す。
     * e.g. /kosodate/hoiku/shien/ → 3
     */
    private function urlPathDepth(string $url): int
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        $segments = array_filter(explode('/', $path), fn (string $s) => $s !== '');

        return count($segments);
    }

    /**
     * URLパスがテンプレートのキーワードにマッチするか判定する。
     */
    private function matchesKeyword(string $url, string $templateName): bool
    {
        $keywords = self::KEYWORDS[$templateName] ?? self::KEYWORDS['childcare'];
        $pattern = implode('|', $keywords);

        return (bool) preg_match("/({$pattern})/i", $url);
    }
}
