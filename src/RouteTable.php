<?php
declare(strict_types=1);

namespace Galaxy\Routing;

use Galaxy\Routing\Adapter\{AdapterInterface,ListAdapter,HelpAdapter};

use InvalidArgumentException;
use OutOfBoundsException;
use ReflectionClass;

final class RouteTable
{

    /** @var list<array{kind: string, key: list<string>, targetId: string, description: string}> */
    private readonly array $routes;

    /** @var array<string, array{adapter: class-string<AdapterInterface>, options: array}> */
    private readonly array $targets;

    public function __construct(array $routes, array $targets)
    {

        if (!array_is_list($routes) || !array_is_list($targets)) {
            throw new InvalidArgumentException('Routes and targets must be lists.');
        }

        $registry = [];
        foreach ($targets as $target) {

            if (!self::hasFields($target, ['targetId', 'adapter'], ['options'])
                || !is_string($target['targetId']) || $target['targetId'] === ''
                || !is_string($target['adapter']) || $target['adapter'] === ''
                || (array_key_exists('options', $target) && !is_array($target['options']))
                || in_array($target['targetId'], ['@galaxy-routing/list', '@galaxy-routing/help'], true)) {
                throw new InvalidArgumentException('Invalid target definition.');
            }
            // A fixed nonnumeric prefix preserves numeric-string ID distinctions.
            $index = 'id:' . $target['targetId'];
            if (array_key_exists($index, $registry)) {
                throw new InvalidArgumentException('Duplicate target ID.');
            }
            // Options retain ordinary PHP COW and shared handles/references.
            $registry[$index] = [
                'adapter' => $target['adapter'],
                'options' => $target['options'] ?? [],
            ];

        }

        $snapshot = [];
        $seen = [];
        foreach ($routes as $route) {

            if (!self::hasFields($route, ['kind', 'key', 'targetId'], ['description'])
                || !in_array($route['kind'], ['exact', 'prefix'], true)
                || !is_array($route['key']) || !self::validKey($route['key'])
                || !is_string($route['targetId']) || $route['targetId'] === ''
                || !array_key_exists('id:' . $route['targetId'], $registry)
                || in_array($route['targetId'], ['@galaxy-routing/list', '@galaxy-routing/help'], true)
                || (array_key_exists('description', $route) && !is_string($route['description']))) {
                throw new InvalidArgumentException('Invalid route definition or unknown target.');
            }
            $key = [];
            foreach ($route['key'] as $segment) {
                $key[] = $segment;
            }
            $identity = $route['kind'] . serialize($key);
            if (isset($seen[$identity])) {
                throw new InvalidArgumentException('Duplicate route key within kind.');
            }
            $seen[$identity] = true;
            $snapshot[] = [
                'kind' => $route['kind'],
                'key' => $key,
                'targetId' => $route['targetId'],
                'description' => $route['description'] ?? '',
            ];

        }

        $internal = [];
        foreach (['list' => 'List available routes.', 'help' => 'Show routing help.'] as $name => $description) {

            if (!isset($seen['exact' . serialize([$name])])) {

                $id = '@galaxy-routing/' . $name;
                $snapshot[] = [
                    'kind' => 'exact',
                    'key' => [$name],
                    'targetId' => $id,
                    'description' => $description,
                ];
                $internal[$id] = $name === 'list' ? ListAdapter::class : HelpAdapter::class;

            }

        }
        foreach ($internal as $id => $class) {
            $registry['id:' . $id] = ['adapter' => $class, 'options' => ['routes' => $snapshot]];
        }
        foreach ($registry as $target) {

            $class = $target['adapter'];
            if (!class_exists($class)) {
                throw new InvalidArgumentException('Adapter class does not exist.');
            }
            $metadata = new ReflectionClass($class);
            if (!$metadata->isInstantiable() || !$metadata->implementsInterface(AdapterInterface::class)) {
                throw new InvalidArgumentException(
                    'Adapter class must be instantiable and implement AdapterInterface.'
                );
            }

        }
        $this->routes = $snapshot;
        $this->targets = $registry;

    }

    /** @return list<array{kind: string, key: list<string>, targetId: string, description: string}> */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function resolve(array $key): ?string
    {

        if (!self::validKey($key)) {
            return null;
        }
        $selected = null;
        $longest = 0;
        foreach ($this->routes as $route) {

            if ($route['kind'] === 'exact') {
                if ($route['key'] === $key) {
                    return $route['targetId'];
                }
                continue;
            }
            $length = count($route['key']);
            if ($length > $longest && array_slice($key, 0, $length) === $route['key']) {
                $selected = $route['targetId'];
                $longest = $length;
            }

        }
        return $selected;

    }

    /** @return class-string<AdapterInterface> */
    public function getAdapterClass(string $targetId): string
    {
        $index = 'id:' . $targetId;
        if (!array_key_exists($index, $this->targets)) {
            throw new OutOfBoundsException('Unknown target ID.');
        }
        return $this->targets[$index]['adapter'];
    }

    public function getAdapterOptions(string $targetId): array
    {
        $index = 'id:' . $targetId;
        if (!array_key_exists($index, $this->targets)) {
            throw new OutOfBoundsException('Unknown target ID.');
        }
        return $this->targets[$index]['options'];
    }

    private static function validKey(array $key): bool
    {

        if ($key === [] || !array_is_list($key)) {
            return false;
        }
        foreach ($key as $segment) {
            if (!is_string($segment) || $segment === '') {
                return false;
            }
        }
        return true;

    }

    private static function hasFields(mixed $record, array $fields, array $optional = []): bool
    {
        return is_array($record)
        && array_diff($fields, array_keys($record)) === []
        && array_diff(array_keys($record), [...$fields, ...$optional]) === [];
    }

}
