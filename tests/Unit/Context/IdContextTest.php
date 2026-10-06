<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Unit\Context;

use Tt0y\XLog\Context\IdContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IdContextTest extends TestCase
{
    #[Test]
    public function it_stores_correlation_and_request_ids(): void
    {
        $ctx = new IdContext('corr-123', 'req-456');

        $this->assertSame('corr-123', $ctx->correlationId);
        $this->assertSame('req-456', $ctx->requestId);
    }

    #[Test]
    public function it_is_readonly(): void
    {
        $ref = new \ReflectionClass(IdContext::class);

        $this->assertTrue($ref->isReadOnly());
    }
}
