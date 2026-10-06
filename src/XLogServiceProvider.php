<?php

declare(strict_types=1);

namespace Tt0y\XLog;

use Tt0y\XLog\Console\BenchmarkCommand;
use Tt0y\XLog\Context\ContextProvider;
use Tt0y\XLog\Context\IdContext;
use Tt0y\XLog\Context\RequestTraceContext;
use Tt0y\XLog\Logger\XLogger;
use Tt0y\XLog\Middleware\EnsureCorrelationAndRequestId;
use Tt0y\XLog\Models\ModelLogPayloadBuilder;
use Tt0y\XLog\User\AuthUserResolver;
use Tt0y\XLog\User\Contracts\UserAttributesResolverInterface;
use Tt0y\XLog\User\Contracts\UserIdResolverInterface;
use Tt0y\XLog\User\NullUserAttributesResolver;
use Tt0y\XLog\User\UserIdResolver;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Uid\Uuid;

final class XLogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/xlog.php', 'xlog');

        $this->app->singleton(AuthUserResolver::class);
        $this->app->singleton(UserIdResolverInterface::class, UserIdResolver::class);

        // User attributes resolver (for custom attributes like tenant_id, organization_id, etc.)
        $this->app->singleton(UserAttributesResolverInterface::class, function ($app) {
            $resolverClass = config('xlog.user.attributes_resolver');

            if (is_string($resolverClass) && class_exists($resolverClass)) {
                return $app->make($resolverClass);
            }

            return new NullUserAttributesResolver();
        });

        /**
         * Per-request IDs context.
         * bind() => always fresh values (important for long-running processes).
         */
        $this->app->bind(IdContext::class, function () {
            $request = request();

            $correlationId = $request
                ? (string) $request->attributes->get(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID, '')
                : '';
            $requestId = $request
                ? (string) $request->attributes->get(EnsureCorrelationAndRequestId::ATTR_REQUEST_ID, '')
                : '';

            // Outside an HTTP request there are no attributes — in a queue worker, a console
            // command or a scheduled run — and both ids used to fall straight to 'n/a', which is
            // why request lines and job lines could not be joined in the log index.
            //
            // The hidden context survives all three: Laravel dehydrates it into every job payload
            // and restores it in the worker, and the scheduler passes it to a subprocess through
            // `__LARAVEL_CONTEXT`.
            //
            // **Request attributes win.** Inside a request they are the source of truth, and a
            // context left over from whatever queued work this process did must never override
            // them.
            if ($correlationId === '') {
                $correlationId = (string) Context::getHidden(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID, '');
            }

            if ($requestId === '') {
                $requestId = (string) Context::getHidden(EnsureCorrelationAndRequestId::ATTR_REQUEST_ID, '');
            }

            return new IdContext(
                $correlationId !== '' ? $correlationId : 'n/a',
                $requestId !== '' ? $requestId : 'n/a',
            );
        });

        /**
         * ContextProvider can be singleton, but must NOT capture IdContext instance.
         * It will resolve IdContext lazily on each call.
         */
        $this->app->singleton(ContextProvider::class, function ($app) {
            return new ContextProvider(
                $app, // Container
                $app->make(UserIdResolverInterface::class),
            );
        });

        $this->app->singleton(XLogger::class);
        $this->app->alias(XLogger::class, 'xlog');

        /**
         * Helper for enriching API error response.
         * Uses Request attributes directly.
         */
        $this->app->singleton(RequestTraceContext::class);

        $this->app->singleton(ModelLogPayloadBuilder::class, fn() => new ModelLogPayloadBuilder());
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/xlog.php' => config_path('xlog.php'),
        ], 'xlog-config');

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                BenchmarkCommand::class,
            ]);
        }

        if (!config('xlog.enabled', true)) {
            return;
        }

        $this->bootCliContext();
        $this->bootQueueContext();
    }

    private function bootCliContext(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->app['events']->listen(
            \Illuminate\Console\Events\CommandStarting::class,
            function (\Illuminate\Console\Events\CommandStarting $event): void {
                \Illuminate\Support\Facades\Log::shareContext([
                    'command_name' => $event->command,
                ]);

                // A console run gets ids of its own, so its lines and the lines of everything it
                // queues can be joined. Without this half the work in a queue-driven application
                // is uncorrelatable: a scheduled command does almost nothing itself and dispatches
                // jobs for the rest.
                //
                // **Correlation is inherited when there is one, minted only when there is not.**
                // That protects an `Artisan::call()` made inside an HTTP request: minting
                // unconditionally would overwrite the request's correlation, and every job the
                // request queued afterwards would travel under the command's id instead.
                //
                // A consequence worth knowing: with `$schedule->commandQueued()` the scheduler
                // dispatches its entries as jobs, so one `schedule:run` tick mints one correlation
                // id and everything in that tick shares it. `request_id` is what tells the
                // individual processes apart.
                if ((string) Context::getHidden(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID, '') === '') {
                    Context::addHidden(
                        EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID,
                        (string) Uuid::v7(),
                    );
                }

                // Always local to this process, exactly as the HTTP middleware treats it.
                Context::addHidden(EnsureCorrelationAndRequestId::ATTR_REQUEST_ID, (string) Uuid::v7());

                // The context provider memoizes its base for the lifetime of the container, and a
                // console process is that lifetime — without this the ids above would arrive after
                // the first log line had already frozen 'n/a'.
                $this->app->make(ContextProvider::class)->reset();
            }
        );
    }

    private function bootQueueContext(): void
    {
        if (!class_exists(\Illuminate\Queue\Events\JobProcessing::class)) {
            return;
        }

        $this->app['events']->listen(
            \Illuminate\Queue\Events\JobProcessing::class,
            function (\Illuminate\Queue\Events\JobProcessing $event): void {
                // The provider is a singleton and memoizes its base context, so in a long-running
                // worker the FIRST job's ids would be reported for every job after it. `reset()`
                // has existed for this since the package was written and nothing called it.
                //
                // Safe to do here: Laravel restores the job's context from its payload on this
                // same event, and that listener is registered by a base service provider, so it
                // has already run by the time this one does.
                $this->app->make(ContextProvider::class)->reset();

                $jobClass = null;

                if (method_exists($event->job, 'resolveName')) {
                    $jobClass = $event->job->resolveName();
                }

                if (!$jobClass && method_exists($event->job, 'payload')) {
                    $payload = $event->job->payload();
                    $jobClass = $payload['displayName'] ?? $payload['job'] ?? null;
                }

                \Illuminate\Support\Facades\Log::shareContext([
                    'job_class' => $jobClass ?: 'unknown',
                    'queue_connection' => $event->connectionName,
                    'queue_name' => method_exists($event->job, 'getQueue') ? $event->job->getQueue() : null,
                ]);
            }
        );
    }
}