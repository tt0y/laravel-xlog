<?php

declare(strict_types=1);

namespace Tt0y\XLog\Models;

use Tt0y\XLog\Attributes\XLoggable;
use Tt0y\XLog\Models\Contracts\LoggableModel;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

final class ModelLogPayloadBuilder
{
    /** @var array<class-string, array<int, string>> */
    private static array $attributesCache = [];

    public function build(Model $model): array
    {
        $attrs = $this->resolveAttributesList($model);

        $data = [];
        foreach ($attrs as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $data[$key] = $model->getAttributeValue($key);
        }

        return array_filter([
            'model' => $model::class,
            'model_id' => $model->getKey(),
            'model_data' => $data,
        ], static fn($v) => $v !== null);
    }

    /**
     * @return array<int, string>
     */
    private function resolveAttributesList(Model $model): array
    {
        // 1) Attribute #[XLoggable] wins
        $fromAttribute = $this->resolveFromAttribute($model);
        if ($fromAttribute !== null) {
            return $fromAttribute;
        }

        // 2) Explicit contract method
        if ($model instanceof LoggableModel) {
            $list = $model->xLogAttributes();
            if (is_array($list) && $list !== []) {
                return $this->normalize($list);
            }
        }

        // 3) Fallback to fillable
        $fillable = method_exists($model, 'getFillable') ? $model->getFillable() : [];
        if (!is_array($fillable) || $fillable === []) {
            return [];
        }

        return $this->normalize($fillable);
    }

    /**
     * self::$attributesCache[$class] caches null if no attribute is present
     * @return array<int, string>|null
     */
    private function resolveFromAttribute(Model $model): ?array
    {
        $class = $model::class;

        if (array_key_exists($class, self::$attributesCache)) {
            return self::$attributesCache[$class];
        }

        $ref = new ReflectionClass($class);
        $attrs = $ref->getAttributes(XLoggable::class);

        if ($attrs === []) {
            self::$attributesCache[$class] = null;
            return null;
        }

        /** @var XLoggable $meta */
        $meta = $attrs[0]->newInstance();

        $fields = $meta->fields;

        if ($meta->includeFillable && method_exists($model, 'getFillable')) {
            $fields = array_merge($fields, $model->getFillable());
        }

        if ($meta->includeKey) {
            $keyName = $model->getKeyName();
            $fields[] = $keyName;
        }

        $result = $this->normalize($fields);
        self::$attributesCache[$class] = $result;

        return $result;
    }

    /**
     * @param array<int, mixed> $list
     * @return array<int, string>
     */
    private function normalize(array $list): array
    {
        $list = array_values(array_filter($list, 'is_string'));
        $list = array_map(static fn(string $v) => trim($v), $list);
        $list = array_values(array_filter($list, static fn(string $v) => $v !== ''));

        return array_values(array_unique($list));
    }
}