<?php

namespace Tests\Feature;

use App\Ai\ChildcareExtractorAgent;
use App\Ai\GrantsExtractorAgent;
use App\Ai\LinkFilterAgent;
use App\Jobs\ExploreJob;
use App\Services\DataStoreService;
use App\Services\ExtractorService;
use App\Services\FetchService;
use App\Services\SiteExplorerService;
use App\Services\SitemapService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Tests\TestCase;

class ExploreJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.crawler.rate_limit_ms' => 0, 'ai.crawler.respect_robots' => false]);
        Storage::fake('data');
    }

    private function bindMockedExplorer(array $extractedData, array $filteredLinks = []): void
    {
        $extractorAgent = $this->createMock(ChildcareExtractorAgent::class);
        $extractorAgent->method('prompt')->willReturn(
            new AgentResponse('id', json_encode($extractedData, JSON_UNESCAPED_UNICODE), new Usage, new Meta)
        );

        $linkFilterAgent = $this->createMock(LinkFilterAgent::class);
        $linkFilterAgent->method('prompt')->willReturn(
            new AgentResponse('id', json_encode($filteredLinks, JSON_UNESCAPED_UNICODE), new Usage, new Meta)
        );

        $sitemapMock = $this->createMock(SitemapService::class);
        $sitemapMock->method('discoverUrls')->willReturn([]);

        $this->app->instance(SiteExplorerService::class, new SiteExplorerService(
            new FetchService,
            $linkFilterAgent,
            new ExtractorService($extractorAgent, $this->createMock(GrantsExtractorAgent::class)),
            new DataStoreService,
            $sitemapMock,
        ));
    }

    public function test_job_saves_data_on_successful_exploration(): void
    {
        Http::fake([
            'https://example.com/' => Http::response('<html><body>保育園情報</body></html>', 200),
        ]);

        $this->bindMockedExplorer(['title' => '保育園', 'target' => '0〜5歳', 'contact' => '子育て課', 'url' => 'https://example.com/']);

        $job = new ExploreJob('https://example.com/', 'childcare', maxDepth: 0);
        $job->handle(app(SiteExplorerService::class));

        Storage::disk('data')->assertExists('example-com/index.json');
    }

    public function test_job_handles_missing_template_gracefully(): void
    {
        Log::shouldReceive('error')->once();

        $explorer = $this->createMock(SiteExplorerService::class);
        $explorer->expects($this->never())->method('explore');

        $job = new ExploreJob('https://example.com/', 'nonexistent_template');
        $job->handle($explorer);
    }

    public function test_job_dry_run_does_not_save_files(): void
    {
        Http::fake([
            'https://example.com/' => Http::response('<html><body>内容</body></html>', 200),
        ]);

        $this->bindMockedExplorer(['title' => '子育て', 'target' => '保護者', 'contact' => '子育て課', 'url' => 'https://example.com/']);

        $job = new ExploreJob('https://example.com/', 'childcare', maxDepth: 0, dryRun: true);
        $job->handle(app(SiteExplorerService::class));

        Storage::disk('data')->assertMissing('example-com/index.json');
    }

    public function test_job_can_be_dispatched_to_queue(): void
    {
        Queue::fake();

        ExploreJob::dispatch('https://example.com/', 'childcare');

        Queue::assertPushed(ExploreJob::class, function (ExploreJob $job) {
            return $job->entryUrl === 'https://example.com/' && $job->templateName === 'childcare';
        });
    }

    public function test_job_has_correct_timeout_and_tries(): void
    {
        $job = new ExploreJob('https://example.com/');

        $this->assertSame(1, $job->tries);
        $this->assertSame(3600, $job->timeout);
    }
}
