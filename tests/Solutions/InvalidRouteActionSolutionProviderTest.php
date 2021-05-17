<?php

namespace Spatie\Ignition\Tests\Solutions;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Ignition\SolutionProviders\InvalidRouteActionSolutionProvider;
use Spatie\Ignition\Support\ComposerClassMap;
use Spatie\Ignition\Tests\stubs\Controllers\TestTypoController;
use Spatie\Ignition\Tests\TestCase;
use UnexpectedValueException;

class InvalidRouteActionSolutionProviderTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->app->bind(
            ComposerClassMap::class,
            function () {
                return new ComposerClassMap(__DIR__.'/../../vendor/autoload.php');
            }
        );
    }

    /** @test */
    public function it_can_solve_the_exception()
    {
        $canSolve = app(InvalidRouteActionSolutionProvider::class)->canSolve($this->getInvalidRouteActionException());

        $this->assertTrue($canSolve);
    }

    /** @test */
    public function it_can_recommend_changing_the_routes_method()
    {
        Route::get('/test', TestTypoController::class);

        /** @var \Spatie\IgnitionContracts\Solution $solution */
        $solution = app(InvalidRouteActionSolutionProvider::class)->getSolutions($this->getInvalidRouteActionException())[0];

        $this->assertTrue(Str::contains($solution->getSolutionDescription(), 'Did you mean `TestTypoController`'));
    }

    /** @test */
    public function it_wont_recommend_another_controller_class_if_the_names_are_too_different()
    {
        Route::get('/test', TestTypoController::class);

        $invalidController = 'UnrelatedTestTypoController';

        /** @var \Spatie\IgnitionContracts\Solution $solution */
        $solution = app(InvalidRouteActionSolutionProvider::class)->getSolutions($this->getInvalidRouteActionException($invalidController))[0];

        $this->assertFalse(Str::contains($solution->getSolutionDescription(), 'Did you mean `TestTypoController`'));
    }

    protected function getInvalidRouteActionException(string $controller = 'TestTypooController'): UnexpectedValueException
    {
        return new UnexpectedValueException("Invalid route action: [{$controller}]");
    }
}
