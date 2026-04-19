<?php

namespace Tests\Unit;

use App\Services\DataStoreService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DataStoreServiceTest extends TestCase
{
    private DataStoreService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
        $this->service = new DataStoreService;
    }

    public function test_save_creates_json_file(): void
    {
        $data = ['url' => 'https://example.com/hoiku', 'title' => '保育園案内', 'municipality' => '渋谷区'];

        $this->service->save($data);

        Storage::disk('data')->assertExists('example-com/hoiku.json');
    }

    public function test_save_overwrites_existing_file_for_same_url(): void
    {
        $data = ['url' => 'https://example.com/page', 'title' => '初回'];
        $updated = ['url' => 'https://example.com/page', 'title' => '更新'];

        $this->service->save($data);
        $this->service->save($updated);

        $result = $this->service->findByUrl('https://example.com/page');
        $this->assertSame('更新', $result['title']);
    }

    public function test_find_by_url_returns_data_for_existing_url(): void
    {
        $data = ['url' => 'https://example.com/kosodate', 'title' => '子育て支援'];
        $this->service->save($data);

        $result = $this->service->findByUrl('https://example.com/kosodate');

        $this->assertNotNull($result);
        $this->assertSame('子育て支援', $result['title']);
    }

    public function test_find_by_url_returns_null_for_missing_url(): void
    {
        $result = $this->service->findByUrl('https://example.com/nonexistent');

        $this->assertNull($result);
    }

    public function test_all_returns_collection_of_all_saved_items(): void
    {
        $this->service->save(['url' => 'https://example.com/page1', 'title' => 'Page 1']);
        $this->service->save(['url' => 'https://example.com/page2', 'title' => 'Page 2']);

        $all = $this->service->all();

        $this->assertCount(2, $all);
    }

    public function test_all_returns_empty_collection_when_no_files(): void
    {
        $all = $this->service->all();

        $this->assertCount(0, $all);
    }

    public function test_count_by_domain_returns_correct_count(): void
    {
        $this->service->save(['url' => 'https://example.com/page1', 'title' => 'A']);
        $this->service->save(['url' => 'https://example.com/page2', 'title' => 'B']);
        $this->service->save(['url' => 'https://other.com/page', 'title' => 'C']);

        $count = $this->service->countByDomain('https://example.com');

        $this->assertSame(2, $count);
    }

    public function test_build_path_generates_correct_path_for_deep_url(): void
    {
        $path = $this->service->buildPath('https://city.shibuya.tokyo.jp/kosodate/hoiku/');

        $this->assertSame('city-shibuya-tokyo-jp/kosodate-hoiku.json', $path);
    }

    public function test_build_path_uses_index_for_root_path(): void
    {
        $path = $this->service->buildPath('https://example.com/');

        $this->assertSame('example-com/index.json', $path);
    }

    public function test_build_path_uses_index_for_no_path(): void
    {
        $path = $this->service->buildPath('https://example.com');

        $this->assertSame('example-com/index.json', $path);
    }

    public function test_save_stores_pretty_printed_json(): void
    {
        $data = ['url' => 'https://example.com/test', 'title' => 'テスト'];
        $this->service->save($data);

        $content = Storage::disk('data')->get('example-com/test.json');
        $this->assertStringContainsString("\n", $content);
        $this->assertStringContainsString('テスト', $content);
    }
}
