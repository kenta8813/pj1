<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

class FetchService
{
    /** @var array<string, array<int, string>> robots.txt キャッシュ [host => disallowed_paths] */
    private array $robotsCache = [];

    private int $timeout;

    private string $userAgent;

    private bool $respectRobots;

    public function __construct()
    {
        $this->timeout = config('ai.crawler.timeout', 30);
        $this->userAgent = config('ai.crawler.user_agent', 'Mozilla/5.0 (compatible; ChildcareBot/1.0)');
        $this->respectRobots = (bool) config('ai.crawler.respect_robots', true);
    }

    /**
     * 指定URLのHTMLを取得する。
     *
     * @throws RequestException
     */
    public function fetch(string $url): string
    {
        $response = Http::withHeaders(['User-Agent' => $this->userAgent])
            ->timeout($this->timeout)
            ->get($url);

        $response->throw();

        return $this->normalizeCharset($response->body(), $response->header('Content-Type') ?? '');
    }

    /**
     * HTMLから同一ドメインのリンクを絶対URL形式で抽出する。
     *
     * @return array<int, string>
     */
    public function extractLinks(string $html, string $baseUrl): array
    {
        $parsed = parse_url($baseUrl);
        $origin = ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? '');

        $crawler = new Crawler($html, $baseUrl);
        $links = [];

        $crawler->filter('a[href]')->each(function (Crawler $node) use ($origin, $baseUrl, &$links): void {
            $href = $node->attr('href');

            if ($href === null || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                return;
            }

            $absolute = $this->resolveUrl($href, $baseUrl);

            if ($absolute !== null && str_starts_with($absolute, $origin)) {
                $links[] = $this->normalizeFragment($absolute);
            }
        });

        return array_values(array_unique($links));
    }

    /**
     * robots.txt の Disallow に該当しないか確認する。
     */
    public function isAllowedByRobots(string $url): bool
    {
        if (! $this->respectRobots) {
            return true;
        }

        $parsed = parse_url($url);
        $host = ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? '');
        $path = $parsed['path'] ?? '/';

        if (! isset($this->robotsCache[$host])) {
            $this->robotsCache[$host] = $this->fetchDisallowedPaths($host);
        }

        foreach ($this->robotsCache[$host] as $disallowed) {
            if ($disallowed !== '' && str_starts_with($path, $disallowed)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function fetchDisallowedPaths(string $host): array
    {
        try {
            $response = Http::timeout(10)->get("{$host}/robots.txt");

            if ($response->failed()) {
                return [];
            }

            return $this->parseRobotsTxt($response->body());
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    private function parseRobotsTxt(string $content): array
    {
        $disallowed = [];
        $isTargetAgent = false;

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);

            if (stripos($line, 'User-agent: *') === 0) {
                $isTargetAgent = true;
            } elseif (stripos($line, 'User-agent:') === 0) {
                $isTargetAgent = false;
            } elseif ($isTargetAgent && stripos($line, 'Disallow:') === 0) {
                $path = trim(substr($line, strlen('Disallow:')));

                if ($path !== '') {
                    $disallowed[] = $path;
                }
            }
        }

        return $disallowed;
    }

    private function resolveUrl(string $href, string $baseUrl): ?string
    {
        if (preg_match('#^https?://#', $href)) {
            return $href;
        }

        $base = parse_url($baseUrl);

        if ($base === false) {
            return null;
        }

        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';

        if (str_starts_with($href, '//')) {
            return "{$scheme}:{$href}";
        }

        if (str_starts_with($href, '/')) {
            return "{$scheme}://{$host}{$href}";
        }

        $basePath = isset($base['path']) ? dirname($base['path']).'/' : '/';

        return "{$scheme}://{$host}{$basePath}{$href}";
    }

    private function normalizeFragment(string $url): string
    {
        return strtok($url, '#') ?: $url;
    }

    /**
     * Content-Type ヘッダーの charset に応じて UTF-8 に変換する（Shift-JIS 等の対応）。
     */
    private function normalizeCharset(string $body, string $contentType): string
    {
        if (preg_match('/charset=([^\s;]+)/i', $contentType, $matches)) {
            $charset = strtoupper(trim($matches[1], '"\''));

            if ($charset !== 'UTF-8' && $charset !== 'UTF8') {
                $converted = mb_convert_encoding($body, 'UTF-8', $charset);

                return $converted !== false ? $converted : $body;
            }
        }

        return $body;
    }
}
