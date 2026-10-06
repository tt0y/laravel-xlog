<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class ModelWithFillableOnly extends Model
{
    protected $table = 'test_models';
    protected $fillable = ['name', 'email'];
}
