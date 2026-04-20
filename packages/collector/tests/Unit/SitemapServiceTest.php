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

    public function test_discover_urls_returns_pages_from_direct_sitemap(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapXml([
                    'https://example.com/hoiku/page1',
                    'https://example.com/hoiku/page2',
                ]),
                200
            ),
        ]);

        $urls = $this->makeService()->discoverUrls('https://example.com/some/page');

        $this->assertCount(2, $urls);
        $this->assertContains('https://example.com/hoiku/page1', $urls);
        $this->assertContains('https://example.com/hoiku/page2', $urls);
    }

    public function test_discover_urls_filters_sitemap_self_references(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapXml([
                    'https://example.com/page1',
                    'https://example.com/sitemap2.xml',
                ]),
                200
            ),
        ]);

        $urls = $this->makeService()->discoverUrls('https://example.com/');

        $this->assertCount(1, $urls);
        $this->assertContains('https://example.com/page1', $urls);
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
                $this->sitemapXml(['https://example.com/kosodate/page1']),
                200
            ),
        ]);

        $urls = $this->makeService()->discoverUrls('https://example.com/', 'childcare');

        $this->assertCount(1, $urls);
        $this->assertContains('https://example.com/kosodate/page1', $urls);
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
                $this->sitemapXml(['https://example.com/shared/page']),
                200
            ),
            'https://example.com/sitemap-kodomo.xml' => Http::response(
                $this->sitemapXml(['https://example.com/shared/page']),
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
                $this->sitemapXml(['https://example.com/teate/page1']),
                200
            ),
        ]);

        $urls = $this->makeService()->discoverUrls('https://example.com/', 'grants');

        $this->assertCount(1, $urls);
        $this->assertContains('https://example.com/teate/page1', $urls);
    }

    public function test_discover_urls_builds_sitemap_url_from_entry_path(): void
    {
        Http::fake([
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapXml(['https://example.com/page']),
                200
            ),
        ]);

        $urls = $this->makeService()->discoverUrls('https://example.com/deep/path/page.html');

        $this->assertNotEmpty($urls);
        Http::assertSent(fn ($req) => $req->url() === 'https://example.com/sitemap.xml');
    }
}
