<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Fixtures;

use Tt0y\XLog\Models\Contracts\LoggableModel;
use Illuminate\Database\Eloquent\Model;

class ModelWithLoggableInterface extends Model implements LoggableModel
{
    protected $table = 'test_models';
    protected $fillable = ['name', 'email', 'password'];

    public function xLogAttributes(): array
    {
        return ['name', 'email'];
    }
}
