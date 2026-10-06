<?php

declare(strict_types=1);

namespace Tt0y\XLog\Context;

use Tt0y\XLog\User\Contracts\UserIdResolverInterface;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;

final class ContextProvider
{
    /** @var array<string, mixed>|null */
    private ?array $cachedBase = null;

    public function __construct(
        private readonly Container $app,
        private readonly UserIdResolverInterface $userIdResolver,
    ) {
    }

    /**
     * Base context that should be present in every log line.
     * Cached for the lifetime of the request.
     *
     * @return array<string, mixed>
     * @throws BindingResolutionException
     */
    public function base(): array
    {
        if ($this->cachedBase !== null) {
            return $this->cachedBase;
        }

        /** @var IdContext $idContext */
        $idContext = $this->app->make(IdContext::class);

        $this->cachedBase = array_filter([
            'service' => (string)config('xlog.service', config('app.name', 'app')),
            'env' => (string)config('xlog.env', config('app.env', 'local')),
            'instance' => config('xlog.instance'),
            'correlation_id' => $idContext->correlationId,
            'request_id' => $idContext->requestId,
            'user_id' => $this->userIdResolver->resolve(),
        ], static fn($v) => $v !== null && $v !== '');

        return $this->cachedBase;
    }

    /**
     * @throws BindingResolutionException
     */
    public function with(array $extra): array
    {
        // Extra wins if same keys are passed explicitly by caller
        return $extra + $this->base();
    }

    /**
     * Reset cache for long-running processes (queues, octane).
     */
    public function reset(): void
    {
        $this->cachedBase = null;
    }
}