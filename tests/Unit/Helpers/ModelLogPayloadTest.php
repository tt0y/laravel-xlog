<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Unit\Helpers;

use Tt0y\XLog\Helpers\ModelLogPayload;
use Tt0y\XLog\Tests\Fixtures\ModelWithFillableOnly;
use Tt0y\XLog\Tests\Fixtures\ModelWithXLoggable;
use Tt0y\XLog\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ModelLogPayloadTest extends TestCase
{
    #[Test]
    public function payload_returns_structured_array_with_model_info(): void
    {
        $model = new ModelWithFillableOnly(['name' => 'John', 'email' => 'john@test.com']);

        $result = ModelLogPayload::payload($model);

        $this->assertIsArray($result);
        $this->assertSame(ModelWithFillableOnly::class, $result['model']);
        $this->assertArrayHasKey('model_data', $result);
        $this->assertSame('John', $result['model_data']['name']);
        $this->assertSame('john@test.com', $result['model_data']['email']);
    }

    #[Test]
    public function from_is_alias_for_payload(): void
    {
        $model = new ModelWithFillableOnly(['name' => 'Jane']);

        $payload = ModelLogPayload::payload($model);
        $from = ModelLogPayload::from($model);

        $this->assertSame($payload, $from);
    }

    #[Test]
    public function payload_respects_xloggable_attribute(): void
    {
        $model = new ModelWithXLoggable(['name' => 'Test', 'email' => 'test@test.com']);

        $result = ModelLogPayload::payload($model);

        $this->assertArrayHasKey('model_data', $result);
        // XLoggable(fields: ['status'], includeFillable: true, includeKey: true)
        // So it includes: status, name, email, id(key)
        $this->assertArrayHasKey('name', $result['model_data']);
        $this->assertArrayHasKey('email', $result['model_data']);
    }

    #[Test]
    public function payload_returns_array_type(): void
    {
        $model = new ModelWithFillableOnly();

        $this->assertIsArray(ModelLogPayload::payload($model));
        $this->assertIsArray(ModelLogPayload::from($model));
    }
}
