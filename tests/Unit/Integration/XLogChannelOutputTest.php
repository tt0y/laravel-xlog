<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Unit\Integration;

use Tt0y\XLog\Context\IdContext;
use Tt0y\XLog\Formatter\PromotedRootJsonFormatter;
use Tt0y\XLog\Tap\XLogTap;
use Tt0y\XLog\Tests\TestCase;
use Tt0y\XLog\User\Contracts\UserIdResolverInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Monolog\Handler\StreamHandler;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration test verifying that the full XLog pipeline
 * (XLogTap processors + PromotedRootJsonFormatter) produces
 * valid structured JSON with promoted fields at root level.
 *
 * Also verifies that a channel WITHOUT XLog does NOT produce
 * the expected format — proving the format depends on configuration.
 */
final class XLogChannelOutputTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stream = fopen('php://memory', 'rb+');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        parent::tearDown();
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('xlog.service', 'test-service');
        $app['config']->set('xlog.env', 'testing');
        $app['config']->set('xlog.instance', 'test-pod-01');

        // Define auth guards required by AuthUserResolver
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

        $app['config']->set('xlog.user.guards', ['api', 'web']);
    }

    private function makeStream()
    {
        $stream = fopen('php://memory', 'rb+');
        $this->assertNotFalse($stream);

        return $stream;
    }

    private function readStream($stream): string
    {
        rewind($stream);

        return stream_get_contents($stream) ?: '';
    }

    private function configureChannelWithXlog($stream): void
    {
        $this->app['config']->set('logging.channels.test_xlog', [
            'driver' => 'monolog',
            'level' => 'debug',
            'handler' => StreamHandler::class,
            'handler_with' => ['stream' => $stream],
            'formatter' => PromotedRootJsonFormatter::class,
            'formatter_with' => ['appendNewline' => true],
            'tap' => [XLogTap::class],
        ]);
    }

    private function configureChannelWithoutXlog($stream): void
    {
        $this->app['config']->set('logging.channels.test_plain', [
            'driver' => 'monolog',
            'level' => 'debug',
            'handler' => StreamHandler::class,
            'handler_with' => ['stream' => $stream],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function logAndDecode(string $channel, string $message, array $context = []): array
    {
        /** @var \Illuminate\Log\LogManager $log */
        $log = $this->app['log'];
        $log->channel($channel)->info($message, $context);

        $output = $this->readStream($this->stream);
        $this->assertNotEmpty($output, 'Log output should not be empty');

        $decoded = json_decode(trim($output), true);
        $this->assertIsArray($decoded, 'Log output must be valid JSON');

        return $decoded;
    }

    // ---------------------------------------------------------------
    // XLog ENABLED: channel with XLogTap + PromotedRootJsonFormatter
    // ---------------------------------------------------------------

    #[Test]
    public function xlog_channel_produces_valid_json_with_promoted_fields(): void
    {
        $this->app->bind(IdContext::class, fn () => new IdContext('corr-abc', 'req-xyz'));

        $userIdResolver = Mockery::mock(UserIdResolverInterface::class);
        $userIdResolver->shouldReceive('resolve')->andReturn(42);
        $this->app->instance(UserIdResolverInterface::class, $userIdResolver);

        $this->stream = $this->makeStream();
        $this->configureChannelWithXlog($this->stream);

        $data = $this->logAndDecode('test_xlog', 'User logged in', ['action' => 'login']);

        // Promoted fields at root level
        $this->assertSame('corr-abc', $data['correlation_id']);
        $this->assertSame('req-xyz', $data['request_id']);
        $this->assertSame('test-service', $data['service']);
        $this->assertSame('testing', $data['env']);
        $this->assertSame('test-pod-01', $data['instance']);
        $this->assertSame(42, $data['user_id']);

        // Standard Monolog fields
        $this->assertSame('User logged in', $data['message']);
        $this->assertSame('INFO', $data['level_name']);

        // Custom context preserved
        $this->assertSame('login', $data['context']['action']);

        // Promoted keys removed from context
        $this->assertArrayNotHasKey('correlation_id', $data['context'] ?? []);
        $this->assertArrayNotHasKey('request_id', $data['context'] ?? []);
        $this->assertArrayNotHasKey('service', $data['context'] ?? []);
    }

    #[Test]
    public function xlog_channel_output_contains_all_required_fields(): void
    {
        $this->app->bind(IdContext::class, fn () => new IdContext('corr-1', 'req-1'));

        $this->stream = $this->makeStream();
        $this->configureChannelWithXlog($this->stream);

        $data = $this->logAndDecode('test_xlog', 'Something failed');

        $requiredRootKeys = ['message', 'level', 'level_name', 'channel', 'datetime'];
        foreach ($requiredRootKeys as $key) {
            $this->assertArrayHasKey($key, $data, "Missing required root key: {$key}");
        }

        $requiredPromotedKeys = ['correlation_id', 'request_id', 'service', 'env'];
        foreach ($requiredPromotedKeys as $key) {
            $this->assertArrayHasKey($key, $data, "Missing promoted key at root: {$key}");
        }
    }

    #[Test]
    public function xlog_channel_without_user_omits_user_id(): void
    {
        $this->app->bind(IdContext::class, fn () => new IdContext('corr-1', 'req-1'));

        $userIdResolver = Mockery::mock(UserIdResolverInterface::class);
        $userIdResolver->shouldReceive('resolve')->andReturn(null);
        $this->app->instance(UserIdResolverInterface::class, $userIdResolver);

        $this->stream = $this->makeStream();
        $this->configureChannelWithXlog($this->stream);

        $data = $this->logAndDecode('test_xlog', 'Anonymous action');

        // user_id is filtered out by ContextProvider when null
        $this->assertArrayNotHasKey('user_id', $data);
    }

    // ---------------------------------------------------------------
    // XLog DISABLED: channel WITHOUT XLogTap / PromotedRootJsonFormatter
    // ---------------------------------------------------------------

    #[Test]
    public function plain_channel_does_not_produce_promoted_fields(): void
    {
        $stream = $this->makeStream();
        $this->configureChannelWithoutXlog($stream);

        /** @var \Illuminate\Log\LogManager $log */
        $log = $this->app['log'];
        $log->channel('test_plain')->info('Plain log', ['action' => 'test']);

        $output = $this->readStream($stream);
        $this->assertNotEmpty($output, 'Log output should not be empty');

        // Standard Monolog LineFormatter — NOT valid JSON
        $decoded = json_decode(trim($output), true);

        if ($decoded !== null) {
            // If somehow it's JSON, promoted XLog fields must NOT be at root
            $this->assertArrayNotHasKey('correlation_id', $decoded, 'Plain channel must not have correlation_id');
            $this->assertArrayNotHasKey('request_id', $decoded, 'Plain channel must not have request_id');
            $this->assertArrayNotHasKey('service', $decoded, 'Plain channel must not have service');
            $this->assertArrayNotHasKey('env', $decoded, 'Plain channel must not have env');
            $this->assertArrayNotHasKey('instance', $decoded, 'Plain channel must not have instance');
        } else {
            // LineFormatter output — expected when XLog is not configured
            $this->assertStringContainsString('Plain log', $output);
        }
    }

    #[Test]
    public function stack_channel_format_depends_on_xlog_configuration(): void
    {
        $this->app->bind(IdContext::class, fn () => new IdContext('corr-stack', 'req-stack'));

        $xlogStream = $this->makeStream();
        $plainStream = $this->makeStream();

        $this->configureChannelWithXlog($xlogStream);
        $this->configureChannelWithoutXlog($plainStream);

        // Simulate config/logging.php stack behavior:
        // XLOG_ENABLED=true  → stack uses 'test_xlog'
        // XLOG_ENABLED=false → stack uses 'test_plain'

        $this->app['config']->set('logging.channels.stack_xlog_on', [
            'driver' => 'stack',
            'channels' => ['test_xlog'],
        ]);
        $this->app['config']->set('logging.channels.stack_xlog_off', [
            'driver' => 'stack',
            'channels' => ['test_plain'],
        ]);

        /** @var \Illuminate\Log\LogManager $log */
        $log = $this->app['log'];

        // --- XLOG_ENABLED=true ---
        $log->channel('stack_xlog_on')->error('Auth failed', ['reason' => 'bad_password']);

        $xlogOutput = $this->readStream($xlogStream);
        $xlogData = json_decode(trim($xlogOutput), true);

        $this->assertIsArray($xlogData, 'With XLog enabled, output must be valid JSON');
        $this->assertArrayHasKey('correlation_id', $xlogData, 'XLog output must have correlation_id at root');
        $this->assertArrayHasKey('request_id', $xlogData, 'XLog output must have request_id at root');
        $this->assertArrayHasKey('service', $xlogData, 'XLog output must have service at root');
        $this->assertSame('Auth failed', $xlogData['message']);

        // --- XLOG_ENABLED=false ---
        $log->channel('stack_xlog_off')->error('Auth failed', ['reason' => 'bad_password']);

        $plainOutput = $this->readStream($plainStream);

        // Plain output should NOT have promoted fields
        $plainData = json_decode(trim($plainOutput), true);
        if ($plainData !== null) {
            $this->assertArrayNotHasKey('correlation_id', $plainData);
            $this->assertArrayNotHasKey('service', $plainData);
        }

        // Outputs must be different
        $this->assertNotEquals(
            trim($xlogOutput),
            trim($plainOutput),
            'XLog-enabled and XLog-disabled channels must produce different output formats'
        );
    }
}
