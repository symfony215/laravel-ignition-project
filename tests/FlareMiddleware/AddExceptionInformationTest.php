<?php

use Illuminate\Database\QueryException;
use Spatie\LaravelIgnition\Facades\Flare;
use Spatie\LaravelIgnition\Tests\TestCase;


it('will add query information with a query exception', function () {
    $sql = 'select * from users where emai = "ruben@spatie.be"';

    $report = Flare::createReport(new QueryException(
        '' . $sql . '',
        [],
        new Exception()
    ));

    $context = $report->toArray()['context'];

    $this->assertArrayHasKey('exception', $context);
    expect($context['exception']['raw_sql'])->toBe($sql);
});

it('wont add query information without a query exception', function () {
    $report = Flare::createReport(new Exception());

    $context = $report->toArray()['context'];

    $this->assertArrayNotHasKey('exception', $context);
});
