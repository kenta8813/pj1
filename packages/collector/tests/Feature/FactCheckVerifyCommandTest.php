<?php

namespace Tests\Feature;

use App\Services\DataStoreService;
use App\Services\FactCheckService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FactCheckVerifyCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
    }

    public function test_command_runs_all_checks_and_shows_summary(): void
    {
        $checker = $this->createMock(FactCheckService::class);
        $checker->method('checkAll')->willReturn(['checked' => 3, 'high' => 2, 'medium' => 1, 'low' => 0]);
        $this->app->instance(FactCheckService::class, $checker);

        $this->artisan('collect:verify')
            ->expectsOutputToContain('3件チェック')
            ->expectsOutputToContain('high:   2件')
            ->assertExitCode(0);
    }

    public function test_command_dry_run_passes_flag_to_service(): void
    {
        $checker = $this->createMock(FactCheckService::class);
        $checker->expects($this->once())
            ->method('checkAll')
            ->with(true, 'all')
            ->willReturn(['checked' => 0, 'high' => 0, 'medium' => 0, 'low' => 0]);
        $this->app->instance(FactCheckService::class, $checker);

        $this->artisan('collect:verify', ['--dry-run' => true])
            ->expectsOutputToContain('dry-run')
            ->assertExitCode(0);
    }

    public function test_command_confidence_filter_is_passed_to_service(): void
    {
        $checker = $this->createMock(FactCheckService::class);
        $checker->expects($this->once())
            ->method('checkAll')
            ->with(false, 'low')
            ->willReturn(['checked' => 1, 'high' => 0, 'medium' => 0, 'low' => 1]);
        $this->app->instance(FactCheckService::class, $checker);

        $this->artisan('collect:verify', ['--confidence' => 'low'])
            ->assertExitCode(0);
    }

    public function test_command_url_option_checks_single_url(): void
    {
        $store = new DataStoreService;
        $store->save(['url' => 'https://example.com/page', 'title' => 'テスト', '_fc_confidence' => '', '_fc_issues' => [], '_fc_checked_at' => '']);
        $this->app->instance(DataStoreService::class, $store);

        $checker = $this->createMock(FactCheckService::class);
        $checker->expects($this->once())
            ->method('check')
            ->willReturn(['confidence' => 'high', 'issues' => []]);
        $this->app->instance(FactCheckService::class, $checker);

        $this->artisan('collect:verify', ['--url' => 'https://example.com/page'])
            ->expectsOutputToContain('confidence: high')
            ->assertExitCode(0);
    }

    public function test_command_url_option_fails_when_url_not_found(): void
    {
        $this->artisan('collect:verify', ['--url' => 'https://example.com/nonexistent'])
            ->expectsOutputToContain('データが見つかりません')
            ->assertExitCode(1);
    }
}
