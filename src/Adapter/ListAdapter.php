<?php
declare(strict_types=1);

namespace Galaxy\Routing\Adapter;

use Galaxy\Result\GResult;

final class ListAdapter implements AdapterInterface
{

    private readonly array $routes;

    public function __construct(array $options = [])
    {

        if (array_diff(array_keys($options), ['routes']) !== []
            || (array_key_exists('routes', $options) && !is_array($options['routes']))) {
            throw new \InvalidArgumentException('Invalid built-in adapter options.');
        }
        $routes = $options['routes'] ?? [];
        if (!array_is_list($routes)) {
            throw new \InvalidArgumentException('Route metadata must be a list.');
        }
        $snapshot = [];
        foreach ($routes as $route) {

            if (!is_array($route) || count($route) !== 4
                || array_diff(['kind', 'key', 'targetId', 'description'], array_keys($route)) !== []
                || !in_array($route['kind'], ['exact', 'prefix'], true)
                || !is_array($route['key']) || $route['key'] === [] || !array_is_list($route['key'])
                || !is_string($route['targetId']) || $route['targetId'] === ''
                || !is_string($route['description'])) {
                throw new \InvalidArgumentException('Invalid route metadata.');
            }
            $key = [];
            foreach ($route['key'] as $segment) {
                if (!is_string($segment) || $segment === '') {
                    throw new \InvalidArgumentException('Invalid metadata key.');
                }
                $key[] = $segment;
            }
            $snapshot[] = [
                'kind' => $route['kind'],
                'key' => $key,
                'targetId' => $route['targetId'],
                'description' => $route['description'],
            ];

        }
        $this->routes = $snapshot;

    }

    public function dispatch(mixed $input): GResult
    {
        return (new GResult())->replaceData([
                'kind' => 'list',
                'routes' => $this->routes,
            ]);
    }

}
