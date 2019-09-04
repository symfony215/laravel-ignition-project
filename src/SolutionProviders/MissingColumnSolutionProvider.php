<?php

namespace Facade\Ignition\SolutionProviders;

use Throwable;
use Illuminate\Database\QueryException;
use Facade\Ignition\Solutions\RunMigrationsSolution;
use Facade\IgnitionContracts\HasSolutionsForThrowable;

class MissingColumnsSolutionProvider implements HasSolutionsForThrowable
{
    /**
     * See https://dev.mysql.com/doc/refman/8.0/en/server-error-reference.html#error_er_bad_field_error.
     */
    const MYSQL_BAD_TABLE_CODE = '42S22';

    public function canSolve(Throwable $throwable): bool
    {
        if (! $throwable instanceof QueryException) {
            return false;
        }

        return  $this->isBadTableErrorCode($throwable->getCode());
    }

    protected function isBadTableErrorCode($code): bool
    {
        return $code === static::MYSQL_BAD_TABLE_CODE;
    }

    public function getSolutions(Throwable $throwable): array
    {
        $solution = new RunMigrationsSolution('A column was not found');
        return [$solution];
    }
}
