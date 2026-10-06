<?php

declare(strict_types=1);

namespace Tt0y\XLog\User\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Contract for resolving additional user attributes for logging.
 *
 * Implement this interface to add project-specific user attributes to logs.
 * For example, you might add organization_id, tenant_id, etc.
 */
interface UserAttributesResolverInterface
{
    /**
     * Resolve additional attributes for the given user.
     *
     * These attributes will be merged into the context.user object in logs.
     * Return an empty array if no additional attributes are needed.
     *
     * @param Authenticatable $user The authenticated user
     * @return array<string, mixed> Key-value pairs of additional attributes
     */
    public function resolve(Authenticatable $user): array;
}
