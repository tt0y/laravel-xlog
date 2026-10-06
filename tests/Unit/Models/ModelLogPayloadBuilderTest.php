<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Unit\Models;

use Tt0y\XLog\Models\ModelLogPayloadBuilder;
use Tt0y\XLog\Tests\Fixtures\ModelWithFillableOnly;
use Tt0y\XLog\Tests\Fixtures\ModelWithLoggableInterface;
use Tt0y\XLog\Tests\Fixtures\ModelWithNoFields;
use Tt0y\XLog\Tests\Fixtures\ModelWithXLoggable;
use Tt0y\XLog\Tests\Fixtures\ModelWithXLoggableFieldsOnly;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModelLogPayloadBuilderTest extends TestCase
{
    private ModelLogPayloadBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear the static cache between tests
        $ref = new \ReflectionClass(ModelLogPayloadBuilder::class);
        $prop = $ref->getProperty('attributesCache');
        $prop->setValue(null, []);

        $this->builder = new ModelLogPayloadBuilder();
    }

    #[Test]
    public function xloggable_with_include_fillable_and_key(): void
    {
        $model = new ModelWithXLoggable();
        $model->forceFill(['id' => 1, 'name' => 'John', 'email' => 'john@example.com', 'status' => 'active']);

        $result = $this->builder->build($model);

        $this->assertSame(ModelWithXLoggable::class, $result['model']);
        $this->assertSame(1, $result['model_id']);
        // Fields: status (explicit) + name, email (fillable) + id (key)
        $this->assertArrayHasKey('status', $result['model_data']);
        $this->assertArrayHasKey('name', $result['model_data']);
        $this->assertArrayHasKey('email', $result['model_data']);
        $this->assertArrayHasKey('id', $result['model_data']);
    }

    #[Test]
    public function xloggable_fields_only_excludes_fillable_and_key(): void
    {
        $model = new ModelWithXLoggableFieldsOnly();
        $model->forceFill(['id' => 1, 'name' => 'John', 'email' => 'john@example.com', 'status' => 'active']);

        $result = $this->builder->build($model);

        $this->assertSame(ModelWithXLoggableFieldsOnly::class, $result['model']);
        // Only 'name' field from explicit fields
        $this->assertArrayHasKey('name', $result['model_data']);
        $this->assertArrayNotHasKey('email', $result['model_data']);
        $this->assertArrayNotHasKey('status', $result['model_data']);
        $this->assertArrayNotHasKey('id', $result['model_data']);
    }

    #[Test]
    public function loggable_model_interface_uses_xlog_attributes(): void
    {
        $model = new ModelWithLoggableInterface();
        $model->forceFill(['id' => 1, 'name' => 'John', 'email' => 'john@example.com', 'password' => 'secret']);

        $result = $this->builder->build($model);

        $this->assertSame(ModelWithLoggableInterface::class, $result['model']);
        $this->assertArrayHasKey('name', $result['model_data']);
        $this->assertArrayHasKey('email', $result['model_data']);
        // password is in fillable but not in xLogAttributes()
        $this->assertArrayNotHasKey('password', $result['model_data']);
    }

    #[Test]
    public function fallback_to_fillable_when_no_attribute_or_interface(): void
    {
        $model = new ModelWithFillableOnly();
        $model->forceFill(['id' => 1, 'name' => 'John', 'email' => 'john@example.com']);

        $result = $this->builder->build($model);

        $this->assertSame(ModelWithFillableOnly::class, $result['model']);
        $this->assertArrayHasKey('name', $result['model_data']);
        $this->assertArrayHasKey('email', $result['model_data']);
    }

    #[Test]
    public function model_with_no_fields_returns_empty_model_data(): void
    {
        $model = new ModelWithNoFields();

        $result = $this->builder->build($model);

        $this->assertSame(ModelWithNoFields::class, $result['model']);
        $this->assertSame([], $result['model_data']);
    }

    #[Test]
    public function build_includes_model_class_and_model_id(): void
    {
        $model = new ModelWithFillableOnly();
        $model->forceFill(['id' => 99, 'name' => 'Test']);

        $result = $this->builder->build($model);

        $this->assertSame(ModelWithFillableOnly::class, $result['model']);
        $this->assertSame(99, $result['model_id']);
    }

    #[Test]
    public function model_id_null_is_filtered_out(): void
    {
        $model = new ModelWithFillableOnly();
        // No ID set

        $result = $this->builder->build($model);

        $this->assertArrayNotHasKey('model_id', $result);
    }

    #[Test]
    public function static_cache_works_for_same_class(): void
    {
        $model1 = new ModelWithXLoggable();
        $model1->forceFill(['id' => 1, 'name' => 'One', 'status' => 'a']);

        $model2 = new ModelWithXLoggable();
        $model2->forceFill(['id' => 2, 'name' => 'Two', 'status' => 'b']);

        $result1 = $this->builder->build($model1);
        $result2 = $this->builder->build($model2);

        // Both should have same keys in model_data (cached attribute resolution)
        $this->assertSame(array_keys($result1['model_data']), array_keys($result2['model_data']));
        // But different values
        $this->assertSame('One', $result1['model_data']['name']);
        $this->assertSame('Two', $result2['model_data']['name']);
    }

    #[Test]
    public function normalize_removes_empty_and_duplicate_strings(): void
    {
        // Use reflection to test normalize directly
        $ref = new \ReflectionMethod(ModelLogPayloadBuilder::class, 'normalize');

        $result = $ref->invoke($this->builder, ['name', '', 'name', '  ', 'email', 42, null]);

        $this->assertSame(['name', 'email'], $result);
    }
}
