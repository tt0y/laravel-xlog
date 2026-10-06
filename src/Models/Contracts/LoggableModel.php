<?php

declare(strict_types=1);

namespace Tt0y\XLog\Models\Contracts;

interface LoggableModel
{
    /**
     * Return the list of model attributes that are safe to log.
     * Must NOT trigger any additional DB queries.
     *
     * @return array<int, string>
     */
    public function xLogAttributes(): array;
}