# Laravel XLog

Unified structured logging package for Laravel applications with automatic context enrichment, request tracing, and model logging support.

## Features

- **Automatic context enrichment** — correlation_id, request_id, user info, HTTP details
- **Request tracing** — UUID v7 based correlation and request IDs for distributed tracing
- **Model logging** — Safe attribute extraction with `#[XLoggable]` attribute
- **JSON formatting** — Structured logs with promoted root fields for log aggregators
- **Middleware** — Automatic ID generation and response enrichment
- **Fully configurable** — Guards, user attributes, HTTP headers, promoted keys

## Requirements

- PHP 8.3+
- Laravel 11 or 12
- symfony/uid ^6.4 || ^7.0

## Installation

### 1. Install via Composer

The package is installed from GitHub (it is not on Packagist):

```bash
composer config repositories.laravel-xlog vcs https://github.com/tt0y/laravel-xlog
composer require tt0y/laravel-xlog:^1.0
```

The package is auto-discovered by Laravel. If auto-discovery is disabled, add the service provider manually:

```php
// config/app.php
'providers' => [
    Tt0y\XLog\XLogServiceProvider::class,
],
```

### 2. Publish Configuration

```bash
php artisan vendor:publish --tag=xlog-config
```

This creates `config/xlog.php` with all available options.

### 3. Configure Logging Channel

Add the `xlog` channel to your `config/logging.php`:

```php
use Tt0y\XLog\Formatter\PromotedRootJsonFormatter;
use Tt0y\XLog\Tap\XLogTap;
use Monolog\Handler\StreamHandler;

'channels' => [
    // ... other channels

    'xlog' => [
        'driver' => 'monolog',
        'level' => env('LOG_LEVEL', 'debug'),
        'handler' => StreamHandler::class,
        'handler_with' => [
            'stream' => 'php://stderr',
        ],
        'formatter' => PromotedRootJsonFormatter::class,
        'formatter_with' => [
            'appendNewline' => true,
        ],
        'tap' => [XLogTap::class],
    ],
],
```

### 4. Register Middleware

Add middleware to your HTTP kernel:

```php
// app/Http/Kernel.php

// Global middleware (runs on ALL requests)
protected $middleware = [
    \Tt0y\XLog\Middleware\EnsureCorrelationAndRequestId::class,
    // ... other middleware
];

// Optional: API middleware group (enriches JSON responses with request_id)
protected $middlewareGroups = [
    'api' => [
        \Tt0y\XLog\Middleware\EnrichJsonResponseWithRequestId::class,
        // ... other middleware
    ],
];
```

## Configuration

### Basic Options

```php
// config/xlog.php
return [
    'enabled' => env('XLOG_ENABLED', true),
    'service' => env('XLOG_SERVICE', env('APP_NAME', 'app')),
    'env' => env('XLOG_ENV', env('APP_ENV', 'local')),

    // Instance identifier (pod name in K8s, hostname, etc.)
    // In Kubernetes, HOSTNAME is automatically set to pod name
    'instance' => env('XLOG_INSTANCE', env('POD_NAME', env('HOSTNAME'))),

    'channel' => env('XLOG_CHANNEL', 'xlog'),

    'headers' => [
        'correlation' => env('XLOG_HEADER_CORRELATION', 'X-Correlation-Id'),
        'request' => env('XLOG_HEADER_REQUEST', 'X-Request-Id'),
    ],
];
```

### User Resolution

Configure how users are resolved for logging:

```php
'user' => [
    // Auth guards to check (in priority order)
    // First guard with an authenticated user wins
    'guards' => ['api', 'web'],

    // Custom class for project-specific user attributes
    // Must implement UserAttributesResolverInterface
    'attributes_resolver' => \App\Logging\MyUserAttributesResolver::class,
],
```

#### Custom User Attributes

To add project-specific user attributes (like `tenant_id`, `organization_id`, etc.), create a resolver:

```php
<?php

namespace App\Logging;

use Tt0y\XLog\User\Contracts\UserAttributesResolverInterface;
use Illuminate\Contracts\Auth\Authenticatable;

class MyUserAttributesResolver implements UserAttributesResolverInterface
{
    public function resolve(Authenticatable $user): array
    {
        $attrs = [];

        // Add custom attributes based on your user model
        if (isset($user->tenant_id)) {
            $attrs['tenant_id'] = $user->tenant_id;
        }

        return $attrs;
    }
}
```

### HTTP Meta Headers

Capture custom HTTP headers in logs:

```php
'http' => [
    // Headers to capture in context.http.meta
    'meta_headers' => [
        'X-App-Name',
        'X-App-Version',
        'X-Tenant-Id',
    ],

    // Only capture if this header is present (optional)
    // Set to null to always capture listed headers
    'meta_trigger' => 'X-App-Name',
],
```

### JSON Formatter

Configure which fields are promoted to root level:

```php
'formatter' => [
    // These keys move from context.* to root level for easier querying
    'promoted_keys' => [
        'correlation_id',
        'request_id',
        'service',
        'env',
        'instance',
        'user_id',
        'tenant_id', // Add custom keys as needed
    ],
],
```

## Usage

### Basic Logging

```php
use Tt0y\XLog\Facades\XLog;

// Simple message
XLog::info('User logged in');

// With context
XLog::info('payment.created', [
    'amount' => 100.50,
    'currency' => 'USD',
]);

// Different levels
XLog::warning('Low balance', ['balance' => 10]);
XLog::error('Payment failed', ['reason' => 'insufficient_funds']);
XLog::critical('System error', ['exception' => $e->getMessage()]);
```

### Model Logging with Trait

```php
use Tt0y\XLog\Traits\LogsActivity;

class Payment extends Model
{
    use LogsActivity;
}

// Usage
$payment->log('payment.processed', ['status' => 'success']);
$payment->logWarning('payment.retry', ['attempt' => 3]);
$payment->logError('payment.failed', ['reason' => 'timeout']);
$payment->logCritical('payment.fraud_detected');
```

### Controlling Logged Attributes

#### Option 1: Using `#[XLoggable]` Attribute (Recommended)

```php
use Tt0y\XLog\Attributes\XLoggable;

#[XLoggable(fields: ['id', 'status', 'amount'], includeFillable: false)]
class Payment extends Model
{
    use LogsActivity;
}
```

#### Option 2: Implementing `LoggableModel` Interface

```php
use Tt0y\XLog\Models\Contracts\LoggableModel;

class Payment extends Model implements LoggableModel
{
    use LogsActivity;

    public function xLogAttributes(): array
    {
        return ['id', 'status', 'amount', 'created_at'];
    }
}
```

#### Option 3: Fallback to Fillable

If neither attribute nor interface is used, the package falls back to `$fillable` properties.

### Accessing Request IDs

```php
use Tt0y\XLog\Middleware\EnsureCorrelationAndRequestId;

// In controller or middleware
$correlationId = request()->attributes->get(
    EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID
);
$requestId = request()->attributes->get(
    EnsureCorrelationAndRequestId::ATTR_REQUEST_ID
);

// Using RequestTraceContext helper
$traceContext = app(\Tt0y\XLog\Context\RequestTraceContext::class);
$requestId = $traceContext->requestId();
$correlationId = $traceContext->correlationId();

// Enrich error response
$traceContext->enrichErrorResponse($response);
```

### Safe Model Payload for Non-XLog Loggers

```php
use Tt0y\XLog\Helpers\ModelLogPayload;

// For Sentry, legacy loggers, or external services
Log::channel('sentry')->error('Error', [
    'user' => ModelLogPayload::payload($user),
]);
```

## Log Output Structure

```json
{
  "message": "payment.created",
  "context": {
    "amount": 100.50,
    "http": {
      "method": "POST",
      "host": "api.example.com",
      "uri": "/api/v1/payments",
      "ip": "192.168.1.1",
      "route": "api.payments.store",
      "action": "PaymentController@store",
      "meta": {
        "X-App-Name": "mobile_app",
        "X-App-Version": "1.0.0"
      }
    },
    "user": {
      "guard": "api",
      "class": "App\\Models\\User",
      "id": 12345,
      "tenant_id": 42
    }
  },
  "level": 200,
  "level_name": "INFO",
  "channel": "xlog",
  "datetime": "2026-01-29T12:00:00.000000+00:00",
  "correlation_id": "019abc12-def3-7000-8000-000000000001",
  "request_id": "019abc12-def3-7000-8000-000000000002",
  "service": "my-service",
  "env": "production",
  "instance": "my-service-web-7f8d9c6b5d-x2k4m",
  "user_id": 12345
}
```

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        HTTP Request                              │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│           EnsureCorrelationAndRequestId Middleware               │
│  • Extracts/generates correlation_id and request_id (UUID v7)   │
│  • Stores in request attributes                                  │
│  • Shares context with Laravel Log                               │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    Application Code                              │
│  • XLog::info(...) or Log::info(...)                            │
│  • $model->log(...)                                              │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      xlog Channel                                │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │              XLogTap — LOGGER-level processors              │  │
│  │  ┌─────────────────────────────────────────────────────┐  │  │
│  │  │  RequestProcessor                                    │  │  │
│  │  │  • Adds context.http (method, host, uri, ip, etc.)  │  │  │
│  │  │  • Adds context.user (guard, id, custom attributes) │  │  │
│  │  │  • Adds context.source for CLI/queue                │  │  │
│  │  └─────────────────────────────────────────────────────┘  │  │
│  │  ┌─────────────────────────────────────────────────────┐  │  │
│  │  │  XLogProcessor                                       │  │  │
│  │  │  • Adds correlation_id, request_id                  │  │  │
│  │  │  • Adds service, env, user_id                       │  │  │
│  │  └─────────────────────────────────────────────────────┘  │  │
│  └───────────────────────────────────────────────────────────┘  │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │        HANDLER-level processors (host application)          │  │
│  │  • Run AFTER every logger-level processor                  │  │
│  │  • Where a PII/credential scrubber belongs, so it sees     │  │
│  │    context.http/context.user produced above                 │  │
│  └───────────────────────────────────────────────────────────┘  │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │  PromotedRootJsonFormatter                                │  │
│  │  • Promotes configured keys to JSON root                  │  │
│  └───────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                     php://stderr                                 │
│              (for container/cloud environments)                  │
└─────────────────────────────────────────────────────────────────┘
```

### Processor levels — enrichment vs. redaction

`XLogTap` registers `RequestProcessor` and `XLogProcessor` on the **logger** level
(`Monolog\Logger::pushProcessor`), never on individual handlers. Monolog runs every
logger-level processor before it hands the record to a handler, and only then runs the
handler's own processors. That ordering is a contract other layers rely on:

- **Logger level** — enrichment (this package: http/user/source, correlation ids). Runs first, so everything downstream sees the enriched record.
- **Handler level** — redaction and filtering added by the host application. Runs last, so it can rewrite or drop anything the enrichers produced.

Registering enrichment on the handler level instead looks equivalent but is not:
`ProcessableHandlerTrait::pushProcessor` **prepends** (LIFO), so a handler-level
processor added later by the application runs *before* an enricher added earlier. A PII
scrubber attached that way would inspect the record before `context.http.uri` exists and
silently miss it — a raw query string (`?ssn=…`, `?t=<login-token>`) would then reach the
log sink. Attach a scrubber on the handler level for exactly this reason.

The regression guard lives in `tests/Unit/Tap/XLogTapTest.php`
(`it_enriches_before_handler_level_processors_run`): it attaches a handler-level
processor after the tap and asserts it can still rewrite `context.http.uri`.

## Components Reference

| Component | Purpose |
|-----------|---------|
| `XLog` Facade | Main entry point for structured logging |
| `XLogger` | Logger implementation delegating to xlog channel |
| `XLogTap` | Laravel tap registering both processors on the **logger** level |
| `XLogProcessor` | Adds base context (service, env, IDs) |
| `RequestProcessor` | Adds HTTP and user context |
| `PromotedRootJsonFormatter` | JSON formatter with configurable promoted fields |
| `EnsureCorrelationAndRequestId` | Middleware for ID generation |
| `EnrichJsonResponseWithRequestId` | Middleware for response enrichment |
| `LogsActivity` Trait | Model logging helpers |
| `#[XLoggable]` Attribute | Controls which model fields to log |
| `LoggableModel` Interface | Alternative to attribute for field control |
| `UserIdResolverInterface` | Contract for resolving user ID |
| `UserAttributesResolverInterface` | Contract for custom user attributes |

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `XLOG_ENABLED` | `true` | Enable/disable XLog |
| `XLOG_SERVICE` | `APP_NAME` | Service name in logs |
| `XLOG_ENV` | `APP_ENV` | Environment name in logs |
| `XLOG_INSTANCE` | `POD_NAME` or `HOSTNAME` | Instance identifier (pod name in K8s) |
| `XLOG_CHANNEL` | `xlog` | Target logging channel |
| `XLOG_HEADER_CORRELATION` | `X-Correlation-Id` | Correlation ID header name |
| `XLOG_HEADER_REQUEST` | `X-Request-Id` | Request ID header name |

## Distributed Tracing

For microservices architecture, pass correlation IDs between services:

```php
// When calling another service
$response = Http::withHeaders([
    'X-Correlation-Id' => request()->attributes->get(
        EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID
    ),
])->post('https://other-service.example.com/api/endpoint', $data);
```

This allows tracing a request through:
- Frontend → Backend → N microservices → Queue → Worker

### Queues and console

Within one application the ids need no manual passing. The middleware stores them in Laravel's
hidden context, which the framework serializes into every queued job and restores in the worker,
and the scheduler hands to a subprocess through `__LARAVEL_CONTEXT`. A job therefore logs the
`correlation_id` of the request (or command) that dispatched it.

- A console run inherits a correlation id when one was handed over and mints one otherwise;
  `request_id` is always new per process.
- Request attributes win over context, so leftover context from earlier queued work never
  overrides a live request.
- Hidden context never reaches log records, so the ids do not appear twice on a line.

## Performance

XLog is optimized for minimal overhead in production environments.

### Benchmark Command

Run performance benchmarks to compare XLog overhead:

```bash
# Basic benchmark (1000 records)
php artisan xlog:benchmark

# More records for accurate measurement
php artisan xlog:benchmark --count=5000

# With context data
php artisan xlog:benchmark --count=5000 --context
```

To compare performance with/without XLog:
1. Run with `XLOG_ENABLED=true` in `.env`
2. Run with `XLOG_ENABLED=false` in `.env`
3. Compare average time per record

### Caching Strategy

XLog caches data to minimize per-log overhead:

| Component | What is Cached | Lifetime |
|-----------|---------------|----------|
| `ContextProvider` | Base context (service, env, IDs, user_id) | Request |
| `RequestProcessor` | HTTP context (method, uri, ip, route) | Request |
| `RequestProcessor` | User context (guard, id, attributes) | Request |
| `RequestProcessor` | Config values (meta_headers, meta_trigger) | Request |
| `AuthUserResolver` | Auth resolution result | Request |
| `PromotedRootJsonFormatter` | Promoted keys list | Instance |
| `IdContext` | correlation_id, request_id | Request |

### Performance Results

Typical overhead compared to basic stdout logging:

| Version | Avg time/record | Overhead |
|---------|----------------|----------|
| XLog (optimized) | ~82 μs | ~2.5% |
| Basic stdout | ~80 μs | baseline |

Main overhead sources (~2-3 μs total):
- UUID v7 generation (2 IDs per first log)
- Context merging
- JSON key promotion

### Resetting Cache

For long-running processes (queues, Octane), caches are automatically reset between jobs. Manual reset:

```php
app(ContextProvider::class)->reset();
app(AuthUserResolver::class)->reset();
```

## Testing

### Running Package Tests

The package includes comprehensive unit test coverage. To run tests:

```bash
composer install
./vendor/bin/phpunit --testdox
```

**Test Coverage:** 68 tests, 157 assertions covering:
- Context management (IdContext, ContextProvider, RequestTraceContext)
- User resolution (AuthUserResolver, UserIdResolver, custom attributes)
- Model payload building (#[XLoggable], LoggableModel interface, fallbacks)
- Processors (XLogProcessor, RequestProcessor with HTTP/user/source context)
- Formatters (PromotedRootJsonFormatter with key promotion)
- Middleware (ID generation, response enrichment)
- Logger (XLogger delegation, convenience methods)

### Testing Your Application Code

When writing tests for code that uses XLog:

```php
use Tt0y\XLog\User\AuthUserResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class MyTest extends TestCase
{
    public function test_something_with_logging()
    {
        // Mock Auth facade for user resolution
        $user = User::factory()->create();
        $guard = new class($user) {
            public function __construct(private $user) {}
            public function hasUser(): bool { return true; }
            public function getUser() { return $this->user; }
        };

        Auth::shouldReceive('guard')->with('api')->andReturn($guard);

        // Assert log output
        Log::shouldReceive('channel')
            ->with('xlog')
            ->andReturnSelf();
        Log::shouldReceive('log')
            ->with('info', 'event.name', Mockery::subset(['key' => 'value']));

        // Your test code
        app(XLogger::class)->info('event.name', ['key' => 'value']);
    }
}
```

**Important:** `AuthUserResolver` and `ContextProvider` are `final` classes and cannot be mocked with Mockery. Use real instances or mock the underlying dependencies (`Auth` facade, config values).

### Test Organization

```
tests/
├── Unit/                    # Isolated unit tests (no Laravel dependencies)
│   ├── Context/            # Context management tests
│   ├── User/               # User resolution tests
│   ├── Models/             # Model payload builder tests
│   ├── Formatter/          # JSON formatter tests
│   ├── Processors/         # Log processors tests
│   └── Logger/             # XLogger tests
└── Fixtures/               # Test models for ModelLogPayloadBuilder
    ├── ModelWithXLoggable.php
    ├── ModelWithLoggableInterface.php
    └── ...
```

## License

MIT - see [LICENSE](LICENSE).
