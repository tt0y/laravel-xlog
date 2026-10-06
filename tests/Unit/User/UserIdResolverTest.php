<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Unit\User;

use Tt0y\XLog\Tests\TestCase;
use Tt0y\XLog\User\AuthUserResolver;
use Tt0y\XLog\User\UserIdResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Test;

final class UserIdResolverTest extends TestCase
{
    use MockeryPHPUnitIntegration;

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
    public function it_delegates_to_auth_user_resolver(): void
    {
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn(42);

        $guard = $this->makeGuardMock(true, $user);
        Auth::shouldReceive('guard')->with('api')->once()->andReturn($guard);

        $authResolver = new AuthUserResolver();
        $resolver = new UserIdResolver($authResolver);

        $this->assertSame(42, $resolver->resolve());
    }

    #[Test]
    public function it_returns_null_when_no_user(): void
    {
        $guard = $this->makeGuardMock(false);
        Auth::shouldReceive('guard')->with('api')->once()->andReturn($guard);
        Auth::shouldReceive('guard')->with('web')->once()->andReturn($guard);

        $authResolver = new AuthUserResolver();
        $resolver = new UserIdResolver($authResolver);

        $this->assertNull($resolver->resolve());
    }

    #[Test]
    public function it_returns_string_uuid(): void
    {
        $user = Mockery::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->andReturn('uuid-string');

        $guard = $this->makeGuardMock(true, $user);
        Auth::shouldReceive('guard')->with('api')->once()->andReturn($guard);

        $authResolver = new AuthUserResolver();
        $resolver = new UserIdResolver($authResolver);

        $this->assertSame('uuid-string', $resolver->resolve());
    }
}
