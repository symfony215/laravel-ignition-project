<?php

namespace Spatie\LaravelIgnition\Tests\Recorders\JobRecorder;

use Carbon\CarbonImmutable;
use Closure;
use Exception;
use Illuminate\Container\Container;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelIgnition\Recorders\JobRecorder\JobRecorder;
use Spatie\LaravelIgnition\Tests\stubs\Jobs\QueueableJob;
use Spatie\LaravelIgnition\Tests\TestCase;

class JobRecorderTest extends TestCase
{
    /** @test */
    public function it_can_record_a_failed_job()
    {
        $recorder = (new JobRecorder($this->app));

        $recorder->record($this->createEvent(function () {
            dispatch(new QueueableJob([]));
        }));

        $recorded = $recorder->getJob();

        $this->assertEquals('Spatie\LaravelIgnition\Tests\stubs\Jobs\QueueableJob', $recorded['name']);
        $this->assertEquals('sync', $recorded['connection']);
        $this->assertEquals('sync', $recorded['queue']);
        $this->assertNotEmpty($recorded['uuid']);
        $this->assertNotEmpty($recorded['data']);
        $this->assertEquals([], $recorded['data']['property']);
    }

    /** @test */
    public function it_can_record_a_failed_job_with_data()
    {
        $recorder = (new JobRecorder($this->app));

        $job = new QueueableJob([
            'int' => 42,
            'boolean' => true,
        ]);

        $recorder->record($this->createEvent(function () use ($job) {
            dispatch($job);
        }));

        $recorded = $recorder->getJob();

        $this->assertNotEmpty($recorded['data']);
        $this->assertEquals([
            'int' => 42,
            'boolean' => true,
        ], $recorded['data']['property']);
    }

    /** @test */
    public function it_can_read_specific_properties_from_a_job()
    {
        $recorder = (new JobRecorder($this->app));

        $date = CarbonImmutable::create(2020, 05, 16, 12, 0, 0);

        $job = new QueueableJob(
            [],
            $date,  // retryUntil
            5, // tries
            10, // maxExceptions
            120 // timeout
        );

        $recorder->record($this->createEvent(function () use ($date, $job) {
            dispatch($job)
                ->onQueue('default')
                ->beforeCommit()
                ->delay($date);
        }));

        $recorded = $recorder->getJob();

        $this->assertEquals(5, $recorded['maxTries']);
        $this->assertEquals(10, $recorded['maxExceptions']);
        $this->assertEquals(120, $recorded['timeout']);
        $this->assertNotEmpty($recorded['data']);
        $this->assertEquals('default', $recorded['data']['queue']);
    }

    /** @test */
    public function it_can_record_a_closure_job()
    {
        $recorder = (new JobRecorder($this->app));

        $job = function () {
            throw new Exception('Die');
        };

        $recorder->record($this->createEvent(function () use ($job) {
            dispatch($job);
        }));

        $recorded = $recorder->getJob();

        $this->assertEquals('Closure (JobRecorderTest.php:99)', $recorded['name']);
    }

    /** @test */
    public function it_can_record_a_chained_job()
    {
        $recorder = (new JobRecorder($this->app));

        $recorder->record($this->createEvent(function () {
            dispatch(new QueueableJob(['level-one']))->chain([
                new QueueableJob(['level-two-a']),
                (new QueueableJob(['level-two-b']))->chain([
                    (new QueueableJob(['level-three'])),
                ]),
            ]);
        }));

        $recorded = $recorder->getJob();

        $this->assertCount(2, $chained = $recorded['data']['chained']);

        $this->assertEquals(QueueableJob::class, $chained[0]['name']);
        $this->assertEquals(['level-two-a'], $chained[0]['data']['property']);
        $this->assertEquals(QueueableJob::class, $chained[1]['name']);
        $this->assertEquals(['level-two-b'], $chained[1]['data']['property']);

        $this->assertCount(1, $chained = $chained[1]['data']['chained']);

        $this->assertEquals(QueueableJob::class, $chained[0]['name']);
        $this->assertEquals(['level-three'], $chained[0]['data']['property']);
    }

    /** @test */
    public function it_can_restrict_the_recorded_chained_jobs_depth()
    {
        $recorder = (new JobRecorder($this->app, 1));

        $recorder->record($this->createEvent(function () {
            dispatch(new QueueableJob(['level-one']))->chain([
                new QueueableJob(['level-two-a']),
                (new QueueableJob(['level-two-b']))->chain([
                    (new QueueableJob(['level-three'])),
                ]),
            ]);
        }));

        $recorded = $recorder->getJob();

        $this->assertCount(2, $chained = $recorded['data']['chained']);

        $this->assertEquals(QueueableJob::class, $chained[0]['name']);
        $this->assertEquals(['level-two-a'], $chained[0]['data']['property']);
        $this->assertEquals(QueueableJob::class, $chained[1]['name']);
        $this->assertEquals(['level-two-b'], $chained[1]['data']['property']);

        $this->assertCount(1, $chained = $chained[1]['data']['chained']);
        $this->assertEquals(['Ignition stopped recording jobs after this point since the max chain depth was reached'], $chained);
    }

    /** @test */
    public function it_can_disable_recording_chained_jobs()
    {
        $recorder = (new JobRecorder($this->app, 0));

        $recorder->record($this->createEvent(function () {
            dispatch(new QueueableJob(['level-one']))->chain([
                new QueueableJob(['level-two-a']),
                (new QueueableJob(['level-two-b']))->chain([
                    (new QueueableJob(['level-three'])),
                ]),
            ]);
        }));

        $recorded = $recorder->getJob();

        $this->assertCount(1, $chained = $recorded['data']['chained']);
        $this->assertEquals(['Ignition stopped recording jobs after this point since the max chain depth was reached'], $chained);
    }

    /** @test */
    public function it_can_handle_a_job_with_an_unserializeable_payload()
    {
        $recorder = (new JobRecorder($this->app));

        $payload = json_encode([
            'job' => 'Fake Job Name',
        ]);

        $event = new JobExceptionOccurred(
            'redis',
            new RedisJob(
                app(Container::class),
                app(RedisQueue::class),
                $payload,
                $payload,
                'redis',
                'default'
            ),
            new Exception()
        );

        $recorder->record($event);

        $recorded = $recorder->getJob();

        $this->assertEquals('Fake Job Name', $recorded['name']);
        $this->assertEquals('redis', $recorded['connection']);
        $this->assertEquals('default', $recorded['queue']);
    }

    private function createEvent(Closure $dispatch): JobExceptionOccurred
    {
        $triggeredEvent = null;

        Event::listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event) use (&$triggeredEvent) {
            $triggeredEvent = $event;
        });

        try {
            $dispatch();
        } catch (Exception $exception) {
        }

        if ($triggeredEvent === null) {
            throw new Exception("Could not create test event");
        }

        return $triggeredEvent;
    }
}
