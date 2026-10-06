<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Unit\Logger;

use Tt0y\XLog\Logger\XLogger;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

final class XLoggerTest extends \Tt0y\XLog\Tests\TestCase
{
    #[Test]
    public function log_delegates_to_xlog_channel(): void
    {
        $channelMock = \Mockery::mock(\Psr\Log\LoggerInterface::class);
        $channelMock->shouldReceive('log')
            ->once()
            ->with('info', 'payment.created', \Mockery::on(function (array $ctx) {
                return $ctx['message'] === 'payment.created' && $ctx['amount'] === 123;
            }));

        Log::shouldReceive('channel')
            ->with('xlog')
            ->once()
            ->andReturn($channelMock);

        $logger = new XLogger();
        $logger->info('payment.created', ['amount' => 123]);
    }

    #[Test]
    public function log_adds_message_to_context(): void
    {
        $channelMock = \Mockery::mock(\Psr\Log\LoggerInterface::class);
        $channelMock->shouldReceive('log')
            ->once()
            ->with('warning', 'event.name', \Mockery::on(function (array $ctx) {
                return $ctx['message'] === 'event.name';
            }));

        Log::shouldReceive('channel')
            ->with('xlog')
            ->once()
            ->andReturn($channelMock);

        $logger = new XLogger();
        $logger->warning('event.name');
    }

    #[Test]
    public function log_does_not_log_when_disabled(): void
    {
        $this->app['config']->set('xlog.enabled', false);

        Log::shouldReceive('channel')->never();

        $logger = new XLogger();
        $logger->info('should.not.log');
    }

    #[Test]
    public function convenience_methods_delegate_correctly(): void
    {
        $levels = ['info', 'warning', 'error', 'critical'];

        foreach ($levels as $level) {
            $channelMock = \Mockery::mock(\Psr\Log\LoggerInterface::class);
            $channelMock->shouldReceive('log')
                ->once()
                ->with($level, "test.{$level}", \Mockery::type('array'));

            Log::shouldReceive('channel')
                ->with('xlog')
                ->once()
                ->andReturn($channelMock);

            $logger = new XLogger();
            $logger->$level("test.{$level}");
        }
    }

    #[Test]
    public function empty_message_does_not_add_message_key(): void
    {
        $channelMock = \Mockery::mock(\Psr\Log\LoggerInterface::class);
        $channelMock->shouldReceive('log')
            ->once()
            ->with('info', '', \Mockery::on(function (array $ctx) {
                return !isset($ctx['message']);
            }));

        Log::shouldReceive('channel')
            ->with('xlog')
            ->once()
            ->andReturn($channelMock);

        $logger = new XLogger();
        $logger->info('', ['key' => 'val']);
    }
}
