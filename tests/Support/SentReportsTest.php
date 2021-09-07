<?php

namespace Spatie\LaravelIgnition\Tests\Support;

use Exception;
use Flare;
use Spatie\FlareClient\Report;
use Spatie\LaravelIgnition\Support\SentReports;
use Spatie\LaravelIgnition\Tests\TestCase;

class SentReportsTest extends TestCase
{
    protected SentReports $sentReports;

    public function setUp(): void
    {
        parent::setUp();

        $this->sentReports = new SentReports();
    }

    /** @test */
    public function it_can_get_the_uuids()
    {
        $this->assertNull($this->sentReports->latestUuid());

        $report = $this->getReport('first-report');
        $this->sentReports->add($report);
        $this->assertEquals('first-report', $this->sentReports->latestUuid());

        $report = $this->getReport('second-report');
        $this->sentReports->add($report);
        $this->assertEquals('second-report', $this->sentReports->latestUuid());

        $this->assertEquals([
            'first-report',
            'second-report',
        ], $this->sentReports->allUuids());
    }

    /** @test */
    public function it_can_be_cleared()
    {
        $report = $this->getReport('first-report');
        $this->sentReports->add($report);
        $this->assertCount(1, $this->sentReports->all());

        $this->sentReports->clear();
        $this->assertCount(0, $this->sentReports->all());
    }

    protected function getReport(string $fakeUuid = 'fake-uuid'): Report
    {
        Report::$fakeUuid = $fakeUuid;

        return Flare::report(new Exception());
    }
}
