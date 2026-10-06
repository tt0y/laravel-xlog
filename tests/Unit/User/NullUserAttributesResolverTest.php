<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Unit\User;

use Tt0y\XLog\User\NullUserAttributesResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NullUserAttributesResolverTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    #[Test]
    public function it_always_returns_empty_array(): void
    {
        $user = Mockery::mock(Authenticatable::class);

        $resolver = new NullUserAttributesResolver();

        $this->assertSame([], $resolver->resolve($user));
    }
}
