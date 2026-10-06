<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Unit\Processors;

use Tt0y\XLog\Processors\RequestProcessor;
use Tt0y\XLog\Tests\TestCase;
use Tt0y\XLog\User\AuthUserResolver;
use Tt0y\XLog\User\Contracts\UserAttributesResolverInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;

final class RequestProcessorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private function makeRecord(array $context = []): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'xlog',
            level: Level::Info,
            message: 'test',
            context: $context,
        );
    }

    private function makeGuardMock(bool $hasUser, ?Authenticatable $user = null): object
    {
        return new class($hasUser, $user) {
            public function __construct(
                private readonly bool $hasUserResult,
                private readonly ?Authenticatable $userResult,
            ) {}

            public function hasUser(): bool
            {
                return $this->hasUserResult;
            }

            public function getUser(): ?Authenticatable
            {
                return $this->userResult;
            }
        };
    }

    private function makeAuthResolver(?Authenticatable $user = null, ?string $guardName = null): AuthUserResolver
    {
        if ($user !== null) {
            $guard = $this->makeGuardMock(true, $user);
            Auth::shouldReceive('guard')->with($guardName ?? 'api')->andReturn($guard);
        } else {
            $guard = $this->makeGuardMock(false);
            Auth::shouldReceive('guard')->andReturn($guard);
        }

        return new AuthUserResolver();
    }

    #[Test]
    public function it_adds_http_context(): void
    {
        $request = Request::create('/api/test', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'example.com',
        ]);

        $route = new Route('POST', '/api/test', fn() => 'ok');
        $route->name('api.test');
        $route->bind($request);
        $request->setRouteResolver(fn() => $route);

        $authResolver = $this->makeAuthResolver();

        $processor = new RequestProcessor($authResolver, null, $request);

        $result = $processor->processLogRecord($this->makeRecord());

        $this->assertSame('POST', $result->context['http']['method']);
        $this->assertSame('example.com', $result->context['http']['host']);
        $this->assertSame('/api/test', $result->context['http']['uri']);
        $this->assertSame('127.0.0.1', $result->context['http']['ip']);
        $this->assertSame('api.test', $result->context['http']['route']);
        $this->assertArrayHasKey('action', $result->context['http']);
    }

    #[Test]
    public function it_adds_user_context(): void
    {
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(42);

        $authResolver = $this->makeAuthResolver($user, 'api');

        $request = Request::create('/test');

        $processor = new RequestProcessor($authResolver, null, $request);

        $result = $processor->processLogRecord($this->makeRecord());

        $this->assertSame('api', $result->context['user']['guard']);
        $this->assertSame(42, $result->context['user']['id']);
        $this->assertSame(get_class($user), $result->context['user']['class']);
    }

    #[Test]
    public function it_merges_custom_user_attributes(): void
    {
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(42);

        $authResolver = $this->makeAuthResolver($user, 'api');

        $attrsResolver = Mockery::mock(UserAttributesResolverInterface::class);
        $attrsResolver->shouldReceive('resolve')->with($user)->once()->andReturn(['tenant_id' => 123]);

        $request = Request::create('/test');

        $processor = new RequestProcessor($authResolver, $attrsResolver, $request);

        $result = $processor->processLogRecord($this->makeRecord());

        $this->assertSame(123, $result->context['user']['tenant_id']);
        $this->assertSame('api', $result->context['user']['guard']);
    }

    #[Test]
    public function it_collects_meta_headers_with_trigger(): void
    {
        $this->app['config']->set('xlog.http.meta_headers', ['X-App-Name', 'X-App-Version']);
        $this->app['config']->set('xlog.http.meta_trigger', 'X-App-Name');

        $request = Request::create('/test', 'GET', [], [], [], [
            'HTTP_X_APP_NAME' => 'MyApp',
            'HTTP_X_APP_VERSION' => '1.2.3',
        ]);

        $authResolver = $this->makeAuthResolver();

        $processor = new RequestProcessor($authResolver, null, $request);

        $result = $processor->processLogRecord($this->makeRecord());

        $this->assertSame('MyApp', $result->context['http']['meta']['X-App-Name']);
        $this->assertSame('1.2.3', $result->context['http']['meta']['X-App-Version']);
    }

    #[Test]
    public function it_does_not_collect_meta_when_trigger_header_absent(): void
    {
        $this->app['config']->set('xlog.http.meta_headers', ['X-App-Name']);
        $this->app['config']->set('xlog.http.meta_trigger', 'X-App-Name');

        $request = Request::create('/test');

        $authResolver = $this->makeAuthResolver();

        $processor = new RequestProcessor($authResolver, null, $request);

        $result = $processor->processLogRecord($this->makeRecord());

        $this->assertArrayNotHasKey('meta', $result->context['http'] ?? []);
    }

    #[Test]
    public function it_adds_source_context_for_cli_command(): void
    {
        $authResolver = $this->makeAuthResolver();

        $processor = new RequestProcessor($authResolver, null, null);

        $result = $processor->processLogRecord($this->makeRecord([
            'command_name' => 'migrate:fresh',
        ]));

        $this->assertSame('cli', $result->context['source']['type']);
        $this->assertSame('migrate:fresh', $result->context['source']['command']);
    }

    #[Test]
    public function it_adds_source_context_for_queue_job(): void
    {
        $authResolver = $this->makeAuthResolver();

        $processor = new RequestProcessor($authResolver, null, null);

        $result = $processor->processLogRecord($this->makeRecord([
            'job_class' => 'App\\Jobs\\SendEmail',
        ]));

        $this->assertSame('queue', $result->context['source']['type']);
        $this->assertSame('App\\Jobs\\SendEmail', $result->context['source']['job']);
    }

    #[Test]
    public function it_adds_unknown_source_without_request_or_context(): void
    {
        $authResolver = $this->makeAuthResolver();

        $processor = new RequestProcessor($authResolver, null, null);

        $result = $processor->processLogRecord($this->makeRecord());

        $this->assertSame('unknown', $result->context['source']['type']);
    }

    #[Test]
    public function it_caches_http_context(): void
    {
        $request = Request::create('/test', 'GET');

        $authResolver = $this->makeAuthResolver();

        $processor = new RequestProcessor($authResolver, null, $request);

        $result1 = $processor->processLogRecord($this->makeRecord());
        $result2 = $processor->processLogRecord($this->makeRecord());

        $this->assertSame($result1->context['http'], $result2->context['http']);
    }

    #[Test]
    public function it_caches_user_context(): void
    {
        $user = Mockery::mock(Authenticatable::class);
        // getAuthIdentifier called once due to caching
        $user->shouldReceive('getAuthIdentifier')->once()->andReturn(42);

        $guard = $this->makeGuardMock(true, $user);
        // guard() called only once
        Auth::shouldReceive('guard')->with('api')->once()->andReturn($guard);

        $authResolver = new AuthUserResolver();

        $request = Request::create('/test');

        $processor = new RequestProcessor($authResolver, null, $request);

        $processor->processLogRecord($this->makeRecord());
        $processor->processLogRecord($this->makeRecord());
    }

    #[Test]
    public function reset_clears_caches(): void
    {
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->twice()->andReturn(42);

        $guard = $this->makeGuardMock(true, $user);
        Auth::shouldReceive('guard')->with('api')->twice()->andReturn($guard);

        $authResolver = new AuthUserResolver();

        $request = Request::create('/test');

        $processor = new RequestProcessor($authResolver, null, $request);

        $processor->processLogRecord($this->makeRecord());

        // Reset both the processor and the auth resolver caches
        $processor->reset();
        $authResolver->reset();

        $processor->processLogRecord($this->makeRecord());
    }

    #[Test]
    public function no_user_context_when_unauthenticated(): void
    {
        $authResolver = $this->makeAuthResolver();

        $request = Request::create('/test');

        $processor = new RequestProcessor($authResolver, null, $request);

        $result = $processor->processLogRecord($this->makeRecord());

        $this->assertArrayNotHasKey('user', $result->context);
    }
}
