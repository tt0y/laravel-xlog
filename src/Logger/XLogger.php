<?php

declare(strict_types=1);

namespace Tt0y\XLog\Logger;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * Structured logger facade for XLog.
 *
 * This is a thin wrapper that delegates to the 'xlog' channel.
 * Context enrichment (correlation_id, request_id, etc.) is handled
 * by XLogProcessor via the tap configuration.
 *
 * Usage:
 *   use Tt0y\XLog\Facades\XLog;
 *   XLog::info('payment.created', ['amount' => 123]);
 */
final class XLogger implements LoggerInterface
{
    use LoggerTrait;

    private const string CHANNEL = 'xlog';

    public function log($level, $message, array $context = []): void
    {
        if (!config('xlog.enabled', true)) {
            return;
        }

        $messageStr = (string) $message;

        if ($messageStr !== '') {
            $context['message'] = $messageStr;
        }

        Log::channel(self::CHANNEL)->log((string) $level, $messageStr, $context);
    }
}