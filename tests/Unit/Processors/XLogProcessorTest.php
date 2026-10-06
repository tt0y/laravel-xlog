<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Unit\Processors;

use Tt0y\XLog\Context\ContextProvider;
use Tt0y\XLog\Context\IdContext;
use Tt0y\XLog\Processors\XLogProcessor;
use Tt0y\XLog\Tests\TestCase;
use Tt0y\XLog\User\Contracts\UserIdResolverInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;

final class XLogProcessorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private function makeContextProvider(array $baseContext): ContextProvider
    {
        $userIdResolver = Mockery::mock(UserIdResolverInterface::class);
        $userIdResolver->shouldReceive('resolve')->andReturn($baseContext['user_id'] ?? null);

        $this->app['config']->set('xlog.service', $baseContext['service'] ?? 'test');
        $this->app['config']->set('xlog.env', $baseContext['env'] ?? 'testing');
        $this->app['config']->set('xlog.instance', $baseContext['instance'] ?? null);

        $corrId = $baseContext['correlation_id'] ?? 'n/a';
        $reqId = $baseContext['request_id'] ?? 'n/a';

        $this->app->bind(IdContext::class, fn() => new IdContext($corrId, $reqId));

        return new ContextProvider($this->app, $userIdResolver);
    }

    #[Test]
    public function it_merges_base_context_into_record(): void
    {
        $contextProvider = $this->makeContextProvider([
            'correlation_id' => 'corr-1',
            'request_id' => 'req-1',
            'service' => 'test-svc',
        ]);

        $processor = new XLogProcessor($contextProvider);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'xlog',
            level: Level::Info,
            message: 'test',
            context: ['custom' => 'value'],
        );

        $result = $processor($record);

        $this->assertSame('corr-1', $result->context['correlation_id']);
        $this->assertSame('req-1', $result->context['request_id']);
        $this->assertSame('test-svc', $result->context['service']);
        $this->assertSame('value', $result->context['custom']);
    }

    #[Test]
    public function record_context_wins_on_key_conflict(): void
    {
        $contextProvider = $this->makeContextProvider([
            'correlation_id' => 'base-corr',
            'service' => 'base-svc',
        ]);

        $processor = new XLogProcessor($contextProvider);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'xlog',
            level: Level::Info,
            message: 'test',
            context: ['correlation_id' => 'record-corr'],
        );

        $result = $processor($record);

        // Record context wins (+ operator: left side wins)
        $this->assertSame('record-corr', $result->context['correlation_id']);
        // Base context fills in missing keys
        $this->assertSame('base-svc', $result->context['service']);
    }
}
