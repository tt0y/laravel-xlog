<?php

return [
    'enabled' => env('XLOG_ENABLED', true),
    'service' => env('XLOG_SERVICE', env('APP_NAME', 'app')),
    'env' => env('XLOG_ENV', env('APP_ENV', 'local')),

    // Instance identifier (pod name in K8s, container ID, hostname, etc.)
    // In Kubernetes, HOSTNAME is automatically set to pod name
    // You can also use Downward API to set POD_NAME env var
    'instance' => env('XLOG_INSTANCE', env('POD_NAME', env('HOSTNAME'))),

    // Logging channel name from config/logging.php
    'channel' => env('XLOG_CHANNEL', 'xlog'),

    'headers' => [
        'correlation' => env('XLOG_HEADER_CORRELATION', 'X-Correlation-Id'),
        'request' => env('XLOG_HEADER_REQUEST', 'X-Request-Id'),
    ],

    /*
    |--------------------------------------------------------------------------
    | User Resolution
    |--------------------------------------------------------------------------
    |
    | Configure how the package resolves authenticated users for logging.
    |
    | guards: List of auth guards to check (in priority order).
    |         First guard with an authenticated user wins.
    |
    | attributes_resolver: Class implementing UserAttributesResolverInterface.
    |                      Used to add project-specific user attributes to logs.
    |                      Set to null to use NullUserAttributesResolver (no extra attributes).
    |
    */
    'user' => [
        'guards' => ['api', 'web'],
        'attributes_resolver' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Meta Headers
    |--------------------------------------------------------------------------
    |
    | Configure which HTTP headers to capture in context.http.meta.
    |
    | headers: List of header names to capture.
    | trigger: Only capture meta headers if this header is present.
    |          Set to null to always capture listed headers.
    |
    */
    'http' => [
        'meta_headers' => [],
        'meta_trigger' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | JSON Formatter
    |--------------------------------------------------------------------------
    |
    | Configure which context keys are promoted to the root level of JSON logs.
    | Promoted keys are easier to query in log aggregators (ELK, Datadog, etc).
    |
    */
    'formatter' => [
        'promoted_keys' => ['correlation_id', 'request_id', 'service', 'env', 'instance', 'user_id'],
    ],
];