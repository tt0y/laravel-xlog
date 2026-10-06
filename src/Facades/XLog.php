<?php

declare(strict_types=1);

namespace Tt0y\XLog\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void info(string $event, array $context = [])
 * @method static void warning(string $event, array $context = [])
 * @method static void error(string $event, array $context = [])
 * @method static void critical(string $event, array $context = [])
 *
 * @see \Tt0y\XLog\Logger\XLogger
 */
final class XLog extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'xlog';
    }
}