<?php

namespace Tests\Unit;

use App\Services\FetchService;
use App\Services\SitemapService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SitemapServiceTest extends TestCase
{
    private function makeService(): SitemapService
    {
        config(['ai.crawler.rate_limit_ms' => 0, 'ai.crawler.respect_robots' => false]);

        return new SitemapService(new FetchService);
    }

    private function sitemapIndexXml(array $locs): string
    {
        $items = implode('', array_map(fn ($l) => "<sitemap><loc>{$l}</loc></sitemap>", $locs));

        return "<?xml version=\"1.0\"?><sitemapindex>{$items}</sitemapindex>";
    }

    private function sitemapXml(array $locs): string
    {
        $items = implode('', array_map(fn ($l) => "<url><loc>{$l}</loc></url>", $locs));

        return "<?xml version=\"1.0\"?><urlset>{$items}</urlset>";
    }

    public function test_discover_urls_returns_only_index_pages_from_direct_sitemap(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapXml([
                    'https://example.com/hoiku/index.html',
                    'https://example.com/kosodate/',
                    'https://example.com/hoiku/6897.html',
                ]),
                200
            ),
        ]);

        $urls = $this->makeService()->discoverUrls('https://example.com/some/page');

        $this->assertCount(2, $urls);
        $this->assertContains('https://example.com/hoiku/index.html', $urls);
        $this->assertContains('https://example.com/kosodate/', $urls);
        $this->assertNotContains('https://example.com/hoiku/6897.html', $urls);
    }

    public function test_discover_urls_excludes_leaf_pages(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapXml([
                    'https://example.com/kosodate/index.html',
                    'https://example.com/kosodate/1/10597.html',
                    'https://example.com/kosodate/news/6700.html',
                ]),
                200
            ),
        ]);

        $urls = $this->makeService()->discoverUrls('https://example.com/');

        $this->assertCount(1, $urls);
        $this->assertSame('https://example.com/kosodate/index.html', $urls[0]);
    }

    public function test_discover_urls_filters_sitemap_self_references(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapXml([
                    'https://example.com/kosodate/index.html',
                    'https://example.com/sitemap2.xml',
                ]),
                200
            ),
        ]);

        $urls = $this->makeService()->discoverUrls('https://example.com/');

        $this->assertCount(1, $urls);
        $this->assertContains('https://example.com/kosodate/index.html', $urls);
    }

    public function test_discover_urls_processes_sitemap_index_with_keyword_filter(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapIndexXml([
                    'https://example.com/sitemap-kosodate.xml',
                    'https://example.com/sitemap-tourism.xml',
                ]),
                200
            ),
            'https://example.com/sitemap-kosodate.xml' => Http::response(
                $this->sitemapXml(['https://example.com/kosodate/index.html']),
                200
            ),
        ]);

        $urls = $this->makeService()->discoverUrls('https://example.com/', 'childcare');

        $this->assertCount(1, $urls);
        $this->assertContains('https://example.com/kosodate/index.html', $urls);
    }

    public function test_discover_urls_returns_empty_on_404(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response('', 404),
        ]);

        $urls = $this->makeService()->discoverUrls('https://example.com/');

        $this->assertEmpty($urls);
    }

    public function test_discover_urls_returns_empty_on_network_error(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response('Error', 500),
        ]);

        $urls = $this->makeService()->discoverUrls('https://example.com/');

        $this->assertEmpty($urls);
    }

    public function test_discover_urls_deduplicates_pages(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapIndexXml([
                    'https://example.com/sitemap-kosodate.xml',
                    'https://example.com/sitemap-kodomo.xml',
                ]),
                200
            ),
            'https://example.com/sitemap-kosodate.xml' => Http::response(
                $this->sitemapXml(['https://example.com/shared/index.html']),
                200
            ),
            'https://example.com/sitemap-kodomo.xml' => Http::response(
                $this->sitemapXml(['https://example.com/shared/index.html']),
                200
            ),
        ]);

        $urls = $this->makeService()->discoverUrls('https://example.com/', 'childcare');

        $this->assertCount(1, $urls);
    }

    public function test_discover_urls_grants_template_keyword_filter(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapIndexXml([
                    'https://example.com/sitemap-teate.xml',
                    'https://example.com/sitemap-tourism.xml',
                ]),
                200
            ),
            'https://example.com/sitemap-teate.xml' => Http::response(
                $this->sitemapXml(['https://example.com/teate/index.html']),
                200
            ),
        ]);

        $urls = $this->makeService()->discoverUrls('https://example.com/', 'grants');

        $this->assertCount(1, $urls);
        $this->assertContains('https://example.com/teate/index.html', $urls);
    }

    public function test_discover_urls_builds_sitemap_url_from_entry_path(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapXml(['https://example.com/kosodate/index.html']),
                200
            ),
        ]);

        $urls = $this->makeService()->discoverUrls('https://example.com/deep/path/page.html');

        $this->assertNotEmpty($urls);
        Http::assertSent(fn ($req) => $req->url() === 'https://example.com/sitemap.xml');
    }

    public function test_discover_urls_excludes_urls_exceeding_max_depth(): void
    {
        config(['ai.crawler.sitemap_max_depth' => 3]);

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapXml([
                    'https://example.com/kosodate/',
                    'https://example.com/kosodate/hoiku/',
                    'https://example.com/kosodate/hoiku/shien/',
                    'https://example.com/kosodate/hoiku/shien/ninsho/', // 深さ4 → 除外
                ]),
                200
            ),
        ]);

        $urls = $this->makeService()->discoverUrls('https://example.com/', 'childcare');

        $this->assertCount(3, $urls);
        $this->assertNotContains('https://example.com/kosodate/hoiku/shien/ninsho/', $urls);
    }

    public function test_discover_urls_applies_keyword_filter_to_url_paths_in_direct_sitemap(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapXml([
                    'https://example.com/kosodate/',
                    'https://example.com/tourism/',         // キーワード不一致 → 除外
                    'https://example.com/kanko/index.html', // キーワード不一致 → 除外
                ]),
                200
            ),
        ]);

        $urls = $this->makeService()->discoverUrls('https://example.com/', 'childcare');

        $this->assertCount(1, $urls);
        $this->assertContains('https://example.com/kosodate/', $urls);
        $this->assertNotContains('https://example.com/tourism/', $urls);
    }

    public function test_discover_urls_caps_result_at_sitemap_max_urls(): void
    {
        config(['ai.crawler.sitemap_max_urls' => 2]);

        $allUrls = array_map(fn (int $i) => "https://example.com/kosodate/s{$i}/", range(1, 10));

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapXml($allUrls),
                200
            ),
        ]);

        $urls = $this->makeService()->discoverUrls('https://example.com/', 'childcare');

        $this->assertCount(2, $urls);
    }

    public function test_discover_urls_prefers_shallowest_urls_when_capping(): void
    {
        config(['ai.crawler.sitemap_max_urls' => 2]);

        Http::fake([
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapXml([
                    'https://example.com/kosodate/hoiku/shien/', // 深さ3 → カット
                    'https://example.com/kosodate/',             // 深さ1 → 残す
                    'https://example.com/kosodate/hoiku/',       // 深さ2 → 残す
                ]),
                200
            ),
        ]);

        $urls = $this->makeService()->discoverUrls('https://example.com/', 'childcare');

        $this->assertCount(2, $urls);
        $this->assertContains('https://example.com/kosodate/', $urls);
        $this->assertContains('https://example.com/kosodate/hoiku/', $urls);
        $this->assertNotContains('https://example.com/kosodate/hoiku/shien/', $urls);
    }
}
