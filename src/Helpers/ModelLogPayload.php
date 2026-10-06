<?php

declare(strict_types=1);

namespace Tt0y\XLog\Helpers;

use Tt0y\XLog\Models\ModelLogPayloadBuilder;
use Illuminate\Database\Eloquent\Model;

final class ModelLogPayload
{
    /**
     * Builds a safe, structured payload for an Eloquent model for *non-XLog* loggers.
     *
     * Use this when you need to pass model data to third-party/legacy logging pipelines that do
     * not run XLog processors (e.g. Sentry channel, legacy Log::channel(...), custom
     * log helpers, external telemetry, etc.).
     *
     * Why this exists:
     * - Passing an Eloquent model object directly into Laravel/Monolog context typically results
     *   in the model being serialized via `toArray()` / `jsonSerialize()`, which may include
     *   sensitive attributes (passwords, tokens, PII) unless meticulously hidden.
     * - This helper enforces the XLog allowlist rules and returns a plain array safe for JSON
     *   encoding and transport.
     *
     * Field selection rules (ModelLogPayloadBuilder):
     * 1) #[XLoggable] attribute on the model class (preferred explicit allowlist)
     * 2) LoggableModel::xlogAttributes() contract
     * 3) Fallback to model-fillable fields (use with caution)
     *
     * Typical usage:
     * - Log::channel('sentry')->error('Something happened', ['user' => ModelLogPayload::payload($user)]);
     * - SomeLegacyLogger::write('...', ['model' => ModelLogPayload::payload($model)]);
     *
     * Prefer `use LogsActivity` + `$model->log*()` when you are logging through XLog.
     * Prefer this helper when you must log to a different channel/logger and still want the same
     * safe model payload semantics.
     *
     * @return array<string, mixed>
     */
    public static function payload(Model $model): array
    {
        /** @var ModelLogPayloadBuilder $builder */
        $builder = app(ModelLogPayloadBuilder::class);

        return $builder->build($model);
    }

    public static function from(Model $model): array
    {
        return self::payload($model);
    }
}