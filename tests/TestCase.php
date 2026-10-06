<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests;

use Tt0y\XLog\XLogServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            XLogServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('xlog.enabled', true);
        $app['config']->set('xlog.service', 'test-service');
        $app['config']->set('xlog.env', 'testing');
        $app['config']->set('xlog.instance', 'test-instance');
        $app['config']->set('xlog.headers.correlation', 'X-Correlation-Id');
        $app['config']->set('xlog.headers.request', 'X-Request-Id');
        $app['config']->set('auth.guards.api', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('xlog.user.guards', ['api', 'web']);
        $app['config']->set('xlog.user.attributes_resolver', null);
        $app['config']->set('xlog.http.meta_headers', []);
        $app['config']->set('xlog.http.meta_trigger', null);
        $app['config']->set('xlog.formatter.promoted_keys', [
            'correlation_id', 'request_id', 'service', 'env', 'instance', 'user_id',
        ]);
    }
}
