<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Unit\Tap;

use Tt0y\XLog\Context\ContextProvider;
use Tt0y\XLog\Context\IdContext;
use Tt0y\XLog\Formatter\PromotedRootJsonFormatter;
use Tt0y\XLog\Tap\XLogTap;
use Tt0y\XLog\Tests\TestCase;
use Tt0y\XLog\User\AuthUserResolver;
use Tt0y\XLog\User\Contracts\UserAttributesResolverInterface;
use Tt0y\XLog\User\Contracts\UserIdResolverInterface;
use Illuminate\Http\Request;
use Illuminate\Log\Logger;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\Attributes\Test;

final class XLogTapTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('auth.guards.api', [
            'driver' => 'session',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.guards.web', [
            'driver' => 'session',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.providers.users', [
            'driver' => 'database',
            'table' => 'users',
        ]);
    }

    private function makeTap(?Request $request = null): XLogTap
    {
        $userIdResolver = Mockery::mock(UserIdResolverInterface::class);
        $userIdResolver->shouldReceive('resolve')->andReturn(null);

        $this->app->instance(UserIdResolverInterface::class, $userIdResolver);
        $this->app->bind(IdContext::class, fn () => new IdContext('corr', 'req'));

        $contextProvider = new ContextProvider($this->app, $userIdResolver);
        $authUserResolver = new AuthUserResolver();

        return new XLogTap($contextProvider, $authUserResolver, null, $request);
    }

    #[Test]
    public function it_pushes_processors_that_enrich_log_records(): void
    {
        $tap = $this->makeTap();

        $stream = fopen('php://memory', 'rb+');
        $handler = new StreamHandler($stream);
        $handler->setFormatter(new PromotedRootJsonFormatter(appendNewline: true));

        $monolog = new MonologLogger('test', [$handler]);
        $logger = new Logger($monolog);

        $tap($logger);

        // Write a record and verify processors enriched it
        $monolog->info('test message', ['action' => 'test']);

        rewind($stream);
        $output = stream_get_contents($stream);
        $data = json_decode(trim($output), true);

        $this->assertIsArray($data);
        $this->assertSame('corr', $data['correlation_id']);
        $this->assertSame('req', $data['request_id']);
        $this->assertSame('test-service', $data['service']);

        fclose($stream);
    }

    #[Test]
    public function it_pushes_to_multiple_handlers(): void
    {
        $tap = $this->makeTap();

        $stream1 = fopen('php://memory', 'rb+');
        $stream2 = fopen('php://memory', 'rb+');
        $handler1 = new StreamHandler($stream1);
        $handler2 = new StreamHandler($stream2);
        $handler1->setFormatter(new PromotedRootJsonFormatter(appendNewline: true));
        $handler2->setFormatter(new PromotedRootJsonFormatter(appendNewline: true));

        $monolog = new MonologLogger('test', [$handler1, $handler2]);
        $logger = new Logger($monolog);

        $tap($logger);

        $monolog->info('multi handler test');

        rewind($stream1);
        rewind($stream2);
        $output1 = stream_get_contents($stream1);
        $output2 = stream_get_contents($stream2);

        // Both handlers should produce enriched output
        $data1 = json_decode(trim($output1), true);
        $data2 = json_decode(trim($output2), true);

        $this->assertSame('corr', $data1['correlation_id']);
        $this->assertSame('corr', $data2['correlation_id']);

        fclose($stream1);
        fclose($stream2);
    }

    #[Test]
    public function it_skips_non_processable_handlers(): void
    {
        $tap = $this->makeTap();

        $nonProcessable = Mockery::mock(\Monolog\Handler\HandlerInterface::class);
        $nonProcessable->shouldNotReceive('pushProcessor');
        $nonProcessable->shouldReceive('isHandling')->andReturn(true);
        $nonProcessable->shouldReceive('handle')->andReturn(false);

        $stream = fopen('php://memory', 'rb+');
        $processable = new StreamHandler($stream);
        $processable->setFormatter(new PromotedRootJsonFormatter(appendNewline: true));

        $monolog = new MonologLogger('test', [$nonProcessable, $processable]);
        $logger = new Logger($monolog);

        $tap($logger);

        // Write and verify only processable handler got enriched
        $monolog->info('test', ['key' => 'val']);

        rewind($stream);
        $output = stream_get_contents($stream);
        $data = json_decode(trim($output), true);

        $this->assertSame('corr', $data['correlation_id']);

        fclose($stream);
    }

    #[Test]
    public function it_enriches_before_handler_level_processors_run(): void
    {
        // Enrichment must stay on the logger level so a handler-level processor
        // added by the host application (e.g. a PII scrubber) can still see and
        // rewrite context.http — handler-level enrichment would bypass it.
        $request = Request::create('/api/test?t=opaque-token', 'GET');

        $tap = $this->makeTap($request);

        $stream = fopen('php://memory', 'rb+');
        $handler = new StreamHandler($stream);
        $handler->setFormatter(new PromotedRootJsonFormatter(appendNewline: true));

        $monolog = new MonologLogger('test', [$handler]);
        $tap(new Logger($monolog));

        $handler->pushProcessor(static function (LogRecord $record): LogRecord {
            $context = $record->context;
            $context['http']['uri'] = 'REWRITTEN';

            return $record->with(context: $context);
        });

        $monolog->info('enriched before handler processors');

        rewind($stream);
        $data = json_decode(trim(stream_get_contents($stream)), true);

        $this->assertSame('REWRITTEN', $data['context']['http']['uri']);

        fclose($stream);
    }

    #[Test]
    public function it_includes_http_context_when_request_present(): void
    {
        $request = Request::create('/api/test', 'POST', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_HOST' => 'example.com',
        ]);

        $tap = $this->makeTap($request);

        $stream = fopen('php://memory', 'rb+');
        $handler = new StreamHandler($stream);
        $handler->setFormatter(new PromotedRootJsonFormatter(appendNewline: true));

        $monolog = new MonologLogger('test', [$handler]);
        $logger = new Logger($monolog);

        $tap($logger);

        $monolog->info('with request');

        rewind($stream);
        $output = stream_get_contents($stream);
        $data = json_decode(trim($output), true);

        $this->assertArrayHasKey('http', $data['context']);
        $this->assertSame('POST', $data['context']['http']['method']);
        $this->assertSame('example.com', $data['context']['http']['host']);

        fclose($stream);
    }
}
