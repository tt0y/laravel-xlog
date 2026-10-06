<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Unit\Middleware;

use Tt0y\XLog\Middleware\EnrichJsonResponseWithRequestId;
use Tt0y\XLog\Middleware\EnsureCorrelationAndRequestId;
use Tt0y\XLog\Tests\TestCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

final class EnrichJsonResponseWithRequestIdTest extends TestCase
{
    private function makeRequest(string $requestId = 'req-123'): Request
    {
        $request = Request::create('/api/test');
        $request->attributes->set(EnsureCorrelationAndRequestId::ATTR_REQUEST_ID, $requestId);

        return $request;
    }

    private function handle(Request $request, JsonResponse $response): Response
    {
        return (new EnrichJsonResponseWithRequestId())->handle($request, fn () => $response);
    }

    #[Test]
    public function it_handles_string_error_value_without_warning(): void
    {
        $request = $this->makeRequest();
        $response = new JsonResponse(
            ['error' => 'Something went wrong'],
            422,
        );

        $result = $this->handle($request, $response);

        $data = json_decode($result->getContent(), true);

        // String error value should be preserved, request_id not injected
        $this->assertSame('Something went wrong', $data['error']);
    }

    #[Test]
    public function it_adds_request_id_to_array_error(): void
    {
        $request = $this->makeRequest();
        $response = new JsonResponse(
            ['error' => ['message' => 'Validation failed']],
            422,
        );

        $result = $this->handle($request, $response);

        $data = json_decode($result->getContent(), true);

        $this->assertSame('req-123', $data['error']['request_id']);
        $this->assertSame('Validation failed', $data['error']['message']);
    }

    #[Test]
    public function it_creates_error_object_when_missing_for_error_response(): void
    {
        $request = $this->makeRequest();
        $response = new JsonResponse(
            ['status' => 'error'],
            500,
        );

        $result = $this->handle($request, $response);

        $data = json_decode($result->getContent(), true);

        $this->assertSame('req-123', $data['error']['request_id']);
    }

    #[Test]
    public function it_adds_request_id_at_root_for_success_response(): void
    {
        $request = $this->makeRequest();
        $response = new JsonResponse(['data' => 'ok'], 200);

        $result = $this->handle($request, $response);

        $data = json_decode($result->getContent(), true);

        $this->assertSame('req-123', $data['request_id']);
    }

    #[Test]
    public function it_handles_integer_error_value_without_warning(): void
    {
        $request = $this->makeRequest();
        $response = new JsonResponse(
            ['error' => 42],
            400,
        );

        $result = $this->handle($request, $response);

        $data = json_decode($result->getContent(), true);

        // Integer error value should be preserved
        $this->assertSame(42, $data['error']);
    }

    #[Test]
    public function it_handles_boolean_error_value_without_warning(): void
    {
        $request = $this->makeRequest();
        $response = new JsonResponse(
            ['error' => true],
            400,
        );

        $result = $this->handle($request, $response);

        $data = json_decode($result->getContent(), true);

        $this->assertTrue($data['error']);
    }

    #[Test]
    public function it_preserves_empty_stdclass_as_json_object(): void
    {
        $request = $this->makeRequest();

        $obj = new \stdClass();
        $obj->data = new \stdClass();
        $response = new JsonResponse($obj, 200);

        $result = $this->handle($request, $response);

        $raw = $result->getContent();

        $this->assertStringContainsString('"data":{}', $raw);
        $this->assertStringContainsString('"request_id":"req-123"', $raw);
    }
}
