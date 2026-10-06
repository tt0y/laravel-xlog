<?php

declare(strict_types=1);

namespace Tt0y\XLog\Context;

/**
 * Context object holding correlation and request IDs.
 */
final readonly class IdContext
{
    public function __construct(
        public string $correlationId,
        public string $requestId,
    ) {}
}