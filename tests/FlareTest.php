<?php

use Flare as FlareFacade;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\View;
use Spatie\FlareClient\Flare;
use Spatie\LaravelIgnition\Tests\Mocks\FakeClient;

uses(TestCase::class);

beforeEach(function () {
    Artisan::call('view:clear');

    app()['config']['logging.channels.flare'] = [
        'driver' => 'flare',
    ];

    config()->set('logging.channels.flare.driver', 'flare');
    config()->set('logging.default', 'flare');
    config()->set('flare.key', 'some-key');

    $this->fakeClient = new FakeClient();

    app()->singleton(Flare::class, fn () => new Flare($this->fakeClient));

    $this->useTime('2019-01-01 12:34:56');

    View::addLocation(__DIR__.'/stubs/views');
});

it('can manually report exceptions', function () {
    FlareFacade::report(new Exception());

    $this->fakeClient->assertRequestsSent(1);
});
