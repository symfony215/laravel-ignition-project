<?php

namespace Spatie\Ignition\Middleware;

use Spatie\FlareClient\Report;
use Spatie\Ignition\DumpRecorder\DumpRecorder;

class AddDumps
{
    protected DumpRecorder $dumpRecorder;

    public function __construct(DumpRecorder $dumpRecorder)
    {
        $this->dumpRecorder = $dumpRecorder;
    }

    public function handle(Report $report, $next)
    {
        $report->group('dumps', $this->dumpRecorder->getDumps());

        return $next($report);
    }
}
