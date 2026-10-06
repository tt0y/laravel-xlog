<?php

declare(strict_types=1);

namespace Tt0y\XLog\Tests\Unit\Context;

use Tt0y\XLog\Context\ContextProvider;
use Tt0y\XLog\Context\IdContext;
use Tt0y\XLog\Middleware\EnsureCorrelationAndRequestId;
use Tt0y\XLog\Tests\TestCase;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\Events\JobProcessing;
use Tt0y\XLog\User\Contracts\UserIdResolverInterface;
use Illuminate\Support\Facades\Context;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Correlation has to survive the queue.
 *
 * Every log line this package writes carries `correlation_id` and `request_id` so that everything
 * belonging to one operation can be pulled out of the index together. That worked for HTTP
 * requests and broke the moment the work moved to a queue: `IdContext` reads request attributes,
 * a worker has none, and both ids fell to `'n/a'` — so request lines and job lines, which is most
 * of the lines in a queue-driven application, could not be joined at all.
 *
 * Three things had to hold, and these tests pin all three: the ids travel (hidden context is what
 * Laravel dehydrates into a job payload), they are read back where the ids are resolved rather
 * than in one caller, and the provider's memo is cleared per job so a long-running worker does
 * not report the first job's ids for every job after it.
 */
final class CorrelationSurvivesTheQueueTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        Context::flush();

        // The harness declares no auth guards, and resolving the user id would throw before any
        // of this file's assertions were reached. Who the user is has nothing to do with whether
        // correlation survives a queue, so it is stubbed out rather than configured.
        $this->app->bind(UserIdResolverInterface::class, fn () => new class implements UserIdResolverInterface {
            public function resolve(): int|string|null
            {
                return null;
            }
        });
    }

    private function handleRequest(Request $request): void
    {
        (new EnsureCorrelationAndRequestId())->handle($request, fn () => new Response('ok'));
    }

    #[Test]
    public function the_middleware_puts_both_ids_where_the_queue_will_carry_them(): void
    {
        $request = Request::create('/test');
        $request->headers->set('X-Correlation-Id', 'corr-from-header');

        $this->handleRequest($request);

        $this->assertSame(
            'corr-from-header',
            Context::getHidden(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID),
        );
        $this->assertNotEmpty(Context::getHidden(EnsureCorrelationAndRequestId::ATTR_REQUEST_ID));
    }

    #[Test]
    public function the_ids_stay_out_of_the_visible_context(): void
    {
        // Visible context is copied into the `extra` of every log record by the framework's own
        // processor. Putting the ids there would add a second, differently-named copy of them to
        // every line in the application — the same field twice, under two names, forever.
        $request = Request::create('/test');

        $this->handleRequest($request);

        $this->assertArrayNotHasKey(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID, Context::all());
        $this->assertArrayNotHasKey(EnsureCorrelationAndRequestId::ATTR_REQUEST_ID, Context::all());
    }

    #[Test]
    public function ids_are_read_back_when_there_is_no_request(): void
    {
        // What a worker sees after Laravel restored the payload's context.
        Context::addHidden(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID, 'corr-from-payload');
        Context::addHidden(EnsureCorrelationAndRequestId::ATTR_REQUEST_ID, 'req-from-payload');

        $ids = $this->app->make(IdContext::class);

        $this->assertSame('corr-from-payload', $ids->correlationId);
        $this->assertSame('req-from-payload', $ids->requestId);
    }

    #[Test]
    public function a_request_attribute_wins_over_the_context(): void
    {
        // Inside a request the attributes are the source of truth. A context left over from
        // whatever queued work this process did must not override them, or a web request would
        // start logging under the id of the last job the worker ran.
        Context::addHidden(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID, 'corr-stale');
        Context::addHidden(EnsureCorrelationAndRequestId::ATTR_REQUEST_ID, 'req-stale');

        $request = Request::create('/test');
        $request->attributes->set(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID, 'corr-live');
        $request->attributes->set(EnsureCorrelationAndRequestId::ATTR_REQUEST_ID, 'req-live');
        $this->app->instance('request', $request);

        $ids = $this->app->make(IdContext::class);

        $this->assertSame('corr-live', $ids->correlationId);
        $this->assertSame('req-live', $ids->requestId);
    }

    #[Test]
    public function with_neither_a_request_nor_a_context_it_still_degrades_to_na(): void
    {
        // The old behaviour is kept where it is the only honest answer: nothing told us what this
        // belongs to. What changed is that it is now the exception rather than every job.
        $this->app->instance('request', Request::create('/test'));

        $ids = $this->app->make(IdContext::class);

        $this->assertSame('n/a', $ids->correlationId);
        $this->assertSame('n/a', $ids->requestId);
    }

    #[Test]
    public function the_provider_memo_is_cleared_for_each_job(): void
    {
        // `ContextProvider` is a singleton and caches its base context. In a worker that lifetime
        // is the whole process, so without the reset the first job's ids are reported for every
        // job after it — which looks exactly like correlation working.
        //
        // The second job's ids arrive **through its payload**, which is the only way they arrive
        // in production: Laravel's own listener hydrates the context from there on this same
        // event, before this package's listener runs. Setting the context directly before
        // dispatching the event would prove nothing — and worse, it is wiped, because hydrating
        // flushes first.
        $provider = $this->app->make(ContextProvider::class);

        Context::addHidden(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID, 'corr-second-job');
        $payload = ['displayName' => 'FakeJob', 'illuminate:log:context' => Context::dehydrate()];

        Context::flush();
        Context::addHidden(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID, 'corr-first-job');
        $this->assertSame('corr-first-job', $provider->base()['correlation_id']);

        $this->app['events']->dispatch(new JobProcessing('redis', $this->fakeJob($payload)));

        $this->assertSame('corr-second-job', $provider->base()['correlation_id']);
    }

    #[Test]
    public function a_job_carrying_no_context_does_not_inherit_the_previous_one(): void
    {
        // The property that makes a long-running worker safe: hydrating flushes first, so a job
        // dispatched with no context clears what the last job left behind instead of logging
        // under its id. Found while writing the test above, which had assumed the opposite.
        $provider = $this->app->make(ContextProvider::class);

        Context::addHidden(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID, 'corr-previous-job');
        $this->assertSame('corr-previous-job', $provider->base()['correlation_id']);

        $this->app['events']->dispatch(new JobProcessing('redis', $this->fakeJob()));

        $this->assertSame('n/a', $provider->base()['correlation_id']);
    }

    #[Test]
    public function a_console_run_mints_a_correlation_id_when_nothing_handed_it_one(): void
    {
        $this->dispatchCommandStarting('reports:snapshot');

        $minted = Context::getHidden(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID);

        $this->assertNotEmpty($minted);
        $this->assertNotSame('n/a', $minted);
    }

    #[Test]
    public function a_console_run_keeps_a_correlation_id_it_inherited(): void
    {
        // The case this protects is `Artisan::call()` inside an HTTP request: minting
        // unconditionally would overwrite the request's correlation, and every job queued after
        // that call would travel under the command's id instead of the request's.
        Context::addHidden(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID, 'corr-inherited');

        $this->dispatchCommandStarting('reports:snapshot');

        $this->assertSame(
            'corr-inherited',
            Context::getHidden(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID),
        );
    }

    #[Test]
    public function a_console_run_always_mints_its_own_request_id(): void
    {
        // Correlation spans the chain, request_id is local to the process — the same split the
        // HTTP middleware makes. It is what tells two commands of one scheduler tick apart, since
        // they share the tick's correlation.
        Context::addHidden(EnsureCorrelationAndRequestId::ATTR_REQUEST_ID, 'req-inherited');

        $this->dispatchCommandStarting('reports:snapshot');

        $this->assertNotSame(
            'req-inherited',
            Context::getHidden(EnsureCorrelationAndRequestId::ATTR_REQUEST_ID),
        );
    }

    #[Test]
    public function an_id_put_in_the_context_reaches_the_log_line(): void
    {
        // End to end through the real provider: this is the property the whole change exists for.
        Context::addHidden(EnsureCorrelationAndRequestId::ATTR_CORRELATION_ID, 'corr-end-to-end');
        Context::addHidden(EnsureCorrelationAndRequestId::ATTR_REQUEST_ID, 'req-end-to-end');

        $base = $this->app->make(ContextProvider::class)->base();

        $this->assertSame('corr-end-to-end', $base['correlation_id']);
        $this->assertSame('req-end-to-end', $base['request_id']);
    }

    private function dispatchCommandStarting(string $command): void
    {
        $this->app['events']->dispatch(
            new CommandStarting($command, new ArrayInput([]), new NullOutput()),
        );
    }

    /**
     * Mocked rather than hand-implemented: `Illuminate\Contracts\Queue\Job` has two dozen
     * methods and gains more between releases, and this file cares about exactly one event
     * carrying one job — not about being a queue driver.
     */
    private function fakeJob(?array $payload = null): \Illuminate\Contracts\Queue\Job
    {
        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('resolveName')->andReturn('FakeJob');
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('payload')->andReturn($payload ?? ['displayName' => 'FakeJob']);

        return $job;
    }
}
