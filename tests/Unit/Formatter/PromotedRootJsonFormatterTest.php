<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Unit\Formatter;

use Tt0y\XLog\Formatter\PromotedRootJsonFormatter;
use Tt0y\XLog\Tests\TestCase;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;

final class PromotedRootJsonFormatterTest extends TestCase
{
    private PromotedRootJsonFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new PromotedRootJsonFormatter();
    }

    #[Test]
    public function it_promotes_context_keys_to_root(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'xlog',
            level: Level::Info,
            message: 'test',
            context: [
                'correlation_id' => 'corr-1',
                'request_id' => 'req-1',
                'service' => 'my-service',
                'custom_key' => 'stays',
            ],
        );

        $json = $this->formatter->format($record);
        $data = json_decode($json, true);

        $this->assertSame('corr-1', $data['correlation_id']);
        $this->assertSame('req-1', $data['request_id']);
        $this->assertSame('my-service', $data['service']);
        // custom_key stays in context
        $this->assertSame('stays', $data['context']['custom_key']);
        // Promoted keys removed from context
        $this->assertArrayNotHasKey('correlation_id', $data['context']);
        $this->assertArrayNotHasKey('request_id', $data['context']);
        $this->assertArrayNotHasKey('service', $data['context']);
    }

    #[Test]
    public function it_does_not_overwrite_existing_root_keys(): void
    {
        // 'message' is a standard root key in normalized Monolog output.
        // Configure it as a promoted key to test that root keys are not overwritten.
        $this->app['config']->set('xlog.formatter.promoted_keys', ['message']);

        $formatter = new PromotedRootJsonFormatter();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'xlog',
            level: Level::Info,
            message: 'root-message',
            context: [
                'message' => 'context-message',
            ],
        );

        $json = $formatter->format($record);
        $data = json_decode($json, true);

        // Root 'message' should keep its original value, not overwritten by context
        $this->assertSame('root-message', $data['message']);
        // Context 'message' should stay in context since it couldn't be promoted
        $this->assertSame('context-message', $data['context']['message']);
    }

    #[Test]
    public function it_removes_empty_context_after_promotion(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'xlog',
            level: Level::Info,
            message: 'test',
            context: [
                'correlation_id' => 'corr-1',
            ],
        );

        $json = $this->formatter->format($record);
        $data = json_decode($json, true);

        $this->assertSame('corr-1', $data['correlation_id']);
        $this->assertArrayNotHasKey('context', $data);
    }

    #[Test]
    public function it_uses_default_keys_when_config_empty(): void
    {
        $this->app['config']->set('xlog.formatter.promoted_keys', []);

        // Need a fresh formatter to clear cached keys
        $formatter = new PromotedRootJsonFormatter();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'xlog',
            level: Level::Info,
            message: 'test',
            context: [
                'correlation_id' => 'corr-1',
                'user_id' => 42,
            ],
        );

        $json = $formatter->format($record);
        $data = json_decode($json, true);

        // Default keys include correlation_id and user_id
        $this->assertSame('corr-1', $data['correlation_id']);
        $this->assertSame(42, $data['user_id']);
    }

    #[Test]
    public function it_uses_custom_promoted_keys_from_config(): void
    {
        $this->app['config']->set('xlog.formatter.promoted_keys', ['custom_key']);

        $formatter = new PromotedRootJsonFormatter();

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'xlog',
            level: Level::Info,
            message: 'test',
            context: [
                'custom_key' => 'promoted',
                'correlation_id' => 'not-promoted',
            ],
        );

        $json = $formatter->format($record);
        $data = json_decode($json, true);

        $this->assertSame('promoted', $data['custom_key']);
        // correlation_id is NOT in custom promoted keys, stays in context
        $this->assertSame('not-promoted', $data['context']['correlation_id']);
    }

    #[Test]
    public function it_appends_newline(): void
    {
        $formatter = new PromotedRootJsonFormatter(appendNewline: true);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'xlog',
            level: Level::Info,
            message: 'test',
            context: [],
        );

        $json = $formatter->format($record);

        $this->assertStringEndsWith("\n", $json);
    }
}
