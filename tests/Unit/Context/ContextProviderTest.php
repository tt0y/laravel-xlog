<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Unit\Context;

use Tt0y\XLog\Context\ContextProvider;
use Tt0y\XLog\Context\IdContext;
use Tt0y\XLog\Tests\TestCase;
use Tt0y\XLog\User\Contracts\UserIdResolverInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Test;

final class ContextProviderTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private UserIdResolverInterface&Mockery\MockInterface $userIdResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userIdResolver = Mockery::mock(UserIdResolverInterface::class);

        $this->app->bind(IdContext::class, fn() => new IdContext('corr-id-1', 'req-id-1'));
    }

    #[Test]
    public function base_builds_context_with_all_fields(): void
    {
        $this->userIdResolver->shouldReceive('resolve')->once()->andReturn(42);

        $provider = new ContextProvider($this->app, $this->userIdResolver);
        $base = $provider->base();

        $this->assertSame('test-service', $base['service']);
        $this->assertSame('testing', $base['env']);
        $this->assertSame('test-instance', $base['instance']);
        $this->assertSame('corr-id-1', $base['correlation_id']);
        $this->assertSame('req-id-1', $base['request_id']);
        $this->assertSame(42, $base['user_id']);
    }

    #[Test]
    public function base_filters_null_and_empty_values(): void
    {
        $this->userIdResolver->shouldReceive('resolve')->once()->andReturn(null);

        $this->app['config']->set('xlog.instance', null);

        $provider = new ContextProvider($this->app, $this->userIdResolver);
        $base = $provider->base();

        $this->assertArrayNotHasKey('instance', $base);
        $this->assertArrayNotHasKey('user_id', $base);
    }

    #[Test]
    public function base_caches_result_on_second_call(): void
    {
        $this->userIdResolver->shouldReceive('resolve')->once()->andReturn(1);

        $provider = new ContextProvider($this->app, $this->userIdResolver);
        $first = $provider->base();
        $second = $provider->base();

        $this->assertSame($first, $second);
    }

    #[Test]
    public function with_merges_extra_on_top_of_base(): void
    {
        $this->userIdResolver->shouldReceive('resolve')->once()->andReturn(1);

        $provider = new ContextProvider($this->app, $this->userIdResolver);

        $result = $provider->with(['custom_key' => 'value', 'service' => 'overridden']);

        // Extra keys win (+ operator: left side wins)
        $this->assertSame('overridden', $result['service']);
        $this->assertSame('value', $result['custom_key']);
        // Base keys still present
        $this->assertSame('corr-id-1', $result['correlation_id']);
    }

    #[Test]
    public function reset_clears_cached_base(): void
    {
        $this->userIdResolver->shouldReceive('resolve')->twice()->andReturn(1, 2);

        $provider = new ContextProvider($this->app, $this->userIdResolver);

        $first = $provider->base();
        $this->assertSame(1, $first['user_id']);

        $provider->reset();

        $second = $provider->base();
        $this->assertSame(2, $second['user_id']);
    }
}
