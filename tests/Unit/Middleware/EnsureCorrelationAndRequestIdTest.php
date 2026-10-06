<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Unit\Middleware;

use Tt0y\XLog\Middleware\EnsureCorrelationAndRequestId;
use Tt0y\XLog\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\Test;

final class EnsureCorrelationAndRequestIdTest extends TestCase
{
    private function handle(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        return (new EnsureCorrelationAndRequestId())->handle($request, fn () => new Response('ok'));
    }

    #[Test]
    public function it_strips_control_characters_from_correlation_id(): void
    {
        $request = Request::create('/test');
        $request->headers->set('X-Correlation-Id', "malicious\ninjection\r\nattack");

        $this->handle($request);

        $correlationId = $request->attributes->get(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID);

        $this->assertSame('maliciousinjectionattack', $correlationId);
    }

    #[Test]
    public function it_strips_control_characters_from_request_id(): void
    {
        $request = Request::create('/test');
        $request->headers->set('X-Request-Id', "id-with\ttab\x00null");

        $this->handle($request);

        $requestId = $request->attributes->get(EnsureCorrelationAndRequestId::ATTR_REQUEST_ID);

        $this->assertSame('id-withtabnull', $requestId);
    }

    #[Test]
    public function it_truncates_overly_long_trace_ids(): void
    {
        $longId = str_repeat('a', 200);
        $request = Request::create('/test');
        $request->headers->set('X-Correlation-Id', $longId);

        $this->handle($request);

        $correlationId = $request->attributes->get(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID);

        $this->assertSame(128, strlen($correlationId));
    }

    #[Test]
    public function it_returns_null_for_header_with_only_control_characters(): void
    {
        $request = Request::create('/test');
        $request->headers->set('X-Correlation-Id', "\n\r\t\x00");

        $this->handle($request);

        $correlationId = $request->attributes->get(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID);

        // Should be a generated UUID, not the sanitized (empty) input
        $this->assertNotNull($correlationId);
        $this->assertNotSame('', $correlationId);
        // Generated UUIDs contain dashes
        $this->assertStringContainsString('-', $correlationId);
    }

    #[Test]
    public function it_passes_valid_uuid_through_unchanged(): void
    {
        $uuid = '01935f6a-7c3b-7f00-8d4e-2a1b3c4d5e6f';
        $request = Request::create('/test');
        $request->headers->set('X-Correlation-Id', $uuid);

        $this->handle($request);

        $correlationId = $request->attributes->get(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID);

        $this->assertSame($uuid, $correlationId);
    }

    #[Test]
    public function it_generates_ids_when_headers_are_missing(): void
    {
        $request = Request::create('/test');

        $this->handle($request);

        $correlationId = $request->attributes->get(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID);
        $requestId = $request->attributes->get(EnsureCorrelationAndRequestId::ATTR_REQUEST_ID);

        $this->assertNotNull($correlationId);
        $this->assertNotNull($requestId);
        $this->assertNotSame('', $correlationId);
        $this->assertNotSame('', $requestId);
    }
}
