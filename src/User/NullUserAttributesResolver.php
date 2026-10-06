<?php

declare(strict_types=1);

namespace Tt0y\XLog\User;

use Tt0y\XLog\User\Contracts\UserAttributesResolverInterface;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Default implementation that returns no additional user attributes.
 *
 * Use this when you don't need to add project-specific user attributes to logs.
 * To add custom attributes, create your own implementation of UserAttributesResolverInterface
 * and register it in config/xlog.php under 'user.attributes_resolver'.
 */
final readonly class NullUserAttributesResolver implements UserAttributesResolverInterface
{
    public function resolve(Authenticatable $user): array
    {
        return [];
    }
}
