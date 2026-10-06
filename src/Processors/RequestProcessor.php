<?php

declare(strict_types=1);

namespace Tt0y\XLog\Processors;

use Tt0y\XLog\User\AuthUserResolver;
use Tt0y\XLog\User\Contracts\UserAttributesResolverInterface;
use Illuminate\Http\Request;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Monolog processor that enriches log records with HTTP and user context.
 *
 * Adds:
 * - context.http: method, host, uri, ip, route, action, meta (configurable headers)
 * - context.user: guard, class, id, plus custom attributes from UserAttributesResolverInterface
 * - context.source: type, job/command (for CLI/queue contexts)
 *
 * Performance: HTTP and user context are cached for the request lifetime.
 */
final class RequestProcessor
{
    /** @var array<string, mixed>|null Cached HTTP context */
    private ?array $cachedHttpContext = null;

    /** @var array<string, mixed>|null Cached user context */
    private ?array $cachedUserContext = null;

    /** @var bool Whether user context has been resolved */
    private bool $userResolved = false;

    /** @var array<string>|null Cached meta headers config */
    private ?array $cachedMetaHeaders = null;

    /** @var string|null Cached meta trigger config */
    private ?string $cachedMetaTrigger = null;

    /** @var bool Whether config has been loaded */
    private bool $configLoaded = false;

    public function __construct(
        private readonly AuthUserResolver $authUserResolver,
        private readonly ?UserAttributesResolverInterface $userAttributesResolver = null,
        private readonly ?Request $request = null,
    ) {
    }

    public function __invoke(Logger|LogRecord $data): LogRecord|null
    {
        if ($data instanceof Logger) {
            foreach ($data->getHandlers() as $handler) {
                $handler->pushProcessor($this);
            }
            return null;
        }

        return $this->processLogRecord($data);
    }

    public function processLogRecord(LogRecord $record): LogRecord
    {
        $context = $record->context;

        // Attach cached user context
        $userContext = $this->getUserContext();
        if ($userContext !== null) {
            $context['user'] = $userContext;
        }

        // Attach HTTP or source context
        if ($this->request) {
            $httpContext = $this->getHttpContext();
            if ($httpContext !== []) {
                $context['http'] = $httpContext;
            }
        } else {
            $source = $this->getSource($record);
            if ($source !== []) {
                $context['source'] = $source;
            }
        }

        return $record->with(context: $context);
    }

    /**
     * Get cached HTTP context (built once per request).
     *
     * @return array<string, mixed>
     */
    private function getHttpContext(): array
    {
        if ($this->cachedHttpContext !== null) {
            return $this->cachedHttpContext;
        }

        if (!$this->request) {
            $this->cachedHttpContext = [];
            return $this->cachedHttpContext;
        }

        $route = $this->request->route();

        // Build HTTP context without array_filter for performance
        $http = [];

        $method = $this->request->getMethod();
        if ($method !== '') {
            $http['method'] = $method;
        }

        $host = $this->request->getHost();
        if ($host !== '') {
            $http['host'] = $host;
        }

        $uri = $this->request->getRequestUri();
        if ($uri !== null && $uri !== '') {
            $http['uri'] = $uri;
        }

        $ip = $this->request->getClientIp();
        if ($ip !== null && $ip !== '') {
            $http['ip'] = $ip;
        }

        if ($route !== null) {
            $routeName = $route->getName();
            if ($routeName !== null && $routeName !== '') {
                $http['route'] = $routeName;
            }

            $action = $route->getActionName();
            if ($action !== null && $action !== '') {
                $http['action'] = $action;
            }
        }

        // Add meta headers if configured
        $meta = $this->collectMetaHeaders();
        if ($meta !== []) {
            $http['meta'] = $meta;
        }

        $this->cachedHttpContext = $http;
        return $this->cachedHttpContext;
    }

    /**
     * Get cached user context (built once per request).
     *
     * @return array<string, mixed>|null
     */
    private function getUserContext(): ?array
    {
        if ($this->userResolved) {
            return $this->cachedUserContext;
        }

        $this->userResolved = true;

        [$user, $guard] = $this->authUserResolver->resolveUser();

        if (!$user) {
            $this->cachedUserContext = null;
            return null;
        }

        $payload = [
            'guard' => (string) $guard,
            'class' => $user::class,
        ];

        $id = $user->getAuthIdentifier();
        if ($id !== null && $id !== '') {
            $payload['id'] = $id;
        }

        // Merge custom user attributes from resolver
        if ($this->userAttributesResolver !== null) {
            $customAttrs = $this->userAttributesResolver->resolve($user);
            if (is_array($customAttrs) && $customAttrs !== []) {
                $payload = array_merge($payload, $customAttrs);
            }
        }

        $this->cachedUserContext = $payload;
        return $this->cachedUserContext;
    }

    /**
     * Collect meta headers based on configuration.
     * Config is cached after first load.
     *
     * @return array<string, string>
     */
    private function collectMetaHeaders(): array
    {
        if (!$this->request) {
            return [];
        }

        // Load config once
        if (!$this->configLoaded) {
            $headers = config('xlog.http.meta_headers', []);
            $this->cachedMetaHeaders = is_array($headers) ? $headers : [];
            $trigger = config('xlog.http.meta_trigger');
            $this->cachedMetaTrigger = is_string($trigger) && $trigger !== '' ? $trigger : null;
            $this->configLoaded = true;
        }

        if ($this->cachedMetaHeaders === []) {
            return [];
        }

        if ($this->cachedMetaTrigger !== null && !$this->request->hasHeader($this->cachedMetaTrigger)) {
            return [];
        }

        $meta = [];
        foreach ($this->cachedMetaHeaders as $header) {
            if (!is_string($header) || $header === '') {
                continue;
            }

            $value = $this->request->header($header);
            if (is_string($value) && $value !== '') {
                $meta[$header] = $value;
            }
        }

        return $meta;
    }

    /**
     * Build source descriptor for CLI/queue contexts.
     * Not cached because it reads from record context.
     */
    private function getSource(LogRecord $record): array
    {
        $ctx = $record->context ?? [];

        if (!empty($ctx['job_class'])) {
            return ['type' => 'queue', 'job' => (string) $ctx['job_class']];
        }

        if (!empty($ctx['command_name'])) {
            return ['type' => 'cli', 'command' => (string) $ctx['command_name']];
        }

        return ['type' => 'unknown'];
    }

    /**
     * Reset cache for long-running processes (queues, octane).
     */
    public function reset(): void
    {
        $this->cachedHttpContext = null;
        $this->cachedUserContext = null;
        $this->userResolved = false;
    }
}