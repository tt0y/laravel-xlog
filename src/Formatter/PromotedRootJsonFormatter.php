<?php

declare(strict_types=1);

namespace Tt0y\XLog\Formatter;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * Monolog JSON formatter that promotes selected context fields to the root level.
 *
 * Purpose:
 * - Keeps commonly queried identifiers at the top level of the JSON log record to simplify
 *   indexing/searching in log platforms (Datadog, ELK/OpenSearch, Splunk, etc.).
 * - Reduces repeated nesting under `context.*` for high-signal fields used in filters and
 *   correlation (e.g. correlation_id/request_id).
 *
 * Behavior:
 * - Normalizes the Monolog LogRecord and converts it to an array.
 * - If `context` exists, moves a predefined set of keys from `context` to the root object
 *   (only if the root key is not already present).
 * - Removes the promoted keys from `context`; if `context` becomes empty, it is removed.
 * - Outputs JSON using Monolog's JsonFormatter encoding rules and appends a newline when configured.
 *
 * Promoted keys are configurable via config('xlog.formatter.promoted_keys').
 * Default: correlation_id, request_id, service, env, instance, user_id
 *
 * Notes:
 * - This is a formatting-only step; it does not modify the semantic content, only the shape.
 * - Root keys always win: if a promoted key already exists at the root, the context value is left as-is.
 */
final class PromotedRootJsonFormatter extends JsonFormatter
{
    /** @var list<string> */
    private const array DEFAULT_KEYS = ['correlation_id', 'request_id', 'service', 'env', 'instance', 'user_id'];

    /** @var list<string>|null */
    private ?array $keysCache = null;

    public function format(LogRecord $record): string
    {
        $data = $this->asArray($record);

        $this->promote($data);

        return $this->toJson($data, true) . ($this->appendNewline ? "\n" : '');
    }

    /**
     * @return array<string, mixed>
     */
    private function asArray(LogRecord $record): array
    {
        $normalized = $this->normalize($record);

        if ($normalized instanceof LogRecord) {
            /** @var array<string, mixed> $arr */
            $arr = $normalized->toArray();
            return $arr;
        }

        /** @var array<string, mixed> $arr */
        $arr = is_array($normalized) ? $normalized : ['message' => (string)$normalized];
        return $arr;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function promote(array &$data): void
    {
        $context = $data['context'] ?? null;
        if (!is_array($context)) {
            return;
        }

        foreach ($this->getPromotedKeys() as $k) {
            if (array_key_exists($k, $context) && !array_key_exists($k, $data)) {
                $data[$k] = $context[$k];
                unset($context[$k]);
            }
        }

        if ($context === []) {
            unset($data['context']);
        } else {
            $data['context'] = $context;
        }
    }

    /**
     * Get the list of keys to promote from config.
     *
     * @return list<string>
     */
    private function getPromotedKeys(): array
    {
        if ($this->keysCache !== null) {
            return $this->keysCache;
        }

        $keys = config('xlog.formatter.promoted_keys');

        if (!is_array($keys) || $keys === []) {
            $this->keysCache = self::DEFAULT_KEYS;
            return $this->keysCache;
        }

        $this->keysCache = array_values(array_filter($keys, 'is_string'));
        return $this->keysCache;
    }
}