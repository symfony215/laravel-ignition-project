<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelIgnition\ContextProviders\LaravelRequestContextProvider;
use Spatie\LaravelIgnition\Tests\TestCase;

uses(TestCase::class);
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

it('returns route name in context data', function () {
    $route = Route::get('/route/', fn () => null)->name('routeName');

    $request = createRequest('GET', '/route');

    $route->bind($request);

    $request->setRouteResolver(fn () => $route);

    $context = new LaravelRequestContextProvider($request);

    $contextData = $context->toArray();

    expect($contextData['route']['route'])->toBe('routeName');
});

it('returns route parameters in context data', function () {
    $route = Route::get('/route/{parameter}/{otherParameter}', fn () => null);

    $request = createRequest('GET', '/route/value/second');

    $route->bind($request);

    $request->setRouteResolver(function () use ($route) {
        return $route;
    });

    $context = new LaravelRequestContextProvider($request);

    $contextData = $context->toArray();

    $this->assertSame([
        'parameter' => 'value',
        'otherParameter' => 'second',
    ], $contextData['route']['routeParameters']);
});

it('returns the url', function () {
    $request = createRequest('GET', '/route', []);

    $context = new LaravelRequestContextProvider($request);

    $request = $context->getRequest();

    expect($request['url'])->toBe('http://localhost/route');
});

it('returns the cookies', function () {
    $request = createRequest('GET', '/route', [], ['cookie' => 'noms']);

    $context = new LaravelRequestContextProvider($request);

    expect($context->getCookies())->toBe(['cookie' => 'noms']);
});

it('returns the authenticated user', function () {
    $user = new User();
    $user->forceFill([
        'id' => 1,
        'email' => 'john@example.com',
    ]);

    $request = createRequest('GET', '/route', [], ['cookie' => 'noms']);
    $request->setUserResolver(fn () => $user);

    $context = new LaravelRequestContextProvider($request);
    $contextData = $context->toArray();

    expect($contextData['user'])->toBe($user->toArray());
});

it('the authenticated user model has a to flare method it will be used to collect user data', function () {
    $user = new class extends User {
        public function toFlare() {
            return ['id' => $this->id];
        }
    };

    $user->forceFill([
        'id' => 1,
        'email' => 'john@example.com',
    ]);

    $request = createRequest('GET', '/route', [], ['cookie' => 'noms']);
    $request->setUserResolver(fn () => $user);

    $context = new LaravelRequestContextProvider($request);
    $contextData = $context->toArray();

    expect($contextData['user'])->toBe(['id' => $user->id]);
});

it('the authenticated user model has no matching method it will return no user data', function () {
    $user = new class {
    };

    $request = createRequest('GET', '/route', [], ['cookie' => 'noms']);
    $request->setUserResolver(fn () => $user);

    $context = new LaravelRequestContextProvider($request);
    $contextData = $context->toArray();

    expect($contextData['user'])->toBe([]);
});

it('the authenticated user model is broken it will return no user data', function () {
    $user = new class extends User {
        protected $appends = ['invalid'];
    };

    $request = createRequest('GET', '/route', [], ['cookie' => 'noms']);
    $request->setUserResolver(fn () => $user);

    $context = new LaravelRequestContextProvider($request);
    $contextData = $context->toArray();

    expect($contextData['user'])->toBe([]);
});

// Helpers
function createRequest($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): Request
{
    $files = array_merge($files, test()->extractFilesFromDataArray($parameters));

    $symfonyRequest = SymfonyRequest::create(
        test()->prepareUrlForRequest($uri),
        $method,
        $parameters,
        $cookies,
        $files,
        array_replace(test()->serverVariables, $server),
        $content
    );

    return Request::createFromBase($symfonyRequest);
}
