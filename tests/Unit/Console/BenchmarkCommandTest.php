<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Unit\Console;

use Tt0y\XLog\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class BenchmarkCommandTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Use null channel to avoid filesystem writes during benchmark
        $app['config']->set('logging.default', 'null');
        $app['config']->set('logging.channels.null', [
            'driver' => 'monolog',
            'handler' => \Monolog\Handler\NullHandler::class,
        ]);
    }

    #[Test]
    public function it_runs_successfully_with_defaults(): void
    {
        $this->artisan('xlog:benchmark', ['--count' => 10])
            ->assertSuccessful()
            ->expectsOutputToContain('RESULTS')
            ->expectsOutputToContain('Total time')
            ->expectsOutputToContain('Avg per record')
            ->expectsOutputToContain('Records/second');
    }

    #[Test]
    public function it_runs_with_context_option(): void
    {
        $this->artisan('xlog:benchmark', ['--count' => 5, '--context' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('With context:     yes');
    }

    #[Test]
    public function it_outputs_json_for_automated_processing(): void
    {
        $this->artisan('xlog:benchmark', ['--count' => 5])
            ->assertSuccessful()
            ->expectsOutputToContain('JSON (for copy/paste):')
            ->expectsOutputToContain('"count": 5');
    }

    #[Test]
    public function it_shows_xlog_enabled_status(): void
    {
        $this->app['config']->set('xlog.enabled', true);

        $this->artisan('xlog:benchmark', ['--count' => 5])
            ->assertSuccessful()
            ->expectsOutputToContain('XLOG_ENABLED:     true');
    }

    #[Test]
    public function it_shows_xlog_disabled_status(): void
    {
        $this->app['config']->set('xlog.enabled', false);

        $this->artisan('xlog:benchmark', ['--count' => 5])
            ->assertSuccessful()
            ->expectsOutputToContain('XLOG_ENABLED:     false');
    }

    #[Test]
    public function it_returns_success_exit_code(): void
    {
        $this->artisan('xlog:benchmark', ['--count' => 5])
            ->assertExitCode(0);
    }
}
