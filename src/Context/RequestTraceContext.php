<?php

declare(strict_types=1);

namespace Tt0y\XLog\Context;

use Tt0y\XLog\Middleware\EnsureCorrelationAndRequestId;
use Illuminate\Http\Request;

final readonly class RequestTraceContext
{
    public function __construct(
        private Request $request,
    ) {
    }

    public function requestId(): ?string
    {
        $id = (string)$this->request->attributes->get(EnsureCorrelationAndRequestId::ATTR_REQUEST_ID, '');
        $id = trim($id);

        return ($id !== '' && $id !== 'n/a') ? $id : null;
    }

    public function correlationId(): ?string
    {
        $id = (string)$this->request->attributes->get(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID, '');
        $id = trim($id);

        return ($id !== '' && $id !== 'n/a') ? $id : null;
    }

    public function enrichErrorResponse(array &$response, bool $withCorrelationId = false): void
    {
        $response['error'] ??= [];

        if ($rid = $this->requestId()) {
            $response['error']['request_id'] = $rid;
        }

        if ($withCorrelationId && ($cid = $this->correlationId())) {
            $response['error']['correlation_id'] = $cid;
        }
    }
}