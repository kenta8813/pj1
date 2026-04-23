<?php

namespace Tests\Unit;

use App\Ai\ChildcareExtractorAgent;
use App\Ai\FactCheckerAgent;
use App\Ai\GrantsExtractorAgent;
use App\Services\DataStoreService;
use App\Services\ExtractorService;
use App\Services\FactCheckService;
use App\Services\FetchService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Tests\TestCase;

class FactCheckServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.crawler.rate_limit_ms' => 0, 'ai.crawler.respect_robots' => false]);
        Storage::fake('data');
    }

    private function makeAgentResponse(string $json): AgentResponse
    {
        return new AgentResponse('inv-id', $json, new Usage, new Meta);
    }

    private function makeService(string $agentResponse): FactCheckService
    {
        $agent = $this->createMock(FactCheckerAgent::class);
        $agent->method('prompt')->willReturn($this->makeAgentResponse($agentResponse));

        $extractor = $this->createMock(ChildcareExtractorAgent::class);

        return new FactCheckService(
            new FetchService,
            $agent,
            new DataStoreService,
            new ExtractorService($extractor, $this->createMock(GrantsExtractorAgent::class)),
        );
    }

    public function test_check_returns_high_confidence_on_matching_page(): void
    {
        Http::fake([
            'https://example.com/hoiku' => Http::response('<html><body>保育園入園案内</body></html>', 200),
        ]);

        $response = json_encode(['confidence' => 'high', 'issues' => []], JSON_UNESCAPED_UNICODE);
        $service = $this->makeService($response);

        $data = ['url' => 'https://example.com/hoiku', 'title' => '保育園入園案内', '_fc_confidence' => '', '_fc_issues' => [], '_fc_checked_at' => ''];
        $result = $service->check($data, dryRun: true);

        $this->assertSame('high', $result['confidence']);
        $this->assertEmpty($result['issues']);
    }

    public function test_check_returns_low_confidence_when_page_fetch_fails(): void
    {
        Http::fake([
            'https://example.com/missing' => Http::response('', 404),
        ]);

        $agent = $this->createMock(FactCheckerAgent::class);
        $agent->expects($this->never())->method('prompt');
        $extractor = $this->createMock(ChildcareExtractorAgent::class);

        $service = new FactCheckService(new FetchService, $agent, new DataStoreService, new ExtractorService($extractor, $this->createMock(GrantsExtractorAgent::class)));
        $data = ['url' => 'https://example.com/missing', 'title' => 'テスト', '_fc_confidence' => '', '_fc_issues' => [], '_fc_checked_at' => ''];
        $result = $service->check($data, dryRun: true);

        $this->assertSame('low', $result['confidence']);
        $this->assertNotEmpty($result['issues']);
    }

    public function test_check_writes_fc_fields_to_stored_data(): void
    {
        Http::fake([
            'https://example.com/page' => Http::response('<html><body>内容</body></html>', 200),
        ]);

        $response = json_encode(['confidence' => 'medium', 'issues' => ['contact: 古い可能性']], JSON_UNESCAPED_UNICODE);
        $service = $this->makeService($response);

        $data = ['url' => 'https://example.com/page', 'title' => 'テスト', '_fc_confidence' => '', '_fc_issues' => [], '_fc_checked_at' => ''];
        $service->check($data, dryRun: false);

        $stored = (new DataStoreService)->findByUrl('https://example.com/page');

        $this->assertNotNull($stored);
        $this->assertSame('medium', $stored['_fc_confidence']);
        $this->assertContains('contact: 古い可能性', $stored['_fc_issues']);
        $this->assertNotEmpty($stored['_fc_checked_at']);
    }

    public function test_check_dry_run_does_not_write_to_store(): void
    {
        Http::fake([
            'https://example.com/dry' => Http::response('<html><body>内容</body></html>', 200),
        ]);

        $response = json_encode(['confidence' => 'high', 'issues' => []], JSON_UNESCAPED_UNICODE);
        $service = $this->makeService($response);

        $data = ['url' => 'https://example.com/dry', 'title' => 'ドライラン', '_fc_confidence' => '', '_fc_issues' => [], '_fc_checked_at' => ''];
        $service->check($data, dryRun: true);

        $this->assertNull((new DataStoreService)->findByUrl('https://example.com/dry'));
    }

    public function test_check_all_returns_correct_counts(): void
    {
        Http::fake([
            'https://example.com/p1' => Http::response('<html><body>1</body></html>', 200),
            'https://example.com/p2' => Http::response('<html><body>2</body></html>', 200),
        ]);

        $store = new DataStoreService;
        $store->save(['url' => 'https://example.com/p1', 'title' => 'P1', '_fc_confidence' => '', '_fc_issues' => [], '_fc_checked_at' => '']);
        $store->save(['url' => 'https://example.com/p2', 'title' => 'P2', '_fc_confidence' => '', '_fc_issues' => [], '_fc_checked_at' => '']);

        $agentMock = $this->createMock(FactCheckerAgent::class);
        $agentMock->method('prompt')->willReturn($this->makeAgentResponse(json_encode(['confidence' => 'high', 'issues' => []])));

        $extractor = $this->createMock(ChildcareExtractorAgent::class);
        $service = new FactCheckService(new FetchService, $agentMock, $store, new ExtractorService($extractor, $this->createMock(GrantsExtractorAgent::class)));

        $counts = $service->checkAll(dryRun: true);

        $this->assertSame(2, $counts['checked']);
        $this->assertSame(2, $counts['high']);
        $this->assertSame(0, $counts['low']);
    }

    public function test_check_all_filters_by_confidence(): void
    {
        Http::fake([
            'https://example.com/low-page' => Http::response('<html><body>低信頼</body></html>', 200),
        ]);

        $store = new DataStoreService;
        $store->save(['url' => 'https://example.com/high-page', 'title' => 'High', '_fc_confidence' => 'high', '_fc_issues' => [], '_fc_checked_at' => '2025-01-01']);
        $store->save(['url' => 'https://example.com/low-page', 'title' => 'Low', '_fc_confidence' => 'low', '_fc_issues' => [], '_fc_checked_at' => '2025-01-01']);

        $agentMock = $this->createMock(FactCheckerAgent::class);
        $agentMock->method('prompt')->willReturn($this->makeAgentResponse(json_encode(['confidence' => 'high', 'issues' => []])));

        $extractor = $this->createMock(ChildcareExtractorAgent::class);
        $service = new FactCheckService(new FetchService, $agentMock, $store, new ExtractorService($extractor, $this->createMock(GrantsExtractorAgent::class)));

        $counts = $service->checkAll(dryRun: true, confidence: 'low');

        $this->assertSame(1, $counts['checked']);
    }
}
