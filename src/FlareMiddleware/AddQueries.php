<?php

namespace Spatie\LaravelIgnition\FlareMiddleware;

use Spatie\FlareClient\Report;
use Spatie\LaravelIgnition\Recorders\QueryRecorder\QueryRecorder;

class AddQueries
{
    protected QueryRecorder $queryRecorder;

    public function __construct(QueryRecorder $queryRecorder)
    {
        $this->queryRecorder = $queryRecorder;
    }

    public function handle(Report $report, $next)
    {
        $report->group('queries', $this->queryRecorder->getQueries());

        return $next($report);
    }
}
