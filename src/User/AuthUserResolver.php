<?php

declare(strict_types=1);

namespace Tt0y\XLog\User;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves the authenticated user from Laravel's auth guards.
 *
 * Guards are checked in the order specified in config('xlog.user.guards').
 * The first guard with an authenticated user wins.
 *
 * Results are cached for the request lifetime to avoid repeated auth checks.
 */
final class AuthUserResolver
{
    /**
     * Default guards to check if config is not set.
     *
     * @var list<string>
     */
    private const array DEFAULT_GUARDS = ['api', 'web'];

    /** @var array{0: Authenticatable|null, 1: string|null}|null */
    private ?array $cachedResult = null;
    private bool $resolved = false;

    /**
     * Resolve authenticated user WITHOUT hitting DB.
     * Result is cached for the lifetime of the request.
     *
     * @return array{0: Authenticatable|null, 1: string|null}
     */
    public function resolveUser(): array
    {
        if ($this->resolved) {
            return $this->cachedResult ?? [null, null];
        }

        foreach ($this->getGuards() as $guardName) {
            $guard = Auth::guard($guardName);

            if (!method_exists($guard, 'hasUser') || !$guard->hasUser()) {
                continue;
            }

            // Only SessionGuard has getUser(); RequestGuard (Sanctum, Passport, viaRequest) does not.
            // After hasUser() is true, user() returns the already resolved user and authenticates nothing.
            $user = method_exists($guard, 'getUser') ? $guard->getUser() : $guard->user();

            if ($user instanceof Authenticatable) {
                $this->cachedResult = [$user, $guardName];
                $this->resolved = true;

                return $this->cachedResult;
            }
        }

        $this->cachedResult = [null, null];
        $this->resolved = true;

        return $this->cachedResult;
    }

    /**
     * Reset cache for long-running processes (queues, octane).
     */
    public function reset(): void
    {
        $this->resolved = false;
        $this->cachedResult = null;
    }

    /**
     * Resolve the user's identifier.
     *
     * @return int|string|null User ID or null if not authenticated
     */
    public function resolveUserId(): int|string|null
    {
        [$user] = $this->resolveUser();
        if (!$user) {
            return null;
        }

        $id = $user->getAuthIdentifier();

        // Return as-is for string IDs (UUID, etc.)
        if (is_string($id) && $id !== '') {
            // Convert numeric strings to int for consistency
            if (ctype_digit($id)) {
                return (int)$id;
            }
            return $id;
        }

        if (is_int($id)) {
            return $id;
        }

        if (is_numeric($id)) {
            return (int)$id;
        }

        return null;
    }

    /**
     * Get the list of guards to check from config.
     *
     * @return list<string>
     */
    private function getGuards(): array
    {
        $guards = config('xlog.user.guards');

        if (!is_array($guards) || $guards === []) {
            $guards = self::DEFAULT_GUARDS;
        }

        // Auth::guard() throws on a guard the application does not define (e.g. 'api' in a fresh
        // Laravel 11+ app), and that would turn every log call into a 500.
        $definedGuards = config('auth.guards', []);

        return array_values(array_filter(
            $guards,
            static fn(mixed $guard): bool => is_string($guard) && isset($definedGuards[$guard]),
        ));
    }
}