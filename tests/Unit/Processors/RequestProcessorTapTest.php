<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Unit\Processors;

use Tt0y\XLog\Formatter\PromotedRootJsonFormatter;
use Tt0y\XLog\Processors\RequestProcessor;
use Tt0y\XLog\Tests\TestCase;
use Tt0y\XLog\User\AuthUserResolver;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\Attributes\Test;

final class RequestProcessorTapTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private function makeAuthResolver(): AuthUserResolver
    {
        $guard = new class {
            public function hasUser(): bool
            {
                return false;
            }
        };

        Auth::shouldReceive('guard')->andReturn($guard);

        return new AuthUserResolver();
    }

    #[Test]
    public function invoke_with_logger_pushes_processor_to_handlers(): void
    {
        $authResolver = $this->makeAuthResolver();
        $processor = new RequestProcessor($authResolver);

        $stream = fopen('php://memory', 'rb+');
        $handler = new StreamHandler($stream);
        $handler->setFormatter(new PromotedRootJsonFormatter(appendNewline: true));

        $monolog = new MonologLogger('test', [$handler]);
        $logger = new Logger($monolog);

        $result = $processor($logger);

        $this->assertNull($result, '__invoke with Logger should return null');

        // Verify processor was pushed by writing a record with context
        $monolog->info('tap test', ['command_name' => 'test:cmd']);

        rewind($stream);
        $output = stream_get_contents($stream);
        $data = json_decode(trim($output), true);

        // RequestProcessor adds source context for CLI
        $this->assertSame('cli', $data['context']['source']['type']);
        $this->assertSame('test:cmd', $data['context']['source']['command']);

        fclose($stream);
    }

    #[Test]
    public function invoke_with_log_record_processes_and_returns_record(): void
    {
        $authResolver = $this->makeAuthResolver();
        $processor = new RequestProcessor($authResolver, null, null);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'test message',
            context: ['command_name' => 'test:run'],
        );

        $result = $processor($record);

        $this->assertInstanceOf(LogRecord::class, $result);
        $this->assertSame('cli', $result->context['source']['type']);
        $this->assertSame('test:run', $result->context['source']['command']);
    }

    #[Test]
    public function invoke_with_logger_returns_null(): void
    {
        $authResolver = $this->makeAuthResolver();
        $processor = new RequestProcessor($authResolver);

        $stream = fopen('php://memory', 'rb+');
        $handler = new StreamHandler($stream);
        $monolog = new MonologLogger('test', [$handler]);
        $logger = new Logger($monolog);

        $result = $processor($logger);

        $this->assertNull($result);

        fclose($stream);
    }

    #[Test]
    public function tap_mode_works_with_multiple_handlers(): void
    {
        $authResolver = $this->makeAuthResolver();
        $processor = new RequestProcessor($authResolver, null, null);

        $stream1 = fopen('php://memory', 'rb+');
        $stream2 = fopen('php://memory', 'rb+');
        $handler1 = new StreamHandler($stream1);
        $handler2 = new StreamHandler($stream2);
        $handler1->setFormatter(new PromotedRootJsonFormatter(appendNewline: true));
        $handler2->setFormatter(new PromotedRootJsonFormatter(appendNewline: true));

        $monolog = new MonologLogger('test', [$handler1, $handler2]);
        $logger = new Logger($monolog);

        $processor($logger);

        $monolog->info('multi', ['job_class' => 'App\\Jobs\\Test']);

        rewind($stream1);
        rewind($stream2);
        $data1 = json_decode(trim(stream_get_contents($stream1)), true);
        $data2 = json_decode(trim(stream_get_contents($stream2)), true);

        $this->assertSame('queue', $data1['context']['source']['type']);
        $this->assertSame('queue', $data2['context']['source']['type']);

        fclose($stream1);
        fclose($stream2);
    }
}
