<?php

declare(strict_types=1);

namespace Tt0y\XLog\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Middleware to ensure that each request has a correlation ID and request ID.
 *
 * This middleware checks incoming HTTP requests for the presence of correlation
 * and request ID headers. If they are missing, it generates new IDs. It also
 * attaches these IDs to the request attributes for downstream usage and shares
 * them with Laravel's logging context. Finally, it adds the IDs to the response
 * headers.
 *
 * How to get IDs in downstream code:
 * $correlationId = request()->attributes->get(\Tt0y\XLog\Middleware\EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID);
 * $requestId = request()->attributes->get(\Tt0y\XLog\Middleware\EnsureCorrelationAndRequestId::ATTR_REQUEST_ID);
 */
final class EnsureCorrelationAndRequestId
{
    public const ATTR_CORRELATION_ID = 'xlog.correlation_id';
    public const ATTR_REQUEST_ID = 'xlog.request_id';

    public function handle(Request $request, Closure $next): Response
    {
        // Skip ID generation when XLog is disabled
        if (!config('xlog.enabled', true)) {
            return $next($request);
        }

        $correlationHeader = (string)config('xlog.headers.correlation', 'X-Correlation-Id');
        $requestHeader = (string)config('xlog.headers.request', 'X-Request-Id');

        // 1) correlation_id: X-Correlation-Id -> fallback X-Request-Id -> generate
        $incomingCorrelationId = $this->trimHeader($request->headers->get($correlationHeader));
        $incomingRequestId = $this->trimHeader($request->headers->get($requestHeader));

        // Resolve correlation_id
        if ($incomingCorrelationId !== null) {
            $correlationId = $incomingCorrelationId;
        } elseif ($incomingRequestId !== null) {
            // Fallback for backward compatibility
            $correlationId = $incomingRequestId;
        } else {
            $correlationId = $this->newId();
        }

        // Resolve request_id (always local)
        $requestId = $incomingRequestId ?? $this->newId();

        // Store in request attributes for downstream usage
        $request->attributes->set(self::ATTR_CORRELATION_ID, $correlationId);
        $request->attributes->set(self::ATTR_REQUEST_ID, $requestId);

        // Carry the ids into anything this request queues.
        //
        // Laravel serializes the context repository into every job payload and restores it in the
        // worker (`Illuminate\Log\Context\ContextServiceProvider`), which is the only transport
        // that survives the queue — request attributes and `Log::shareContext()` below do not, so
        // without this a job's log lines carry `correlation_id: n/a` and cannot be joined to the
        // request that caused them.
        //
        // **Hidden, deliberately.** Visible context is copied into the `extra` of every log record
        // by the framework's own processor, which would put a second, differently-named copy of
        // these ids on every line in the application. Hidden context is still dehydrated into the
        // payload; it simply never reaches a log record.
        //
        // The values are the sanitized ones from above, so nothing a caller put in a header
        // reaches a job payload raw.
        Context::addHidden(self::ATTR_CORRELATION_ID, $correlationId);
        Context::addHidden(self::ATTR_REQUEST_ID, $requestId);

        // 2) Share context to Laravel logs
        // This attaches to all logs emitted in the current request lifecycle.
        Log::shareContext(array_filter([
            'correlation_id' => $correlationId,
            'request_id' => $requestId,
            'service' => config('xlog.service'),
            'env' => config('xlog.env'),
            'instance' => config('xlog.instance'),
        ], static fn($v) => $v !== null && $v !== ''));

        /** @var Response $response */
        $response = $next($request);

        // 3) Return headers back
        $response->headers->set($correlationHeader, $correlationId);
        $response->headers->set($requestHeader, $requestId);

        return $response;
    }

    /**
     * Max allowed length for trace ID headers to prevent abuse.
     */
    private const int MAX_TRACE_ID_LENGTH = 128;

    private function trimHeader(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return $this->sanitizeTraceId($value);
    }

    /**
     * Sanitize trace ID: strip control characters, restrict to safe chars, enforce max length.
     * Prevents log injection/forging via crafted headers.
     */
    private function sanitizeTraceId(string $value): ?string
    {
        // Strip control characters (newlines, tabs, etc.)
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);

        // Enforce max length
        if (strlen($value) > self::MAX_TRACE_ID_LENGTH) {
            $value = substr($value, 0, self::MAX_TRACE_ID_LENGTH);
        }

        return $value !== '' ? $value : null;
    }

    private function newId(): string
    {
        return (string) Uuid::v7();
    }
}