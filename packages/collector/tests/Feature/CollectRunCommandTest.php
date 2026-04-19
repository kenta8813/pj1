<?php

namespace Tests\Feature;

use App\Jobs\ExploreJob;
use App\Services\SiteExplorerService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CollectRunCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('data');
    }

    public function test_command_runs_synchronously_and_shows_count(): void
    {
        $explorer = $this->createMock(SiteExplorerService::class);
        $explorer->method('explore')->willReturn(5);
        $this->app->instance(SiteExplorerService::class, $explorer);

        $this->artisan('collect:run', ['url' => 'https://example.com/'])
            ->expectsOutputToContain('5件')
            ->assertExitCode(0);
    }

    public function test_command_fails_when_template_not_found(): void
    {
        $this->artisan('collect:run', ['url' => 'https://example.com/', '--template' => 'nonexistent'])
            ->expectsOutputToContain('テンプレートが見つかりません')
            ->assertExitCode(1);
    }

    public function test_command_dry_run_passes_flag_to_explorer(): void
    {
        $explorer = $this->createMock(SiteExplorerService::class);
        $explorer->expects($this->once())
            ->method('explore')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                true // dryRun = true
            )
            ->willReturn(3);
        $this->app->instance(SiteExplorerService::class, $explorer);

        $this->artisan('collect:run', ['url' => 'https://example.com/', '--dry-run' => true])
            ->expectsOutputToContain('dry-run')
            ->assertExitCode(0);
    }

    public function test_command_queue_option_dispatches_job(): void
    {
        Queue::fake();

        $this->artisan('collect:run', ['url' => 'https://example.com/', '--queue' => true])
            ->expectsOutputToContain('キューに投入')
            ->assertExitCode(0);

        Queue::assertPushed(ExploreJob::class, function (ExploreJob $job) {
            return $job->entryUrl === 'https://example.com/';
        });
    }

    public function test_command_passes_depth_and_pages_to_explorer(): void
    {
        $explorer = $this->createMock(SiteExplorerService::class);
        $explorer->expects($this->once())
            ->method('explore')
            ->with(
                $this->anything(),
                $this->anything(),
                2,   // depth
                50,  // pages
                $this->anything()
            )
            ->willReturn(0);
        $this->app->instance(SiteExplorerService::class, $explorer);

        $this->artisan('collect:run', ['url' => 'https://example.com/', '--depth' => 2, '--pages' => 50])
            ->assertExitCode(0);
    }
}
