<?php
declare(strict_types=1);

namespace Galaxy\Routing\Tests;

use Galaxy\Result\GResult;
use Galaxy\Routing\Adapter\AdapterInterface;
use Galaxy\Routing\Input\CliInputParser;
use Galaxy\Routing\GRouter;
use Galaxy\Routing\Adapter\HelpAdapter;
use Galaxy\Routing\Input\InputParserInterface;
use Galaxy\Routing\Adapter\ListAdapter;
use Galaxy\Routing\RouteTable;
use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BuiltinAdaptersTest extends TestCase
{

    protected function setUp(): void
    {
        BIProbe::$constructors = BIProbe::$dispatches = 0;
    }

    private function builtins(): array
    {

        return [
            [
                'kind' => 'exact',
                'key' => [
                    'list'
                ],
                'targetId' => '@galaxy-routing/list',
                'description' => 'List available routes.'
            ],
            ['kind' => 'exact', 'key' => ['help'], 'targetId' => '@galaxy-routing/help', 'description' => 'Show routing help.'],
        ];

    }

    private function route(string $kind, array $key, string $targetId = 'engine'): array
    {
        return compact('kind', 'key', 'targetId');
    }

    private function target(array $options = []): array
    {
        return ['targetId' => 'engine', 'adapter' => BIProbe::class, 'options' => $options];
    }

    public function testEmptyConfigurationAndExactResults(): void
    {

        $table = new RouteTable([], []);
        self::assertSame('@galaxy-routing/list', $table->resolve(['list']));
        self::assertSame('@galaxy-routing/help', $table->resolve(['help']));
        self::assertSame($this->builtins(), $table->getRoutes());
        $router = new GRouter($table, new CliInputParser());
        foreach ([[], ['list'], ['list', '--format=json', '', '--']] as $input) {
            self::assertSame(['kind' => 'list', 'routes' => $this->builtins()], $router->dispatch($input)->getAllData());
        }
        foreach ([['help'], ['--help']] as $input) {
            self::assertSame([
                    'kind' => 'help', 'description' => 'Route an input to one registered target.',
                    'routes' => $this->builtins(),
                ], $router->dispatch($input)->getAllData());
        }
        self::assertNull($table->resolve(['unknown']));
        foreach (['list' => ListAdapter::class, 'help' => HelpAdapter::class] as $name => $class) {
            self::assertSame($class, $table->getAdapterClass('@galaxy-routing/' . $name));
            $options = $table->getAdapterOptions('@galaxy-routing/' . $name);
            self::assertSame(['routes' => $this->builtins()], $options);
            self::assertNotSame(new $class($options), new $class($options));
        }

    }

    public function testEffectiveOverridesPrefixesOrderAndNoEngineConstructors(): void
    {

        foreach ([[], ['list'], ['help'], ['list', 'help']] as $overrides) {

            $routes = [
                $this->route('prefix', ['list']),
                $this->route('prefix', ['help']),
                $this->route('prefix', ['dynamic']) + ['description' => 'Dynamic routes'],
            ];
            foreach ($overrides as $name) {
                $routes[] = $this->route('exact', [$name]) + ['description' => 'Own ' . $name];
            }
            $table = new RouteTable($routes, [$this->target()]);
            $expected = array_map(static fn (array $row): array => $row + ['description' => ''], $routes);
            foreach ($this->builtins() as $row) {
                if (!in_array($row['key'][0], $overrides, true)) {
                    $expected[] = $row;
                }
            }
            self::assertSame($expected, $table->getRoutes());
            foreach (['list', 'help'] as $name) {

                if (in_array($name, $overrides, true)) {

                    self::assertSame('engine', $table->resolve([$name]));
                    foreach (['getAdapterClass', 'getAdapterOptions'] as $method) {

                        try {
                            $table->$method('@galaxy-routing/' . $name);
                            self::fail('Overridden internal target must be absent.');
                        } catch (OutOfBoundsException) {
                            self::assertTrue(true);
                        }

                    }

                } else {
                    $result = (new GRouter($table, new CliInputParser()))->dispatch([$name]);
                    self::assertSame($expected, $result->getData('routes'));
                }

            }
            self::assertSame(0, BIProbe::$constructors);
            self::assertSame(0, BIProbe::$dispatches);

        }

    }

    public function testReservedIdsDescriptionsAndInvalidDefinitionsAreEarly(): void
    {

        foreach (['@galaxy-routing/list', '@galaxy-routing/help'] as $reserved) {

            $this->invalid([], [['targetId' => $reserved, 'adapter' => BIProbe::class]]);
            foreach (['exact', 'prefix'] as $kind) {
                $this->invalid([$this->route($kind, ['x'], $reserved)], [$this->target()]);
            }
            $this->invalid([
                    $this->route('exact', ['list']), $this->route('exact', ['help']),
                ], [$this->target(), ['targetId' => $reserved, 'adapter' => BIProbe::class]]);

        }
        foreach ([null, 1, false, [], new \stdClass()] as $description) {
            $this->invalid([$this->route('exact', ['x']) + ['description' => $description]], [$this->target()]);
        }
        foreach (['list', 'help'] as $name) {
            $row = $this->route('exact', [$name]);
            $this->invalid([$row, $row], [$this->target()]);
        }
        $this->invalid([$this->route('exact', ['x'], 'missing')], [$this->target()]);
        $this->invalid([$this->route('exact', ['x']) + ['extra' => true]], [$this->target()]);
        self::assertSame(0, BIProbe::$constructors);
        $table = new RouteTable([$this->route('exact', ['x'], '@other')], [
                ['targetId' => '@other', 'adapter' => BIProbe::class],
            ]);
        self::assertSame('@other', $table->resolve(['x']));

    }

    private function invalid(array $routes, array $targets): void
    {

        try {
            new RouteTable($routes, $targets);
            self::fail('Expected invalid configuration.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

    }

    public function testSnapshotsDetachMetadataAndBuiltinPayloads(): void
    {

        $segment = 'x';
        $kind = 'prefix';
        $id = 'engine';
        $description = 'Original';
        $key = [&$segment];
        $row = ['kind' => &$kind, 'key' => &$key, 'targetId' => &$id, 'description' => &$description];
        $rows = [&$row];
        $table = new RouteTable($rows, [$this->target()]);
        $expected = [
            ['kind' => 'prefix', 'key' => ['x'], 'targetId' => 'engine', 'description' => 'Original'],
            ...$this->builtins(),
        ];
        $segment = $kind = $id = $description = 'changed';
        $key[] = 'extra';
        $row = $rows = [];
        self::assertSame($expected, $table->getRoutes());
        $view = $table->getRoutes();
        $view[0]['key'][0] = 'changed';
        self::assertSame($expected, $table->getRoutes());
        foreach (['list', 'help'] as $name) {

            $id = '@galaxy-routing/' . $name;
            $options = $table->getAdapterOptions($id);
            $options['routes'][0]['description'] = 'changed';
            self::assertSame(['routes' => $expected], $table->getAdapterOptions($id));
            $class = $table->getAdapterClass($id);
            $adapter = new $class($table->getAdapterOptions($id));
            self::assertSame($expected, $adapter->dispatch(null)->getData('routes'));

        }
        foreach ([ListAdapter::class, HelpAdapter::class] as $class) {

            $part = 'x';
            $desc = 'Original';
            $record = ['kind' => 'prefix', 'key' => [&$part], 'targetId' => 'engine', 'description' => &$desc];
            $metadata = [&$record];
            $adapter = new $class(['routes' => $metadata]);
            $part = $desc = 'changed';
            $record = $metadata = [];
            self::assertSame([$expected[0]], $adapter->dispatch(false)->getData('routes'));

        }

    }

    public function testStandaloneDefaultsAndUserBuiltinOptionsAreNotEnriched(): void
    {

        foreach (['list' => ListAdapter::class, 'help' => HelpAdapter::class] as $kind => $class) {

            $standalone = new $class();
            self::assertSame([], $standalone->dispatch(null)->getData('routes'));
            $custom = [['kind' => 'prefix', 'key' => ['custom'], 'targetId' => 'custom', 'description' => 'Own metadata']];
            foreach ([[], ['routes' => $custom]] as $options) {

                $table = new RouteTable([$this->route('exact', [$kind], 'own')], [
                        ['targetId' => 'own', 'adapter' => $class, 'options' => $options],
                    ]);
                self::assertSame($options, $table->getAdapterOptions('own'));
                $result = (new GRouter($table, new CliInputParser()))->dispatch([$kind]);
                self::assertSame($options['routes'] ?? [], $result->getData('routes'));
                self::assertNotSame($table->getRoutes(), $result->getData('routes'));

            }
            $table = new RouteTable([$this->route('exact', ['own'], 'own')], [
                    ['targetId' => 'own', 'adapter' => $class, 'options' => ['unsupported' => true]],
                ]);
            self::assertSame(['unsupported' => true], $table->getAdapterOptions('own'));
            try {
                (new GRouter($table, new CliInputParser()))->dispatch(['own']);
                self::fail('Expected selected built-in options validation.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }

        }

    }

    public function testOwnOptionsMetadataValidationAndNoOutput(): void
    {

        $valid = $this->builtins()[0];
        $invalidRows = [
            [1 => $valid], ['named' => $valid], [null], [[]],
            [array_diff_key($valid, ['description' => true])],
            [$valid + ['factory' => static fn () => null]],
            [array_replace($valid, ['kind' => 'unknown'])],
            [array_replace($valid, ['targetId' => ''])],
            [array_replace($valid, ['targetId' => 1])],
            [array_replace($valid, ['description' => null])],
        ];
        foreach ([[], [''], [1], [1 => 'x'], 'x'] as $key) {
            $invalidRows[] = [array_replace($valid, ['key' => $key])];
        }
        foreach ([ListAdapter::class, HelpAdapter::class] as $class) {

            $badOptions = [['extra' => true], [$valid], ['routes' => null], ['routes' => false], ['routes' => 'metadata']];
            foreach ($invalidRows as $rows) {
                $badOptions[] = ['routes' => $rows];
            }
            foreach ($badOptions as $options) {

                try {
                    new $class($options);
                    self::fail('Invalid built-in options accepted.');
                } catch (InvalidArgumentException) {
                    self::assertTrue(true);
                }

            }
            $adapter = new $class(['routes' => $this->builtins()]);
            $this->expectOutputString('');
            foreach ([null, false, 0, ['list', '--flag'], (object) ['method' => 'POST', 'body' => 'unchanged']] as $input) {
                self::assertSame($this->builtins(), $adapter->dispatch($input)->getData('routes'));
            }

        }

    }

    public function testFreshSuccessfulResultsAndCallerMutation(): void
    {

        $table = new RouteTable([], []);
        foreach ([
                'list' => ['kind' => 'list', 'routes' => $this->builtins()],
                'help' => [
                    'kind' => 'help',
                    'description' => 'Route an input to one registered target.',
                    'routes' => $this->builtins()
                ],
            ] as $kind => $expected) {

            $id = '@galaxy-routing/' . $kind;
            $class = $table->getAdapterClass($id);
            $adapter = new $class($table->getAdapterOptions($id));
            $first = $adapter->dispatch(new \stdClass());
            $second = $adapter->dispatch(null);
            self::assertNotSame($first, $second);
            foreach ([$first, $second] as $result) {
                self::assertSame($expected, $result->getAllData());
                self::assertTrue($result->isSuccess());
                self::assertSame([], $result->getErrors());
                self::assertSame(0, $result->getCode());
                self::assertSame(0, $result->getConsoleCode());
            }
            $first->setData('routes', []);
            $first->addError(4001, 'Caller mutation.', consoleCode: 4);
            $third = $adapter->dispatch(false);
            self::assertSame($expected, $second->getAllData());
            self::assertSame($expected, $third->getAllData());
            self::assertTrue($third->isSuccess());
            self::assertSame([], $third->getErrors());
            self::assertSame(0, $third->getCode());
            self::assertSame(0, $third->getConsoleCode());

        }

    }

    public function testCustomHelpObjectInputAndOriginalExceptionWithoutFallback(): void
    {

        $request = (object) ['path' => '/help', 'method' => 'POST'];
        $result = (new GResult())->replaceData(['source' => 'custom-help']);
        $failure = new RuntimeException('Own help failure');
        $state = (object) ['expectedInput' => $request, 'result' => $result, 'failure' => null, 'inputs' => []];
        $table = new RouteTable([$this->route('exact', ['help'])], [$this->target(['state' => $state])]);
        $parser = new class implements InputParserInterface {
            public int $calls = 0;
            public function extractKey(mixed $input): ?array {
                ++$this->calls;
                return ['help'];
            }
        };
        $router = new GRouter($table, $parser);
        self::assertSame($result, $router->dispatch($request));
        $state->failure = $failure;
        try {
            $router->dispatch($request);
            self::fail('Expected original override exception.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame([$request, $request], $state->inputs);
        self::assertSame([2, 2, 2], [$parser->calls, BIProbe::$constructors, BIProbe::$dispatches]);

    }

    public function testCliGlobalsAndOriginalTargetHelpTokens(): void
    {

        $parser = new CliInputParser();
        foreach ([[[], ['list']], [['help'], ['help']], [['--help'], ['help']],
                [['list', 'extra', '', '--'], ['list']],
                [['help', 'salary:calculate'], ['salary', 'calculate']],
                [['salary:calculate', '--help'], ['salary', 'calculate']]] as [$input, $expected]) {
            self::assertSame($expected, $parser->extractKey($input));
        }
        foreach ([['--help', 'x'], ['help', ''], ['help', '-x'], ['x::y'], ['help', null]] as $input) {
            self::assertNull($parser->extractKey($input));
        }
        $result = new GResult();
        $result->addError(1001, 'Command failed.', consoleCode: 7);
        $state = (object) ['result' => $result, 'failure' => null, 'inputs' => []];
        $table = new RouteTable([$this->route('exact', ['salary', 'calculate'])], [$this->target(['state' => $state])]);
        $router = new GRouter($table, $parser);
        foreach ([['help', 'salary:calculate', '', '--'], ['salary:calculate', '--help']] as $input) {
            self::assertSame($result, $router->dispatch($input));
            self::assertSame($input, $state->inputs[array_key_last($state->inputs)]);
        }
        self::assertSame(2, BIProbe::$constructors);
        self::assertSame(2, BIProbe::$dispatches);

    }

}

class BIProbe implements AdapterInterface
{

    public static int $constructors = 0;
    public static int $dispatches = 0;
    private readonly array $options;

    public function __construct(array $options = [])
    {
        ++self::$constructors;
        $this->options = $options;
    }

    public function dispatch(mixed $input): GResult
    {

        ++self::$dispatches;
        $state = $this->options['state'] ?? null;
        if ($state !== null) {

            $state->inputs[] = $input;
            if (property_exists($state, 'expectedInput')) {
                TestCase::assertSame($state->expectedInput, $input);
            }
            if ($state->failure !== null) {
                throw $state->failure;
            }
            return $state->result;

        }
        return new GResult();

    }

}
