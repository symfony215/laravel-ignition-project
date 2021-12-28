<?php

namespace Spatie\LaravelIgnition;

use Illuminate\Contracts\Foundation\ExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Engines\CompilerEngine as LaravelCompilerEngine;
use Illuminate\View\Engines\PhpEngine as LaravelPhpEngine;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TickReceived;
use Monolog\Logger;
use Spatie\FlareClient\Flare;
use Spatie\FlareClient\FlareMiddleware\AddSolutions;
use Spatie\Ignition\Config\IgnitionConfig;
use Spatie\Ignition\Contracts\SolutionProviderRepository as SolutionProviderRepositoryContract;
use Spatie\Ignition\Ignition;
use Spatie\LaravelIgnition\Commands\SolutionMakeCommand;
use Spatie\LaravelIgnition\Commands\SolutionProviderMakeCommand;
use Spatie\LaravelIgnition\Commands\TestCommand;
use Spatie\LaravelIgnition\Exceptions\InvalidConfig;
use Spatie\LaravelIgnition\FlareMiddleware\AddJobs;
use Spatie\LaravelIgnition\FlareMiddleware\AddLogs;
use Spatie\LaravelIgnition\FlareMiddleware\AddQueries;
use Spatie\LaravelIgnition\Http\Controllers\ExecuteSolutionController;
use Spatie\LaravelIgnition\Http\Controllers\HealthCheckController;
use Spatie\LaravelIgnition\Http\Controllers\UpdateConfigController;
use Spatie\LaravelIgnition\Http\Middleware\RunnableSolutionsEnabled;
use Spatie\LaravelIgnition\Recorders\DumpRecorder\DumpRecorder;
use Spatie\LaravelIgnition\Recorders\JobRecorder\JobRecorder;
use Spatie\LaravelIgnition\Recorders\LogRecorder\LogRecorder;
use Spatie\LaravelIgnition\Recorders\QueryRecorder\QueryRecorder;
use Spatie\LaravelIgnition\Renderers\IgnitionExceptionRenderer;
use Spatie\LaravelIgnition\Renderers\IgnitionWhoopsHandler;
use Spatie\LaravelIgnition\Solutions\SolutionProviders\SolutionProviderRepository;
use Spatie\LaravelIgnition\Support\FlareLogHandler;
use Spatie\LaravelIgnition\Support\SentReports;
use Spatie\LaravelIgnition\Views\Engines\CompilerEngine;
use Spatie\LaravelIgnition\Views\Engines\PhpEngine;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Whoops\Handler\HandlerInterface;

class IgnitionServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-ignition')
            ->hasConfigFile(['flare', 'ignition']);
    }

    public function packageRegistered(): void
    {
        $this
            ->registerFlare()
            ->registerIgnition()
            ->registerRenderer()
            ->registerRecorders();
    }

    public function bootingPackage()
    {
        if ($this->app['config']->get('flare.key')) {
            $this->package->hasCommands([
                TestCommand::class,
            ]);
        }

        if ($this->app['config']->get('ignition.register_commands')) {
            $this->package->hasCommands([
                SolutionMakeCommand::class,
                SolutionProviderMakeCommand::class,
            ]);
        }
    }

    public function packageBooted(): void
    {
        $this
            ->configureTinker()
            ->configureOctane()
            ->registerViewEngines()
            ->registerRoutes()
            ->registerLogHandler()
            ->startRecorders()
            ->configureQueue();
    }

    protected function registerRenderer(): self
    {
        if (interface_exists(HandlerInterface::class)) {
            $this->app->bind(
                HandlerInterface::class,
                fn (Application $app) => $app->make(IgnitionWhoopsHandler::class)
            );
        }


        if (interface_exists(ExceptionRenderer::class)) {
            $this->app->bind(
                ExceptionRenderer::class,
                fn (Application $app) => $app->make(IgnitionExceptionRenderer::class)
            );
        }

        return $this;
    }

    protected function registerFlare(): self
    {
        $this->app->singleton(Flare::class, function () {
            return Flare::make()
                ->setApiToken(config('flare.key') ?? '')
                ->setBaseUrl(config('flare.base_url', 'https://flareapp.io/api'))
                ->setStage(config('app.env'))
                ->registerMiddleware($this->getFlareMiddleware())
                ->registerMiddleware(new AddSolutions(new SolutionProviderRepository($this->getSolutionProviders())));
        });

        $this->app->singleton(SentReports::class);

        return $this;
    }

    protected function registerIgnition(): self
    {
        $ignitionConfig = (new IgnitionConfig())->loadConfigFile()->merge(config('ignition'));

        $solutionProviders = $this->getSolutionProviders();
        $solutionProviderRepository = new SolutionProviderRepository($solutionProviders);

        $this->app->singleton(IgnitionConfig::class, fn () => $ignitionConfig);

        $this->app->singleton(SolutionProviderRepositoryContract::class, fn () => $solutionProviderRepository);

        $this->app->singleton(Ignition::class, fn () => (new Ignition()));

        return $this;
    }

    protected function registerRecorders(): self
    {
        $this->app->singleton(DumpRecorder::class);

        $this->app->singleton(LogRecorder::class, function (Application $app): LogRecorder {
            return new LogRecorder(
                $app,
                config()->get('flare.flare_middleware' . AddLogs::class . 'maximum_number_of_collected_logs')
            );
        });

        $this->app->singleton(
            QueryRecorder::class,
            function (Application $app): QueryRecorder {
                return new QueryRecorder(
                    $app,
                    config()->get('flare.flare_middleware.' . AddQueries::class . '.report_query_bindings'),
                    config()->get('flare.flare_middleware.' . AddQueries::class . '.maximum_number_of_collected_queries')
                );
            }
        );

        $this->app->singleton(JobRecorder::class, function (Application $app): JobRecorder {
            return new JobRecorder(
                $app,
                config()->get('flare.flare_middleware.' . AddJobs::class . '.max_chained_job_reporting_depth')
            );
        });

        return $this;
    }

    public function configureTinker(): self
    {
        if (! $this->app->runningInConsole()) {
            if (isset($_SERVER['argv']) && ['artisan', 'tinker'] === $_SERVER['argv']) {
                app(Flare::class)->sendReportsImmediately();
            }
        }

        return $this;
    }

    protected function configureOctane(): self
    {
        if (isset($_SERVER['LARAVEL_OCTANE'])) {
            $this->setupOctane();
        }

        return $this;
    }

    protected function registerViewEngines(): self
    {
        if (! $this->hasCustomViewEnginesRegistered()) {
            return $this;
        }

        $this->app->make('view.engine.resolver')->register('php', function () {
            return new PhpEngine($this->app['files']);
        });

        $this->app->make('view.engine.resolver')->register('blade', function () {
            return new CompilerEngine($this->app['blade.compiler']);
        });

        return $this;
    }

    protected function registerRoutes(): self
    {
        if ($this->app->runningInConsole()) {
            return $this;
        }

        Route::group([
            'as' => 'ignition.',
            'prefix' => config('ignition.housekeeping_endpoint_prefix'),
            'middleware' => [RunnableSolutionsEnabled::class],
        ], function () {
            Route::get('health-check', HealthCheckController::class)->name('healthCheck');

            Route::post('execute-solution', ExecuteSolutionController::class)
                ->name('executeSolution');

            Route::post('update-config', UpdateConfigController::class)->name('updateConfig');
        });

        return $this;
    }

    protected function registerLogHandler(): self
    {
        $this->app->singleton('flare.logger', function ($app) {
            $handler = new FlareLogHandler(
                $app->make(Flare::class),
                $app->make(SentReports::class),
            );

            $logLevelString = config('logging.channels.flare.level', 'error');

            $logLevel = $this->getLogLevel($logLevelString);

            $handler->setMinimumReportLogLevel($logLevel);

            return tap(
                new Logger('Flare'),
                fn (Logger $logger) => $logger->pushHandler($handler)
            );
        });

        Log::extend('flare', fn ($app) => $app['flare.logger']);

        return $this;
    }

    protected function startRecorders(): self
    {
        // TODO: Ignition feature toggles

        $this->app->make(DumpRecorder::class)->start();

        $this->app->make(LogRecorder::class)->start();

        $this->app->make(QueryRecorder::class)->start();

        $this->app->make(JobRecorder::class)->start();

        return $this;
    }

    protected function configureQueue(): self
    {
        if (! $this->app->bound('queue')) {
            return $this;
        }

        $queue = $this->app->get('queue');

        // Reset before executing a queue job to make sure the job's log/query/dump recorders are empty.
        // When using a sync queue this also reports the queued reports from previous exceptions.
        $queue->before(function () {
            $this->resetFlareAndLaravelIgnition();
        });

        // Send queued reports (and reset) after executing a queue job.
        $queue->after(function () {
            $this->resetFlareAndLaravelIgnition();
        });

        // Note: the $queue->looping() event can't be used because it's not triggered on Vapor

        return $this;
    }

    protected function getLogLevel(string $logLevelString): int
    {
        $logLevel = Logger::getLevels()[strtoupper($logLevelString)] ?? null;

        if (! $logLevel) {
            throw InvalidConfig::invalidLogLevel($logLevelString);
        }

        return $logLevel;
    }

    protected function hasCustomViewEnginesRegistered(): bool
    {
        $resolver = $this->app->make('view.engine.resolver');

        if (! $resolver->resolve('php') instanceof LaravelPhpEngine) {
            return false;
        }

        if (! $resolver->resolve('blade') instanceof LaravelCompilerEngine) {
            return false;
        }

        return true;
    }

    protected function getFlareMiddleware(): array
    {
        return collect(config('flare.flare_middleware'))
            ->map(function ($value, $key) {
                if (is_string($key)) {
                    $middlewareClass = $key;
                    $parameters = $value ?? [];
                } else {
                    $middlewareClass = $value;
                    $parameters = [];
                }

                return new $middlewareClass(...array_values($parameters));
            })
            ->values()
            ->toArray();
    }

    protected function getSolutionProviders(): array
    {
        return collect(config('ignition.solution_providers'))
            ->reject(
                fn (string $class) => in_array($class, config('ignition.ignored_solution_providers'))
            )
            ->toArray();
    }

    protected function setupOctane()
    {
        $this->app['events']->listen(RequestReceived::class, function () {
            $this->resetFlareAndLaravelIgnition();
        });

        $this->app['events']->listen(TaskReceived::class, function () {
            $this->resetFlareAndLaravelIgnition();
        });

        $this->app['events']->listen(TickReceived::class, function () {
            $this->resetFlareAndLaravelIgnition();
        });
    }

    protected function resetFlareAndLaravelIgnition()
    {
        $this->app->get(SentReports::class)->clear();
        $this->app->get(Ignition::class)->reset();

        if (config('flare.flare_middleware.' . AddLogs::class)) {
            $this->app->make(LogRecorder::class)->reset();
        }

        if (config('flare.flare_middleware.' . AddQueries::class)) {
            $this->app->make(QueryRecorder::class)->reset();
        }

        if (config('flare.flare_middleware.' . AddJobs::class)) {
            $this->app->make(JobRecorder::class)->reset();
        }

        $this->app->make(DumpRecorder::class)->reset();
    }
}
