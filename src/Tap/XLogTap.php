<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tap;

use Tt0y\XLog\Context\ContextProvider;
use Tt0y\XLog\Processors\RequestProcessor;
use Tt0y\XLog\Processors\XLogProcessor;
use Tt0y\XLog\User\AuthUserResolver;
use Tt0y\XLog\User\Contracts\UserAttributesResolverInterface;
use Illuminate\Http\Request;
use Illuminate\Log\Logger;
use Monolog\Logger as MonologLogger;

/**
 * Laravel logging tap that configures XLog processor on a channel.
 *
 * Usage in config/logging.php:
 *   'xlog' => [
 *       'driver' => 'monolog',
 *       'handler' => StreamHandler::class,
 *       'tap' => [XLogTap::class],
 *       ...
 *   ],
 */
final class XLogTap
{
    public function __construct(
        private readonly ContextProvider $contextProvider,
        private readonly AuthUserResolver $authUserResolver,
        private readonly ?UserAttributesResolverInterface $userAttributesResolver = null,
        private readonly ?Request $request = null,
    ) {
    }

    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (!$monolog instanceof MonologLogger) {
            return;
        }

        $xlogProcessor = new XLogProcessor($this->contextProvider);
        $requestProcessor = new RequestProcessor(
            $this->authUserResolver,
            $this->userAttributesResolver,
            $this->request,
        );

        // Enrichment belongs on the LOGGER level, not the handler level: Monolog
        // runs logger processors before handing the record to a handler, so any
        // handler-level processor the host application adds (e.g. a PII
        // scrubber) sees the context this tap produced. Handler-level
        // enrichment would run after such a scrubber and bypass it.
        //
        // Order matters: RequestProcessor adds http/user context first, then
        // XLogProcessor adds base context (correlation_id, etc.). pushProcessor
        // prepends, so the last one pushed runs first.
        $monolog->pushProcessor($xlogProcessor);
        $monolog->pushProcessor($requestProcessor);
    }
}
