<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Unit\Context;

use Tt0y\XLog\Context\RequestTraceContext;
use Tt0y\XLog\Middleware\EnsureCorrelationAndRequestId;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequestTraceContextTest extends TestCase
{
    #[Test]
    public function request_id_returns_id_from_request_attributes(): void
    {
        $request = Request::create('/test');
        $request->attributes->set(EnsureCorrelationAndRequestId::ATTR_REQUEST_ID, 'req-abc');

        $ctx = new RequestTraceContext($request);

        $this->assertSame('req-abc', $ctx->requestId());
    }

    #[Test]
    public function correlation_id_returns_id_from_request_attributes(): void
    {
        $request = Request::create('/test');
        $request->attributes->set(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID, 'corr-abc');

        $ctx = new RequestTraceContext($request);

        $this->assertSame('corr-abc', $ctx->correlationId());
    }

    #[Test]
    #[DataProvider('emptyValueProvider')]
    public function request_id_returns_null_for_empty_values(mixed $value): void
    {
        $request = Request::create('/test');
        $request->attributes->set(EnsureCorrelationAndRequestId::ATTR_REQUEST_ID, $value);

        $ctx = new RequestTraceContext($request);

        $this->assertNull($ctx->requestId());
    }

    #[Test]
    #[DataProvider('emptyValueProvider')]
    public function correlation_id_returns_null_for_empty_values(mixed $value): void
    {
        $request = Request::create('/test');
        $request->attributes->set(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID, $value);

        $ctx = new RequestTraceContext($request);

        $this->assertNull($ctx->correlationId());
    }

    public static function emptyValueProvider(): array
    {
        return [
            'empty string' => [''],
            'n/a' => ['n/a'],
            'whitespace' => ['  '],
            'null' => [null],
        ];
    }

    #[Test]
    public function enrich_error_response_adds_request_id(): void
    {
        $request = Request::create('/test');
        $request->attributes->set(EnsureCorrelationAndRequestId::ATTR_REQUEST_ID, 'req-123');

        $ctx = new RequestTraceContext($request);

        $response = [];
        $ctx->enrichErrorResponse($response);

        $this->assertSame('req-123', $response['error']['request_id']);
        $this->assertArrayNotHasKey('correlation_id', $response['error']);
    }

    #[Test]
    public function enrich_error_response_adds_correlation_id_when_requested(): void
    {
        $request = Request::create('/test');
        $request->attributes->set(EnsureCorrelationAndRequestId::ATTR_REQUEST_ID, 'req-123');
        $request->attributes->set(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID, 'corr-456');

        $ctx = new RequestTraceContext($request);

        $response = [];
        $ctx->enrichErrorResponse($response, withCorrelationId: true);

        $this->assertSame('req-123', $response['error']['request_id']);
        $this->assertSame('corr-456', $response['error']['correlation_id']);
    }

    #[Test]
    public function enrich_error_response_preserves_existing_error_data(): void
    {
        $request = Request::create('/test');
        $request->attributes->set(EnsureCorrelationAndRequestId::ATTR_REQUEST_ID, 'req-123');

        $ctx = new RequestTraceContext($request);

        $response = ['error' => ['message' => 'Something failed']];
        $ctx->enrichErrorResponse($response);

        $this->assertSame('Something failed', $response['error']['message']);
        $this->assertSame('req-123', $response['error']['request_id']);
    }
}
