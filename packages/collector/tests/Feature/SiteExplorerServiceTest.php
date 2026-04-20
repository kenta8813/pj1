<?php

namespace Tests\Feature;

use App\Ai\ChildcareExtractorAgent;
use App\Ai\GrantsExtractorAgent;
use App\Ai\LinkFilterAgent;
use App\Services\DataStoreService;
use App\Services\ExtractorService;
use App\Services\FetchService;
use App\Services\SiteExplorerService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Tests\TestCase;

class SiteExplorerServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.crawler.rate_limit_ms' => 0, 'ai.crawler.respect_robots' => false]);
        Storage::fake('data');
    }

    /** @param  array<string, mixed>  $data */
    private function makeExtractorAgent(array $data): ChildcareExtractorAgent
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $agent = $this->createMock(ChildcareExtractorAgent::class);
        $agent->method('prompt')->willReturn(new AgentResponse('id', $json, new Usage, new Meta));

        return $agent;
    }

    private function makeLinkFilterAgent(array $urls): LinkFilterAgent
    {
        $json = json_encode($urls, JSON_UNESCAPED_UNICODE);
        $agent = $this->createMock(LinkFilterAgent::class);
        $agent->method('prompt')->willReturn(new AgentResponse('id', $json, new Usage, new Meta));

        return $agent;
    }

    private function makeService(
        ChildcareExtractorAgent $extractor,
        LinkFilterAgent $linkFilter,
    ): SiteExplorerService {
        return new SiteExplorerService(
            new FetchService,
            $linkFilter,
            new ExtractorService($extractor, $this->createMock(GrantsExtractorAgent::class)),
            new DataStoreService,
        );
    }

    private function template(): array
    {
        return [
            'title' => '', 'category' => '', 'target' => '', 'summary' => '',
            'eligibility' => '', 'application_method' => '', 'contact' => '',
            'url' => '', 'municipality' => '', 'updated_at' => '',
        ];
    }

    public function test_explore_saves_entry_page_with_valid_title(): void
    {
        Http::fake([
            'https://example.com/' => Http::response('<html><body><main>保育園情報</main></body></html>', 200),
        ]);

        $extractor = $this->makeExtractorAgent(['title' => '保育園案内', 'url' => 'https://example.com/']);
        $linkFilter = $this->makeLinkFilterAgent([]);

        $service = $this->makeService($extractor, $linkFilter);
        $saved = $service->explore('https://example.com/', $this->template(), maxDepth: 0);

        $this->assertSame(1, $saved);
        Storage::disk('data')->assertExists('example-com/index.json');
    }

    public function test_explore_does_not_save_when_title_is_empty(): void
    {
        Http::fake([
            'https://example.com/' => Http::response('<html><nav>ナビ</nav></html>', 200),
        ]);

        $extractor = $this->makeExtractorAgent(['title' => '', 'url' => 'https://example.com/']);
        $linkFilter = $this->makeLinkFilterAgent([]);

        $service = $this->makeService($extractor, $linkFilter);
        $saved = $service->explore('https://example.com/', $this->template(), maxDepth: 0);

        $this->assertSame(0, $saved);
    }

    public function test_explore_follows_relevant_links_up_to_max_depth(): void
    {
        Http::fake([
            'https://example.com/' => Http::response('<html><body><a href="/hoiku">保育</a></body></html>', 200),
            'https://example.com/hoiku' => Http::response('<html><body>保育園情報</body></html>', 200),
        ]);

        $extractorMock = $this->createMock(ChildcareExtractorAgent::class);
        $extractorMock->method('prompt')->willReturnCallback(function ($prompt) {
            if (str_contains((string) $prompt, '/hoiku')) {
                return new AgentResponse('id', json_encode(['title' => '保育園', 'url' => 'https://example.com/hoiku'], JSON_UNESCAPED_UNICODE), new Usage, new Meta);
            }

            return new AgentResponse('id', json_encode(['title' => '', 'url' => 'https://example.com/'], JSON_UNESCAPED_UNICODE), new Usage, new Meta);
        });

        $linkFilter = $this->makeLinkFilterAgent(['https://example.com/hoiku']);
        $service = $this->makeService($extractorMock, $linkFilter);
        $saved = $service->explore('https://example.com/', $this->template(), maxDepth: 1);

        $this->assertSame(1, $saved);
    }

    public function test_explore_respects_max_depth_limit(): void
    {
        Http::fake([
            'https://example.com/' => Http::response('<html><a href="/level1">L1</a></html>', 200),
            'https://example.com/level1' => Http::response('<html><a href="/level2">L2</a></html>', 200),
        ]);

        $extractorMock = $this->createMock(ChildcareExtractorAgent::class);
        $extractorMock->method('prompt')->willReturn(
            new AgentResponse('id', json_encode(['title' => 'ページ', 'url' => 'https://example.com/'], JSON_UNESCAPED_UNICODE), new Usage, new Meta)
        );

        $linkFilterMock = $this->createMock(LinkFilterAgent::class);
        $callCount = 0;
        $linkFilterMock->method('prompt')->willReturnCallback(function () use (&$callCount) {
            $callCount++;

            return new AgentResponse('id', json_encode(['https://example.com/level1'], JSON_UNESCAPED_UNICODE), new Usage, new Meta);
        });

        $service = $this->makeService($extractorMock, $linkFilterMock);
        $service->explore('https://example.com/', $this->template(), maxDepth: 1);

        // Only the entry page triggers link filter (depth=0 < maxDepth=1)
        // level1 is at depth=1 which equals maxDepth so it does NOT trigger link filter
        $this->assertSame(1, $callCount);
    }

    public function test_explore_respects_max_pages_limit(): void
    {
        Http::fake([
            'https://example.com/p1' => Http::response('<html><a href="/p2">2</a><a href="/p3">3</a></html>', 200),
            'https://example.com/p2' => Http::response('<html>P2</html>', 200),
            'https://example.com/p3' => Http::response('<html>P3</html>', 200),
        ]);

        $extractorMock = $this->createMock(ChildcareExtractorAgent::class);
        $extractorMock->method('prompt')->willReturn(
            new AgentResponse('id', json_encode(['title' => 'タイトル', 'url' => ''], JSON_UNESCAPED_UNICODE), new Usage, new Meta)
        );

        $linkFilter = $this->makeLinkFilterAgent(['https://example.com/p2', 'https://example.com/p3']);
        $service = $this->makeService($extractorMock, $linkFilter);
        $saved = $service->explore('https://example.com/p1', $this->template(), maxDepth: 1, maxPages: 1);

        $this->assertSame(1, $saved);
    }

    public function test_explore_skips_robots_disallowed_urls(): void
    {
        config(['ai.crawler.respect_robots' => true]);

        Http::fake([
            'https://example.com/robots.txt' => Http::response("User-agent: *\nDisallow: /admin/", 200),
            'https://example.com/admin/' => Http::response('<html>Admin</html>', 200),
        ]);

        $extractorMock = $this->createMock(ChildcareExtractorAgent::class);
        $extractorMock->expects($this->never())->method('prompt');

        $linkFilter = $this->makeLinkFilterAgent([]);
        $service = new SiteExplorerService(
            new FetchService,
            $linkFilter,
            new ExtractorService($extractorMock, $this->createMock(GrantsExtractorAgent::class)),
            new DataStoreService,
        );

        $saved = $service->explore('https://example.com/admin/', $this->template(), maxDepth: 0);
        $this->assertSame(0, $saved);
    }

    public function test_explore_skips_pages_that_fail_to_fetch(): void
    {
        Http::fake([
            'https://example.com/missing' => Http::response('', 404),
        ]);

        $extractorMock = $this->createMock(ChildcareExtractorAgent::class);
        $extractorMock->expects($this->never())->method('prompt');

        $linkFilter = $this->makeLinkFilterAgent([]);
        $service = $this->makeService($extractorMock, $linkFilter);
        $saved = $service->explore('https://example.com/missing', $this->template(), maxDepth: 0);

        $this->assertSame(0, $saved);
    }

    public function test_explore_dry_run_does_not_save_files(): void
    {
        Http::fake([
            'https://example.com/' => Http::response('<html><body>内容</body></html>', 200),
        ]);

        $extractor = $this->makeExtractorAgent(['title' => '子育て', 'url' => 'https://example.com/']);
        $linkFilter = $this->makeLinkFilterAgent([]);
        $service = $this->makeService($extractor, $linkFilter);

        $saved = $service->explore('https://example.com/', $this->template(), maxDepth: 0, dryRun: true);

        $this->assertSame(1, $saved);
        Storage::disk('data')->assertMissing('example-com/index.json');
    }

    public function test_explore_does_not_visit_same_url_twice(): void
    {
        Http::fake([
            'https://example.com/' => Http::response('<html><a href="/">self</a></html>', 200),
        ]);

        $extractorMock = $this->createMock(ChildcareExtractorAgent::class);
        $extractorMock->expects($this->once())->method('prompt')->willReturn(
            new AgentResponse('id', json_encode(['title' => '', 'url' => ''], JSON_UNESCAPED_UNICODE), new Usage, new Meta)
        );

        $linkFilter = $this->makeLinkFilterAgent(['https://example.com/']);
        $service = $this->makeService($extractorMock, $linkFilter);
        $service->explore('https://example.com/', $this->template(), maxDepth: 1);
    }
}
