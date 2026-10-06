<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Unit\Traits;

use Tt0y\XLog\Models\ModelLogPayloadBuilder;
use Tt0y\XLog\Tests\TestCase;
use Tt0y\XLog\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Test;

final class LogsActivityTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private function makeModel(array $attributes = []): Model
    {
        return new class($attributes) extends Model {
            use LogsActivity;

            protected $table = 'test_models';
            protected $fillable = ['name', 'email'];
        };
    }

    /**
     * Bind a fake builder that returns a fixed payload.
     */
    private function fakePayloadBuilder(array $payload = []): void
    {
        $fake = new class($payload) {
            public function __construct(private readonly array $payload) {}

            public function build(Model $model): array
            {
                return $this->payload;
            }
        };

        $this->app->instance(ModelLogPayloadBuilder::class, $fake);
    }

    /**
     * Mock Log::channel('xlog') to return a channel mock that expects a specific level/event.
     */
    private function expectXLogCall(string $level, string $event, mixed $contextMatcher = null): void
    {
        $matcher = $contextMatcher ?? Mockery::type('array');

        $channelMock = Mockery::mock(\Psr\Log\LoggerInterface::class);
        $channelMock->shouldReceive('log')
            ->once()
            ->with($level, $event, $matcher);

        Log::shouldReceive('channel')
            ->with('xlog')
            ->once()
            ->andReturn($channelMock);
    }

    #[Test]
    public function log_calls_xlog_info_by_default(): void
    {
        $this->fakePayloadBuilder(['model' => 'test', 'model_id' => 1]);

        $this->expectXLogCall('info', 'model.log', Mockery::on(
            fn (array $ctx) => $ctx['model'] === 'test' && $ctx['model_id'] === 1
        ));

        $model = $this->makeModel(['name' => 'John']);
        $model->log();
    }

    #[Test]
    public function log_passes_custom_event_and_context(): void
    {
        $this->fakePayloadBuilder(['model_id' => 5]);

        $this->expectXLogCall('info', 'user.updated', Mockery::on(
            fn (array $ctx) => $ctx['reason'] === 'profile_change' && $ctx['model_id'] === 5
        ));

        $model = $this->makeModel();
        $model->log('user.updated', ['reason' => 'profile_change']);
    }

    #[Test]
    public function log_context_overrides_payload_on_key_conflict(): void
    {
        $this->fakePayloadBuilder(['model_id' => 1, 'status' => 'from_payload']);

        $this->expectXLogCall('info', 'test', Mockery::on(
            fn (array $ctx) => $ctx['status'] === 'overridden'
        ));

        $model = $this->makeModel();
        $model->log('test', ['status' => 'overridden']);
    }

    #[Test]
    public function log_warning_delegates_to_warning_level(): void
    {
        $this->fakePayloadBuilder([]);
        $this->expectXLogCall('warning', 'alert.fired');

        $model = $this->makeModel();
        $model->logWarning('alert.fired');
    }

    #[Test]
    public function log_error_delegates_to_error_level(): void
    {
        $this->fakePayloadBuilder([]);
        $this->expectXLogCall('error', 'payment.failed');

        $model = $this->makeModel();
        $model->logError('payment.failed');
    }

    #[Test]
    public function log_critical_delegates_to_critical_level(): void
    {
        $this->fakePayloadBuilder([]);
        $this->expectXLogCall('critical', 'system.down');

        $model = $this->makeModel();
        $model->logCritical('system.down');
    }

    #[Test]
    public function log_falls_back_to_info_for_unsupported_level(): void
    {
        $this->fakePayloadBuilder([]);
        $this->expectXLogCall('info', 'test.event');

        $model = $this->makeModel();
        $model->log('test.event', [], 'debug');
    }

    #[Test]
    public function log_normalizes_level_case_and_whitespace(): void
    {
        $this->fakePayloadBuilder([]);
        $this->expectXLogCall('error', 'test');

        $model = $this->makeModel();
        $model->log('test', [], '  ERROR  ');
    }
}
