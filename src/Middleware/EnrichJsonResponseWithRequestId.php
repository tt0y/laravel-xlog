<?php

declare(strict_types=1);

namespace Tt0y\XLog\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to add request_id to all JSON API responses.
 *
 * This middleware enriches the response body of JSON responses with
 * the request_id from the request attributes (set by EnsureCorrelationAndRequestId middleware).
 *
 * For successful responses (2xx, 3xx): request_id is added at the root level.
 * For error responses (4xx, 5xx): request_id is added inside the 'error' object.
 */
final class EnrichJsonResponseWithRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (!$response instanceof JsonResponse) {
            return $response;
        }

        $requestId = $this->getRequestId($request);
        if ($requestId === null) {
            return $response;
        }

        $this->enrichResponse($response, $requestId);

        return $response;
    }

    private function getRequestId(Request $request): ?string
    {
        $id = (string)$request->attributes->get(EnsureCorrelationAndRequestId::ATTR_REQUEST_ID, '');
        $id = trim($id);

        return ($id !== '' && $id !== 'n/a') ? $id : null;
    }

    private function enrichResponse(JsonResponse $response, string $requestId): void
    {
        $data = $response->getData();

        if (!$data instanceof \stdClass) {
            return;
        }

        if ($response->isClientError() || $response->isServerError()) {
            $this->enrichErrorResponse($data, $requestId);
        } else {
            $this->enrichSuccessResponse($data, $requestId);
        }

        $response->setData($data);
    }

    private function enrichSuccessResponse(\stdClass $data, string $requestId): void
    {
        if (!isset($data->request_id)) {
            $data->request_id = $requestId;
        }
    }

    private function enrichErrorResponse(\stdClass $data, string $requestId): void
    {
        if (!isset($data->error)) {
            $data->error = new \stdClass();
        }

        if (!$data->error instanceof \stdClass) {
            return;
        }

        if (!isset($data->error->request_id)) {
            $data->error->request_id = $requestId;
        }
    }
}
