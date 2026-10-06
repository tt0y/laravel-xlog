<?php

declare(strict_types=1);

namespace Tt0y\XLog\User\Contracts;

/**
 * Contract for resolving the current user's identifier for logging.
 *
 * The default implementation extracts the ID from Laravel's authentication system.
 * Custom implementations can be registered to support different ID types or sources.
 */
interface UserIdResolverInterface
{
    /**
     * Resolve the current authenticated user's identifier.
     *
     * @return int|string|null User ID (int or string for UUID), or null if not authenticated
     */
    public function resolve(): int|string|null;
}