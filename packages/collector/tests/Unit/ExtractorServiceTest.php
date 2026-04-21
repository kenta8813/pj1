<?php

namespace Tests\Unit;

use App\Ai\ChildcareExtractorAgent;
use App\Ai\GrantsExtractorAgent;
use App\Services\ExtractorService;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Tests\TestCase;

class ExtractorServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->template = [
            'title' => '',
            'category' => '',
            'target' => '',
            'summary' => '',
            'eligibility' => '',
            'application_method' => '',
            'contact' => '',
            'url' => '',
            'municipality' => '',
            'updated_at' => '',
        ];
    }

    private function makeAgentResponse(string $text): AgentResponse
    {
        return new AgentResponse('inv-id', $text, new Usage, new Meta);
    }

    private function makeService(string $agentResponse): ExtractorService
    {
        $agent = $this->createMock(ChildcareExtractorAgent::class);
        $agent->method('prompt')->willReturn($this->makeAgentResponse($agentResponse));

        return new ExtractorService($agent, $this->createMock(GrantsExtractorAgent::class));
    }

    public function test_extract_returns_filled_array_on_success(): void
    {
        $json = json_encode([
            'title' => '保育園入園案内',
            'category' => '保育園',
            'target' => '0〜5歳児',
            'contact' => '子育て課 0120-000-000',
            'municipality' => '東京都渋谷区',
            'url' => 'https://example.com/hoiku',
        ], JSON_UNESCAPED_UNICODE);

        $service = $this->makeService($json);
        $result = $service->extract('<html>保育園情報</html>', 'https://example.com/hoiku', $this->template);

        $this->assertSame('保育園入園案内', $result['title']);
        $this->assertSame('東京都渋谷区', $result['municipality']);
    }

    public function test_extract_returns_empty_array_when_agent_throws(): void
    {
        $agent = $this->createMock(ChildcareExtractorAgent::class);
        $agent->method('prompt')->willThrowException(new \RuntimeException('API error'));

        $service = new ExtractorService($agent, $this->createMock(GrantsExtractorAgent::class));
        $result = $service->extract('<html></html>', 'https://example.com/', $this->template);

        $this->assertEmpty($result);
    }

    public function test_extract_returns_empty_array_when_response_is_not_json(): void
    {
        $service = $this->makeService('これはJSONではありません');
        $result = $service->extract('<html></html>', 'https://example.com/', $this->template);

        $this->assertEmpty($result);
    }

    public function test_missing_template_keys_are_filled_with_empty_string(): void
    {
        $json = json_encode(['title' => '子育て支援', 'target' => '保護者', 'contact' => '子育て課'], JSON_UNESCAPED_UNICODE);
        $service = $this->makeService($json);
        $result = $service->extract('<html></html>', 'https://example.com/', $this->template);

        $this->assertSame('', $result['category']);
        $this->assertArrayNotHasKey('extra_key', $result);
    }

    public function test_extra_keys_from_llm_are_stripped(): void
    {
        $json = json_encode(['title' => 'test', 'target' => '全市民', 'contact' => '市役所', 'unknown_key' => 'value'], JSON_UNESCAPED_UNICODE);
        $service = $this->makeService($json);
        $result = $service->extract('<html></html>', 'https://example.com/', $this->template);

        $this->assertArrayNotHasKey('unknown_key', $result);
    }

    public function test_json_code_block_wrapper_is_stripped(): void
    {
        $json = "```json\n{\"title\": \"保育園\", \"target\": \"0〜5歳\", \"contact\": \"保育課\"}\n```";
        $service = $this->makeService($json);
        $result = $service->extract('<html></html>', 'https://example.com/', $this->template);

        $this->assertSame('保育園', $result['title']);
    }

    public function test_extract_returns_empty_when_title_is_empty(): void
    {
        $json = json_encode(['title' => '', 'target' => '保護者', 'contact' => '子育て課'], JSON_UNESCAPED_UNICODE);
        $service = $this->makeService($json);
        $result = $service->extract('<html></html>', 'https://example.com/', $this->template);

        $this->assertEmpty($result);
    }

    public function test_extract_returns_empty_for_noise_page_with_insufficient_fields(): void
    {
        // title のみで target/summary/eligibility/application_method/contact が全空 → ノイズ
        $json = json_encode(['title' => '高岡市役所・窓口案内'], JSON_UNESCAPED_UNICODE);
        $service = $this->makeService($json);
        $result = $service->extract('<html></html>', 'https://example.com/', $this->template);

        $this->assertEmpty($result);
    }

    public function test_extract_passes_when_two_meaningful_fields_are_filled(): void
    {
        $json = json_encode(['title' => '児童手当', 'target' => '中学生以下の子を持つ保護者', 'contact' => '子育て支援課'], JSON_UNESCAPED_UNICODE);
        $service = $this->makeService($json);
        $result = $service->extract('<html></html>', 'https://example.com/', $this->template);

        $this->assertSame('児童手当', $result['title']);
    }

    public function test_clean_html_removes_script_tags(): void
    {
        $agent = $this->createMock(ChildcareExtractorAgent::class);
        $service = new ExtractorService($agent, $this->createMock(GrantsExtractorAgent::class));
        $html = '<html><body><script>alert("xss")</script><p>本文</p></body></html>';

        $result = $service->cleanHtml($html);

        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringContainsString('本文', $result);
    }

    public function test_clean_html_removes_style_tags(): void
    {
        $agent = $this->createMock(ChildcareExtractorAgent::class);
        $service = new ExtractorService($agent, $this->createMock(GrantsExtractorAgent::class));
        $html = '<html><body><style>.foo{color:red}</style><p>内容</p></body></html>';

        $result = $service->cleanHtml($html);

        $this->assertStringNotContainsString('.foo', $result);
        $this->assertStringContainsString('内容', $result);
    }

    public function test_clean_html_removes_nav_and_footer(): void
    {
        $agent = $this->createMock(ChildcareExtractorAgent::class);
        $service = new ExtractorService($agent, $this->createMock(GrantsExtractorAgent::class));
        $html = '<html><body><nav>ナビ</nav><main>本文</main><footer>フッター</footer></body></html>';

        $result = $service->cleanHtml($html);

        $this->assertStringNotContainsString('ナビ', $result);
        $this->assertStringNotContainsString('フッター', $result);
        $this->assertStringContainsString('本文', $result);
    }
}
