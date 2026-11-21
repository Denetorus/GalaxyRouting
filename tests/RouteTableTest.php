<?php
declare(strict_types=1);

namespace Galaxy\Routing\Tests;

use Galaxy\Routing\RouteTable;
use Galaxy\Routing\Adapter\AdapterInterface;
use Galaxy\Result\GResult;
use PHPUnit\Framework\Attributes\DataProvider;
use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;

final class RouteTableTest extends TestCase
{

    private function route(string $kind, array $key, string $id = 'a'): array
    {
        return ['kind' => $kind, 'key' => $key, 'targetId' => $id];
    }

    protected function setUp(): void
    {
        RTAdapter::$constructors = 0;
    }

    private function targets(): array
    {
        return array_map(
            static fn (string $id): array => ['targetId' => $id, 'adapter' => RTAdapter::class, 'options' => ['id' => $id]],
            ['a', 'b', 'c', '1', '01'],
        );
    }

    public function testSelectionAndConfigurationOrder(): void
    {

        $routes = [
            $this->route('prefix', ['x']),
            $this->route('prefix', ['x', 'y'], 'b'),
            $this->route('exact', ['x', 'y'], 'c'),
            $this->route('exact', ['other']),
        ];
        foreach ([$routes, array_reverse($routes)] as $ordered) {

            $table = new RouteTable($ordered, $this->targets());
            self::assertSame('a', $table->resolve(['x']));
            self::assertSame('c', $table->resolve(['x', 'y']));
            self::assertSame('b', $table->resolve(['x', 'y', 'z']));
            self::assertSame('a', $table->resolve(['other']));
            self::assertNull($table->resolve(['xy']));

        }
        $table = new RouteTable([$this->route('prefix', ['x', 'y'], 'b')], $this->targets());
        self::assertSame('b', $table->resolve(['x', 'y']));
        self::assertNull((new RouteTable([], []))->resolve(['x']));
        self::assertSame('@galaxy-routing/list', (new RouteTable([], []))->resolve(['list']));
        self::assertNull((new RouteTable([], $this->targets()))->resolve(['x']));

    }

    public function testLiteralSegmentsAndIds(): void
    {

        $keys = [['1'], ['01'], ['a:b'], ['a', 'b'], ['list'], ['help'], ['-name'], ["\0"], ['Ä']];
        $routes = [];
        $targets = [];
        foreach ($keys as $index => $key) {
            $id = (string) $index;
            $routes[] = $this->route('exact', $key, $id);
            $targets[] = ['targetId' => $id, 'adapter' => RTAdapter::class];
        }
        $table = new RouteTable($routes, $targets);
        foreach ($keys as $index => $key) {
            self::assertSame((string) $index, $table->resolve($key));
        }
        foreach ([['List'], ['ä'], ['a', 'B'], ['a:b ']] as $key) {
            self::assertNull($table->resolve($key));
        }
        $table = new RouteTable([
                $this->route('exact', ['one'], '1'),
                $this->route('exact', ['zero-one'], '01'),
            ], $this->targets());
        self::assertSame('1', $table->resolve(['one']));
        self::assertSame('01', $table->resolve(['zero-one']));
        self::assertSame(['id' => '1'], $table->getAdapterOptions('1'));
        self::assertSame(['id' => '01'], $table->getAdapterOptions('01'));

    }

    public function testInvalidLookup(): void
    {
        $table = new RouteTable([$this->route('prefix', ['x'])], $this->targets());
        foreach ([[], [1 => 'x'], ['x' => 'x'], [''], ['x', ''], [1], [null], [false], [[]], ['x', new \stdClass()]] as $key) {
            self::assertNull($table->resolve($key));
        }
    }

    public function testInvalidConfiguration(): void
    {

        $route = $this->route('exact', ['x']);
        $badRoutes = [
            ['named' => $route], [1 => $route], [null], [[]],
            [array_diff_key($route, ['kind' => true])], [$route + ['extra' => true]],
            [array_replace($route, ['kind' => 'unknown'])],
            [array_replace($route, ['kind' => 1])],
            [array_replace($route, ['targetId' => ''])],
            [array_replace($route, ['targetId' => 1])],
            [array_replace($route, ['targetId' => 'missing'])],
            [$route, $route],
            [$this->route('prefix', ['x']), $this->route('prefix', ['x'])],
            [$route, $this->route('prefix', ['y'], 'missing')],
        ];
        foreach ([[], [''], [1], [null], [[]], [1 => 'x'], ['x' => 'x'], 'x'] as $key) {
            $badRoutes[] = [array_replace($route, ['key' => $key])];
        }
        foreach ($badRoutes as $routes) {
            $this->assertInvalid($routes, $this->targets());
        }
        $target = ['targetId' => 'a', 'factory' => static fn () => null];
        foreach ([
                ['named' => $target], [1 => $target], [null], [[]],
                [array_diff_key($target, ['factory' => true])], [$target + ['extra' => true]],
                [array_replace($target, ['targetId' => ''])],
                [array_replace($target, ['targetId' => 1])],
                [$target, $target],
                [array_replace($target, ['factory' => 'missing_function_gf0007'])],
                [array_replace($target, ['factory' => null])],
                [array_replace($target, ['factory' => [new \stdClass(), 'missing']])],
            ] as $targets) {
            $this->assertInvalid([], $targets);
        }

    }

    private function assertInvalid(array $routes, array $targets): void
    {

        try {
            new RouteTable($routes, $targets);
            self::fail('Invalid configuration was accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

    }

    public function testClassAccessorsNeverConstructOrExecuteOptions(): void
    {

        $calls = 0;
        $callback = static function () use (&$calls): void {
            ++$calls;
        };
        $table = new RouteTable([$this->route('exact', ['x'])], [
                ['targetId' => 'a', 'adapter' => RTAdapter::class, 'options' => ['callback' => $callback]],
                ['targetId' => 'unused', 'adapter' => RTInherited::class],
            ]);
        self::assertSame('a', $table->resolve(['x']));
        self::assertSame(RTAdapter::class, $table->getAdapterClass('a'));
        self::assertSame($callback, $table->getAdapterOptions('a')['callback']);
        self::assertSame([], $table->getAdapterOptions('unused'));
        self::assertSame(0, RTAdapter::$constructors);
        self::assertSame(0, $calls);
        self::assertFalse(method_exists($table, 'getFactory'));
        foreach (['getAdapterClass', 'getAdapterOptions'] as $method) {

            try {
                $table->$method('A');
                self::fail('Expected unknown exact target ID.');
            } catch (OutOfBoundsException) {
                self::assertTrue(true);
            }

        }

    }

    public function testConfigurationReferencesAreDetached(): void
    {

        $segment = 'x';
        $key = [&$segment];
        $kind = 'exact';
        $id = 'a';
        $class = RTAdapter::class;
        $options = ['nested' => ['value' => 'original']];
        $route = ['kind' => &$kind, 'key' => &$key, 'targetId' => &$id];
        $target = ['targetId' => &$id, 'adapter' => &$class, 'options' => &$options];
        $routes = [&$route];
        $targets = [&$target];
        $table = new RouteTable($routes, $targets);
        $segment = 'changed';
        $key[] = 'extra';
        $kind = 'prefix';
        $id = 'changed';
        $class = RTInherited::class;
        $options['nested']['value'] = 'source change';
        $route = $target = $routes = $targets = [];
        self::assertSame('a', $table->resolve(['x']));
        self::assertNull($table->resolve(['x', 'extra']));
        self::assertNull($table->resolve(['changed']));
        self::assertSame(RTAdapter::class, $table->getAdapterClass('a'));
        self::assertSame(['nested' => ['value' => 'original']], $table->getAdapterOptions('a'));
        $returned = $table->getRoutes();
        $returned[0]['key'][0] = 'changed';
        self::assertSame(['x'], $table->getRoutes()[0]['key']);

    }

    public function testStrictNewTargetSchemaAndClassMetadata(): void
    {

        $valid = ['targetId' => 'a', 'adapter' => RTAdapter::class];
        $bad = [
            ['named' => $valid], [1 => $valid], [null], [[]], [$valid, $valid],
            [array_diff_key($valid, ['adapter' => true])],
            [array_diff_key($valid, ['targetId' => true])],
            [$valid + ['extra' => true]],
            [$valid + ['factory' => static fn () => null]],
            [['targetId' => 'a', 'factory' => static fn () => null]],
        ];
        foreach (['', 1, null, false, []] as $id) {
            $bad[] = [array_replace($valid, ['targetId' => $id])];
        }
        foreach (['', 1, null, [], new \stdClass(), 'MissingGf0022Adapter', \stdClass::class,
                RTInterface::class, RTAbstract::class, RTTrait::class, RTEnum::class, RTPrivate::class] as $class) {
            $bad[] = [array_replace($valid, ['adapter' => $class])];
        }
        foreach ([null, 1, 'options', false, new \stdClass()] as $options) {
            $bad[] = [$valid + ['options' => $options]];
        }
        foreach ($bad as $targets) {
            $this->assertInvalid([], $targets);
        }
        self::assertSame(0, RTAdapter::$constructors);
        $ids = ['1', '01', ' a ', 'a', 'A', "a\0", '@Other/list'];
        $targets = array_map(static fn (string $id): array => [
                'targetId' => $id, 'adapter' => RTAdapter::class, 'options' => ['id' => $id],
            ], $ids);
        $table = new RouteTable([], $targets);
        foreach ($ids as $id) {
            self::assertSame(['id' => $id], $table->getAdapterOptions($id));
        }

    }

    public function testPHPClassNamesAliasesInheritanceAndVariance(): void
    {

        $alias = __NAMESPACE__ . '\\RTClassAlias';
        if (!class_exists($alias, false)) {
            class_alias(RTAdapter::class, $alias);
        }
        foreach ([RTAdapter::class, RTInherited::class, RTVariance::class, $alias,
                strtolower(RTAdapter::class), '\\' . RTAdapter::class] as $class) {
            $table = new RouteTable([], [['targetId' => 'a', 'adapter' => $class]]);
            self::assertSame($class, $table->getAdapterClass('a'));
            self::assertSame(0, RTAdapter::$constructors);
        }
        $class = RTVariance::class;
        $adapter = new $class(['value' => 7]);
        self::assertSame(['value' => 7], $adapter->options);
        self::assertSame(17, $adapter->additional);
        $inherited = new RTInherited();
        self::assertSame([], $inherited->options);

    }

    public function testAutoloadValidationAndOriginalAutoloaderException(): void
    {

        $name = __NAMESPACE__ . '\\RTAutoloadAlias';
        $loads = [];
        $loader = static function (string $class) use ($name, &$loads): void {
            if ($class === $name) {
                $loads[] = $class;
                class_alias(RTAdapter::class, $class);
            }
        };
        spl_autoload_register($loader);
        try {
            $table = new RouteTable([], [['targetId' => 'a', 'adapter' => $name]]);
            self::assertSame([$name], $loads);
            self::assertSame($name, $table->getAdapterClass('a'));
            self::assertSame(0, RTAdapter::$constructors);
        } finally {
            spl_autoload_unregister($loader);
        }
        $failure = new \RuntimeException('Autoloader fixture');
        $missing = __NAMESPACE__ . '\\RTThrowingAutoload';
        $thrower = static function (string $class) use ($missing, $failure): void {
            if ($class === $missing) {
                throw $failure;
            }
        };
        spl_autoload_register($thrower);
        try {

            try {
                new RouteTable([], [['targetId' => 'a', 'adapter' => $missing]]);
                self::fail('Expected original autoload failure.');
            } catch (\RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }

        } finally {
            spl_autoload_unregister($thrower);
        }

    }

    public function testOptionsKeysAndPlainNestedArrayCOW(): void
    {

        $options = ['' => null, 9 => 'nine', '01' => false, 'nested' => ['value' => 'original']];
        $table = new RouteTable([], [['targetId' => 'a', 'adapter' => RTMutation::class, 'options' => $options]]);
        self::assertSame(array_keys($options), array_keys($table->getAdapterOptions('a')));
        $options['nested']['value'] = 'source changed';
        $view = $table->getAdapterOptions('a');
        $view['nested']['value'] = 'accessor changed';
        $class = $table->getAdapterClass('a');
        $constructed = new $class($table->getAdapterOptions('a'));
        self::assertSame('constructor changed', $constructed->options['nested']['value']);
        self::assertSame('original', $table->getAdapterOptions('a')['nested']['value']);
        self::assertSame(['', 9, '01', 'nested'], array_keys($table->getAdapterOptions('a')));

    }

    public function testOptionsShareObjectClosureResourceAndReferences(): void
    {

        $object = (object) ['value' => 1];
        $calls = 0;
        $closure = static function () use (&$calls): void {
            ++$calls;
        };
        $resource = fopen('php://temp', 'w+');
        $shared = 'original';
        try {

            $options = ['object' => $object, 'closure' => $closure, 'resource' => $resource, 'nested' => ['value' => &$shared]];
            $table = new RouteTable([], [['targetId' => 'a', 'adapter' => RTAdapter::class, 'options' => $options]]);
            $view = $table->getAdapterOptions('a');
            self::assertSame($object, $view['object']);
            self::assertSame($closure, $view['closure']);
            self::assertSame($resource, $view['resource']);
            $view['object']->value = 2;
            $view['nested']['value'] = 'shared';
            fwrite($view['resource'], 'fixture');
            self::assertSame(2, $object->value);
            self::assertSame('shared', $shared);
            self::assertSame('shared', $table->getAdapterOptions('a')['nested']['value']);
            self::assertSame(7, ftell($resource));
            self::assertSame(0, $calls);
            self::assertSame(0, RTAdapter::$constructors);

        } finally {
            fclose($resource);
        }
        $recursive = [];
        $recursive['self'] = &$recursive;
        $table = new RouteTable([], [['targetId' => 'cycle', 'adapter' => RTAdapter::class, 'options' => $recursive]]);
        $view = $table->getAdapterOptions('cycle');
        self::assertSame(
            \ReflectionReference::fromArrayElement($recursive, 'self')->getId(),
            \ReflectionReference::fromArrayElement($view, 'self')->getId(),
        );
        $view['self']['marker'] = 'shared cycle';
        self::assertSame('shared cycle', $recursive['marker']);

    }

    public static function incompatibleConstructors(): iterable
    {
        yield 'wrong type' => ['wrong-type', 'public function __construct(string $value = "") {}'];
        yield 'required options' => ['required', 'public function __construct(array $options) {}'];
        yield 'private constructor' => ['private', 'private function __construct(array $options = []) {}'];
    }

    #[DataProvider('incompatibleConstructors')]
    public function testIncompatibleConstructorIsolated(string $label, string $constructor): void
    {

        $resultSource = (new \ReflectionClass(GResult::class))->getFileName();
        $interfaceSource = (new \ReflectionClass(AdapterInterface::class))->getFileName();
        $code = 'require ' . var_export($resultSource, true)
            . '; require ' . var_export($interfaceSource, true)
            . '; class ConstructorFixture implements \\' . AdapterInterface::class . ' { '
            . $constructor . ' public function dispatch(mixed $input): \\' . GResult::class
            . ' { return new \\' . GResult::class . '(); } } echo "loaded";';

        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=1', '-d', 'log_errors=0', '-r', $code],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        self::assertNotSame(0, $status, $label);
        self::assertMatchesRegularExpression('/Declaration|compatible|access level|Fatal error/i', $stdout . $stderr);
        self::assertStringContainsString('ConstructorFixture', $stdout . $stderr);
        self::assertStringContainsString('AdapterInterface', $stdout . $stderr);

    }

}

class RTAdapter implements AdapterInterface
{

    public static int $constructors = 0;
    public array $options;

    public function __construct(array $options = [])
    {
        ++self::$constructors;
        $this->options = $options;
    }

    public function dispatch(mixed $input): GResult
    {
        return new GResult();
    }

}

class RTInherited extends RTAdapter {
}
class RTVariance extends RTAdapter
{

    public int $additional;

    public function __construct(mixed $settings = [], int $additional = 17)
    {
        parent::__construct($settings);
        $this->additional = $additional;
    }

}
class RTMutation extends RTAdapter
{
    public function __construct(array $options = [])
    {
        $options['nested']['value'] = 'constructor changed';
        parent::__construct($options);
    }
}
interface RTInterface extends AdapterInterface {
}
abstract class RTAbstract extends RTAdapter {
}
trait RTTrait {
}
enum RTEnum {
    case Entry;
}
class RTPrivate {
    private function __construct() {
    }
}
