<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Unit;

use Tt0y\XLog\Context\IdContext;
use Tt0y\XLog\Facades\XLog;
use Tt0y\XLog\Logger\XLogger;
use Tt0y\XLog\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class XLogServiceProviderTest extends TestCase
{
    #[Test]
    public function id_context_binding_does_not_fail_when_request_is_null(): void
    {
        // Simulate CLI context by clearing the request from the container
        $this->app->forgetInstance('request');
        $this->app->bind('request', fn () => null);

        $idContext = $this->app->make(IdContext::class);

        $this->assertInstanceOf(IdContext::class, $idContext);
        $this->assertSame('n/a', $idContext->correlationId);
        $this->assertSame('n/a', $idContext->requestId);
    }

    #[Test]
    public function facade_resolves_to_the_logger(): void
    {
        $this->assertInstanceOf(XLogger::class, XLog::getFacadeRoot());
    }
}
