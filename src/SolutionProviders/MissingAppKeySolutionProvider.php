<?php

namespace Spatie\LaravelIgnition\SolutionProviders;

use RuntimeException;
use Spatie\Ignition\Solutions\GenerateAppKeySolution;
use Spatie\IgnitionContracts\HasSolutionsForThrowable;
use Throwable;

class MissingAppKeySolutionProvider implements HasSolutionsForThrowable
{
    public function canSolve(Throwable $throwable): bool
    {
        if (! $throwable instanceof RuntimeException) {
            return false;
        }

        return $throwable->getMessage() === 'No application encryption key has been specified.';
    }

    public function getSolutions(Throwable $throwable): array
    {
        return [new GenerateAppKeySolution()];
    }
}
