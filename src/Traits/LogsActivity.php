<?php

declare(strict_types=1);

namespace Tt0y\XLog\Traits;

use Tt0y\XLog\Facades\XLog;
use Tt0y\XLog\Models\ModelLogPayloadBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin Model
 */
trait LogsActivity
{
    /**
     * Adds model-scoped logging helpers.
     *
     * When used on an Eloquent model, this trait injects the following methods:
     * - log(string $event = 'model.log', array $context = [], string $level = 'info'): void
     * - logWarning(string $event, array $context = []): void
     * - logError(string $event, array $context = []): void
     * - logCritical(string $event, array $context = []): void
     *
     * Each call automatically builds a safe model payload via ModelLogPayloadBuilder
     * (respecting #[XLoggable] / LoggableModel / fillable fallback rules) and sends it
     * to the XLog logger at the requested level.
     *
     * @param string $event event name (e.g. "user.updated", "payment.created")
     * @param array<string, mixed> $context extra context
     * @param string $level supported: info|warning|error|critical
     */
    public function log(string $event = 'model.log', array $context = [], string $level = 'info'): void
    {
        /** @var ModelLogPayloadBuilder $builder */
        $builder = app(ModelLogPayloadBuilder::class);

        $payload = $builder->build($this);

        // Extra context wins over payload if keys overlap (caller can override)
        $data = $context + $payload;

        $level = strtolower(trim($level));

        // Restrict to levels supported by the XLog facade to avoid fatals.
        if (!in_array($level, ['info', 'warning', 'error', 'critical'], true)) {
            $level = 'info';
        }

        match ($level) {
            'warning' => XLog::warning($event, $data),
            'error' => XLog::error($event, $data),
            'critical' => XLog::critical($event, $data),
            default => XLog::info($event, $data),
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    public function logWarning(string $event, array $context = []): void
    {
        $this->log($event, $context, 'warning');
    }

    /**
     * @param array<string, mixed> $context
     */
    public function logError(string $event, array $context = []): void
    {
        $this->log($event, $context, 'error');
    }

    /**
     * @param array<string, mixed> $context
     */
    public function logCritical(string $event, array $context = []): void
    {
        $this->log($event, $context, 'critical');
    }
}