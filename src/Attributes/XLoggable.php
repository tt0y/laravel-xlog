<?php

declare(strict_types=1);

namespace Tt0y\XLog\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class XLoggable
{
    /**
     * #[XLoggable] on the model class defines the list of fields and fallback behavior
     * The builder first looks at the attribute, then the interface, then the fillable
     * Reflection is cached (with a static cache) to avoid overhead for each log
     *
     * @param array<int, string> $fields
     */
    public function __construct(
        public array $fields = [],
        public bool $includeFillable = true,  // merge with fillable
        public bool $includeKey = true,       // include a model primary key
    )
    {
    }
}