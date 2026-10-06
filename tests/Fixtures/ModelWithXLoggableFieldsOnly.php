<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Fixtures;

use Tt0y\XLog\Attributes\XLoggable;
use Illuminate\Database\Eloquent\Model;

#[XLoggable(fields: ['name'], includeKey: false, includeFillable: false)]
class ModelWithXLoggableFieldsOnly extends Model
{
    protected $table = 'test_models';
    protected $fillable = ['name', 'email', 'status'];
}
