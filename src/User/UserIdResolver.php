<?php

declare(strict_types=1);

namespace Tt0y\XLog\User;

use Tt0y\XLog\User\Contracts\UserIdResolverInterface;

/**
 * Default implementation that resolves user ID from Laravel's auth system.
 *
 * Supports both integer and string (UUID) identifiers.
 */
final readonly class UserIdResolver implements UserIdResolverInterface
{
    public function __construct(
        private AuthUserResolver $authUserResolver,
    ) {
    }

    public function resolve(): int|string|null
    {
        return $this->authUserResolver->resolveUserId();
    }
}