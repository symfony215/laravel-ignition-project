<?php

namespace Spatie\LaravelIgnition\ContextProviders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Spatie\FlareClient\Context\RequestContextProvider;
use Throwable;

class LaravelRequestContextProvider extends RequestContextProvider
{
    protected ?\Symfony\Component\HttpFoundation\Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function getUser(): array
    {
        try {
            $user = $this->request->user();

            if (! $user) {
                return [];
            }
        } catch (Throwable $e) {
            return [];
        }

        try {
            if (method_exists($user, 'toFlare')) {
                return $user->toFlare();
            }

            if (method_exists($user, 'toArray')) {
                return $user->toArray();
            }
        } catch (Throwable $e) {
            return [];
        }

        return [];
    }

    public function getRoute(): array
    {
        $route = $this->request->route();

        return [
            'route' => optional($route)->getName(),
            'routeParameters' => $this->getRouteParameters(),
            'controllerAction' => optional($route)->getActionName(),
            'middleware' => array_values(optional($route)->gatherMiddleware() ?? []),
        ];
    }

    public function getRequest(): array
    {
        $properties = parent::getRequest();


        if ($this->request->hasHeader('x-livewire') && $this->request->hasHeader('referer')) {
            $properties['url'] = $this->request->header('referer');
        }

        return $properties;
    }

    protected function getRouteParameters(): array
    {
        try {
            return collect(optional($this->request->route())->parameters ?? [])
                ->map(fn ($parameter) => $parameter instanceof Model ? $parameter->withoutRelations() : $parameter)
                ->toArray();
        } catch (Throwable $e) {
            return [];
        }
    }

    public function toArray(): array
    {
        $properties = parent::toArray();

        $properties['route'] = $this->getRoute();

        $properties['user'] = $this->getUser();

        return $properties;
    }
}
