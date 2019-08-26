<?php

namespace Facade\Ignition\Tests\Solutions;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use Facade\Ignition\SolutionProviders\ViewNotFoundSolutionProvider;
use Facade\Ignition\Support\ComposerClassMap;
use Facade\Ignition\Tests\stubs\Controllers\TestTypoController;
use Facade\Ignition\Tests\TestCase;
use UnexpectedValueException;

class ViewNotFoundSolutionProviderTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        View::addLocation(__DIR__.'/../stubs/views');
    }

    /** @test */
    public function it_can_solve_the_exception()
    {
        $canSolve = app(ViewNotFoundSolutionProvider::class)->canSolve($this->getViewNotFoundException());

        $this->assertTrue($canSolve);
    }

    /** @test */
    public function it_can_recommend_changing_a_typo_in_the_view_name()
    {
        /** @var \Facade\IgnitionContracts\Solution $solution */
        $solution = app(ViewNotFoundSolutionProvider::class)->getSolutions($this->getViewNotFoundException())[0];

        $this->assertStringContainsString('Did you mean `php-exception`?', $solution->getSolutionDescription());
    }

    /** @test */
    public function it_wont_recommend_another_controller_class_if_the_names_are_too_different()
    {
        $unknownView = 'a-view-that-doesnt-exist-and-is-not-a-typo';

        /** @var \Facade\IgnitionContracts\Solution $solution */
        $solution = app(ViewNotFoundSolutionProvider::class)->getSolutions($this->getViewNotFoundException($unknownView))[0];

        $this->assertStringNotContainsString('Did you mean', $solution->getSolutionDescription());
    }

    protected function getViewNotFoundException(string $view = 'phpp-exceptionn'): InvalidArgumentException
    {
        return new InvalidArgumentException("View [{$view}] not found.");
    }
}
