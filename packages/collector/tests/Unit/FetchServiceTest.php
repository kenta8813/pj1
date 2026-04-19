<?php

namespace Tests\Unit;

use App\Services\FetchService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchServiceTest extends TestCase
{
    private FetchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.crawler.rate_limit_ms' => 0, 'ai.crawler.respect_robots' => false]);
        $this->service = new FetchService;
    }

    public function test_fetch_returns_html_body(): void
    {
        Http::fake(['https://example.com' => Http::response('<html><body>Hello</body></html>', 200)]);

        $html = $this->service->fetch('https://example.com');

        $this->assertStringContainsString('Hello', $html);
    }

    public function test_fetch_throws_on_http_error(): void
    {
        Http::fake(['https://example.com/missing' => Http::response('', 404)]);

        $this->expectException(RequestException::class);

        $this->service->fetch('https://example.com/missing');
    }

    public function test_extract_links_returns_same_origin_absolute_urls(): void
    {
        $html = '<a href="/kosodate/">子育て</a><a href="https://example.com/hoiku">保育</a>';

        $links = $this->service->extractLinks($html, 'https://example.com/');

        $this->assertContains('https://example.com/kosodate/', $links);
        $this->assertContains('https://example.com/hoiku', $links);
    }

    public function test_extract_links_skips_external_domains(): void
    {
        $html = '<a href="https://external.com/page">外部</a>';

        $links = $this->service->extractLinks($html, 'https://example.com/');

        $this->assertEmpty($links);
    }

    public function test_extract_links_skips_fragment_links(): void
    {
        $html = '<a href="#section">セクション</a>';

        $links = $this->service->extractLinks($html, 'https://example.com/');

        $this->assertEmpty($links);
    }

    public function test_extract_links_skips_mailto_links(): void
    {
        $html = '<a href="mailto:info@example.com">メール</a>';

        $links = $this->service->extractLinks($html, 'https://example.com/');

        $this->assertEmpty($links);
    }

    public function test_extract_links_resolves_root_relative_paths(): void
    {
        $html = '<a href="/about">About</a>';

        $links = $this->service->extractLinks($html, 'https://example.com/some/path/');

        $this->assertContains('https://example.com/about', $links);
    }

    public function test_extract_links_deduplicates_urls(): void
    {
        $html = '<a href="/page">1</a><a href="/page">2</a>';

        $links = $this->service->extractLinks($html, 'https://example.com/');

        $this->assertCount(1, $links);
    }

    public function test_extract_links_strips_fragment_from_url(): void
    {
        $html = '<a href="/page#section">リンク</a>';

        $links = $this->service->extractLinks($html, 'https://example.com/');

        $this->assertContains('https://example.com/page', $links);
    }

    public function test_is_allowed_by_robots_returns_true_when_not_disallowed(): void
    {
        Http::fake(['https://example.com/robots.txt' => Http::response("User-agent: *\nDisallow: /admin/", 200)]);

        config(['ai.crawler.respect_robots' => true]);
        $service = new FetchService;

        $this->assertTrue($service->isAllowedByRobots('https://example.com/kosodate/'));
    }

    public function test_is_allowed_by_robots_returns_false_for_disallowed_path(): void
    {
        Http::fake(['https://example.com/robots.txt' => Http::response("User-agent: *\nDisallow: /admin/", 200)]);

        config(['ai.crawler.respect_robots' => true]);
        $service = new FetchService;

        $this->assertFalse($service->isAllowedByRobots('https://example.com/admin/secret'));
    }

    public function test_is_allowed_by_robots_returns_true_when_fetch_fails(): void
    {
        Http::fake(['https://example.com/robots.txt' => Http::response('', 404)]);

        config(['ai.crawler.respect_robots' => true]);
        $service = new FetchService;

        $this->assertTrue($service->isAllowedByRobots('https://example.com/any/path'));
    }

    public function test_is_allowed_by_robots_skipped_when_disabled(): void
    {
        Http::fake();

        config(['ai.crawler.respect_robots' => false]);
        $service = new FetchService;

        $this->assertTrue($service->isAllowedByRobots('https://example.com/admin/'));

        Http::assertNothingSent();
    }

    public function test_robots_cache_is_reused_per_host(): void
    {
        Http::fake(['https://example.com/robots.txt' => Http::response("User-agent: *\nDisallow: /admin/", 200)]);

        config(['ai.crawler.respect_robots' => true]);
        $service = new FetchService;

        $service->isAllowedByRobots('https://example.com/page1');
        $service->isAllowedByRobots('https://example.com/page2');

        Http::assertSentCount(1);
    }
}
