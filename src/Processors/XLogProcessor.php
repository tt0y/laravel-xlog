<?php

declare(strict_types=1);

namespace Tt0y\XLog\Processors;

use Tt0y\XLog\Context\ContextProvider;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that enriches log records with XLog context.
 *
 * Adds correlation_id, request_id, service, env, and user_id to every log record.
 * Context is cached for the lifetime of the request.
 */
final readonly class XLogProcessor implements ProcessorInterface
{
    public function __construct(
        private ContextProvider $contextProvider,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $baseContext = $this->contextProvider->base();

        // Merge base context into record context (record context wins on conflicts)
        $mergedContext = $record->context + $baseContext;

        return $record->with(context: $mergedContext);
    }
}
