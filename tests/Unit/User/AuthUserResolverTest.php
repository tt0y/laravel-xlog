<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Unit\User;

use Tt0y\XLog\Tests\TestCase;
use Tt0y\XLog\User\AuthUserResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Test;

final class AuthUserResolverTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * Create a guard mock that has hasUser() and getUser() methods,
     * which AuthUserResolver checks via method_exists().
     */
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

    #[Test]
    public function resolves_user_from_first_guard_with_authenticated_user(): void
    {
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(42);

        $guard = $this->makeGuardMock(true, $user);

        Auth::shouldReceive('guard')->with('api')->once()->andReturn($guard);

        $resolver = new AuthUserResolver();
        [$resolvedUser, $guardName] = $resolver->resolveUser();

        $this->assertSame($user, $resolvedUser);
        $this->assertSame('api', $guardName);
    }

    #[Test]
    public function skips_guards_not_defined_by_the_application(): void
    {
        config()->set('auth.guards', ['web' => ['driver' => 'session', 'provider' => 'users']]);

        $user = Mockery::mock(Authenticatable::class);
        $guard = $this->makeGuardMock(true, $user);

        Auth::shouldReceive('guard')->with('api')
            ->andThrow(new InvalidArgumentException('Auth guard [api] is not defined.'));
        Auth::shouldReceive('guard')->with('web')->once()->andReturn($guard);

        $resolver = new AuthUserResolver();
        [$resolvedUser, $guardName] = $resolver->resolveUser();

        $this->assertSame($user, $resolvedUser);
        $this->assertSame('web', $guardName);
    }

    #[Test]
    public function returns_null_pair_if_no_authenticated_user(): void
    {
        $guard = $this->makeGuardMock(false);

        Auth::shouldReceive('guard')->with('api')->once()->andReturn($guard);
        Auth::shouldReceive('guard')->with('web')->once()->andReturn($guard);

        $resolver = new AuthUserResolver();
        [$user, $guardName] = $resolver->resolveUser();

        $this->assertNull($user);
        $this->assertNull($guardName);
    }

    #[Test]
    public function caches_result_on_subsequent_calls(): void
    {
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);

        $guard = $this->makeGuardMock(true, $user);

        // Called only once due to caching
        Auth::shouldReceive('guard')->with('api')->once()->andReturn($guard);

        $resolver = new AuthUserResolver();
        $resolver->resolveUser();
        $resolver->resolveUser();
    }

    #[Test]
    public function reset_clears_cache(): void
    {
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);

        $guard = $this->makeGuardMock(true, $user);

        // Called twice because reset clears cache
        Auth::shouldReceive('guard')->with('api')->twice()->andReturn($guard);

        $resolver = new AuthUserResolver();
        $resolver->resolveUser();
        $resolver->reset();
        $resolver->resolveUser();
    }

    #[Test]
    public function resolve_user_id_returns_int_id(): void
    {
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(42);

        $guard = $this->makeGuardMock(true, $user);

        Auth::shouldReceive('guard')->with('api')->once()->andReturn($guard);

        $resolver = new AuthUserResolver();
        $this->assertSame(42, $resolver->resolveUserId());
    }

    #[Test]
    public function resolve_user_id_returns_string_uuid(): void
    {
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn('550e8400-e29b-41d4-a716-446655440000');

        $guard = $this->makeGuardMock(true, $user);

        Auth::shouldReceive('guard')->with('api')->once()->andReturn($guard);

        $resolver = new AuthUserResolver();
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $resolver->resolveUserId());
    }

    #[Test]
    public function resolve_user_id_converts_numeric_string_to_int(): void
    {
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn('123');

        $guard = $this->makeGuardMock(true, $user);

        Auth::shouldReceive('guard')->with('api')->once()->andReturn($guard);

        $resolver = new AuthUserResolver();
        $this->assertSame(123, $resolver->resolveUserId());
    }

    #[Test]
    public function resolve_user_id_returns_null_when_no_user(): void
    {
        $guard = $this->makeGuardMock(false);

        Auth::shouldReceive('guard')->with('api')->once()->andReturn($guard);
        Auth::shouldReceive('guard')->with('web')->once()->andReturn($guard);

        $resolver = new AuthUserResolver();
        $this->assertNull($resolver->resolveUserId());
    }

    #[Test]
    public function get_guards_uses_config_values(): void
    {
        $this->app['config']->set('auth.guards.custom_guard', ['driver' => 'session', 'provider' => 'users']);
        $this->app['config']->set('xlog.user.guards', ['custom_guard']);

        $guard = $this->makeGuardMock(false);

        Auth::shouldReceive('guard')->with('custom_guard')->once()->andReturn($guard);

        $resolver = new AuthUserResolver();
        $resolver->resolveUser();
    }

    #[Test]
    public function get_guards_falls_back_to_default_when_config_empty(): void
    {
        $this->app['config']->set('xlog.user.guards', []);

        $guard = $this->makeGuardMock(false);

        Auth::shouldReceive('guard')->with('api')->once()->andReturn($guard);
        Auth::shouldReceive('guard')->with('web')->once()->andReturn($guard);

        $resolver = new AuthUserResolver();
        $resolver->resolveUser();
    }

    #[Test]
    public function resolve_retries_after_guard_exception(): void
    {
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(99);

        $guard = $this->makeGuardMock(true, $user);

        $callCount = 0;

        // First call throws, second call succeeds
        Auth::shouldReceive('guard')
            ->with('api')
            ->twice()
            ->andReturnUsing(function () use (&$callCount, $guard) {
                $callCount++;
                if ($callCount === 1) {
                    throw new \RuntimeException('Redis timeout');
                }
                return $guard;
            });

        $resolver = new AuthUserResolver();

        // First call — exception, resolved flag should NOT stick
        try {
            $resolver->resolveUser();
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException) {
            // expected
        }

        // Second call should retry (not return cached [null, null])
        [$resolvedUser, $guardName] = $resolver->resolveUser();

        $this->assertSame($user, $resolvedUser);
        $this->assertSame('api', $guardName);
    }
}
