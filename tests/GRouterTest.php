<?php
declare(strict_types=1);

namespace Galaxy\Routing\Tests;

use Closure;
use Galaxy\Result\GResult;
use Galaxy\Routing\Adapter\AdapterInterface;
use Galaxy\Routing\Input\CliInputParser;
use Galaxy\Routing\GRouter;
use Galaxy\Routing\Input\InputParserInterface;
use Galaxy\Routing\Exception\RouteNotFoundException;
use Galaxy\Routing\RouteTable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use TypeError;
use UnexpectedValueException;

final class GRouterTest extends TestCase
{

    protected function setUp(): void
    {
        GRProbe::$constructors = GRProbe::$dispatches = GRProbe::$instances = GRProbe::$inputs = [];
    }

    private function parser(Closure $extract): InputParserInterface
    {

        return new class($extract) implements InputParserInterface {

            public int $calls = 0;
            public function __construct(private readonly Closure $extract) {
            }
            public function extractKey(mixed $input): ?array {
                ++$this->calls;
                return ($this->extract)($input);
            }

        };

    }

    private function route(string $kind, array $key, string $id): array
    {
        return ['kind' => $kind, 'key' => $key, 'targetId' => $id];
    }

    private function target(string $id, array $options = [], string $class = GRProbe::class): array
    {
        return ['targetId' => $id, 'adapter' => $class, 'options' => ['tag' => $id, ...$options]];
    }

    public function testLookupConstructorCountersAndRepeatedDispatch(): void
    {

        $table = new RouteTable([
                $this->route('prefix', ['x'], 'short'),
                $this->route('prefix', ['x', 'y'], 'long'),
                $this->route('exact', ['x', 'y'], 'exact'),
            ], array_map(fn (string $id): array => $this->target($id), ['exact', 'long', 'short', 'unused']));
        $parser = $this->parser(static fn ($input): array => $input);
        $router = new GRouter($table, $parser);
        self::assertSame([], GRProbe::$constructors);
        self::assertSame(0, $parser->calls);
        foreach ([[['x', 'y'], 'exact'], [['x', 'y', 'z'], 'long'], [['x'], 'short'], [['x', 'y'], 'exact']] as [$input, $id]) {
            $router->dispatch($input);
            self::assertSame($id, GRProbe::$instances[array_key_last(GRProbe::$instances)]->options['tag']);
        }
        self::assertSame(['exact' => 2, 'long' => 1, 'short' => 1], GRProbe::$constructors);
        self::assertSame(GRProbe::$constructors, GRProbe::$dispatches);
        self::assertSame(4, $parser->calls);
        self::assertCount(4, GRProbe::$instances);
        self::assertNotSame(GRProbe::$instances[0], GRProbe::$instances[3]);
        self::assertSame(2, (new \ReflectionMethod(GRouter::class, '__construct'))->getNumberOfParameters());

    }

    public function testPrefixEqualAndMissingKeysNeverConstructOtherTargets(): void
    {

        $table = new RouteTable([$this->route('prefix', ['x'], 'a')], [$this->target('a'), $this->target('unused')]);
        self::assertTrue((new GRouter($table, $this->parser(static fn () => ['x'])))->dispatch(null)->isSuccess());
        self::assertSame(['a' => 1], GRProbe::$constructors);
        foreach ([null, [], [''], [1], [1 => 'x'], ['y'], ['x', '']] as $key) {

            $parser = $this->parser(static fn () => $key);
            try {
                (new GRouter($table, $parser))->dispatch(new \stdClass());
                self::fail('Expected RouteNotFoundException.');
            } catch (RouteNotFoundException $caught) {
                self::assertInstanceOf(RuntimeException::class, $caught);
            }
            self::assertSame(1, $parser->calls);
            self::assertSame(['a' => 1], GRProbe::$constructors);

        }

    }

    public function testOriginalInputResultDataAndBothCodesKeepIdentity(): void
    {

        $object = new \stdClass();
        $argv = ['scom:demo', '--flag', 'two words', '--flag', '', '--', 'scom:demo'];
        $result = (new GResult())->replaceData(['payload' => [null, false, 0, ['b' => 2, 'a' => 1]]]);
        $result->addError(1001, 'First error.', consoleCode: 3);
        $result->addError(2002, 'Second error.', consoleCode: 8);
        $snapshot = [$result->getAllData(), $result->getErrors(), $result->getCode(), $result->getConsoleCode()];
        $table = new RouteTable([$this->route('exact', ['x'], 'a')], [$this->target('a', ['result' => $result])]);
        foreach ([null, false, 0, $argv, $object] as $input) {

            $parser = $this->parser(static function ($received) use ($input): array {
                    self::assertSame($input, $received);
                    return ['x'];
                });
            $router = new GRouter($table, $parser);
            self::assertSame($result, $router->dispatch($input));
            self::assertSame($input, GRProbe::$inputs[array_key_last(GRProbe::$inputs)]);
            self::assertSame(1, $parser->calls);
            self::assertSame(
                $snapshot,
                [
                    $result->getAllData(),
                    $result->getErrors(),
                    $result->getCode(),
                    $result->getConsoleCode()
                ]
            );

        }
        self::assertSame(1001, $result->getCode());
        self::assertSame(3, $result->getConsoleCode());
        self::assertSame([1001, 2002], array_column($result->getErrors(), 'code'));
        self::assertSame([3, 8], array_column($result->getErrors(), 'consoleCode'));
        self::assertSame(['a' => 5], GRProbe::$constructors);

    }

    public function testSameClassDifferentIdsReceiveTheirOwnOptions(): void
    {

        $table = new RouteTable([
                $this->route('exact', ['left'], '1'), $this->route('exact', ['right'], '01'),
            ], [$this->target('1', ['value' => 'left']), $this->target('01', ['value' => 'right'])]);
        $router = new GRouter($table, new CliInputParser());
        $router->dispatch(['left']);
        $router->dispatch(['right']);
        self::assertSame('left', GRProbe::$instances[0]->options['value']);
        self::assertSame('right', GRProbe::$instances[1]->options['value']);
        self::assertNotSame(GRProbe::$instances[0], GRProbe::$instances[1]);
        self::assertSame(['1' => 1, '01' => 1], GRProbe::$constructors);

    }

    public function testOptionsAreCOWAndNotCopiedIntoResultOrInvoked(): void
    {

        $calls = 0;
        $callback = static function () use (&$calls): void {
            ++$calls;
        };
        $object = new \stdClass();
        $options = ['nested' => ['value' => 'original'], 'callback' => $callback, 'object' => $object];
        $table = new RouteTable([$this->route('exact', ['x'], 'a')], [
                $this->target('a', $options, GRLocalMutation::class),
            ]);
        $result = (new GRouter($table, new CliInputParser()))->dispatch(['x']);
        self::assertSame('constructor changed', GRProbe::$instances[0]->options['nested']['value']);
        self::assertSame('original', $table->getAdapterOptions('a')['nested']['value']);
        self::assertSame('original', $options['nested']['value']);
        self::assertSame($callback, GRProbe::$instances[0]->options['callback']);
        self::assertSame($object, GRProbe::$instances[0]->options['object']);
        self::assertSame([], $result->getAllData());
        self::assertSame(0, $calls);

    }

    public function testParserConstructorAndAdapterFailuresKeepIdentityWithoutFallback(): void
    {

        foreach (['parser', 'constructor', 'adapter'] as $boundary) {

            GRProbe::$constructors = GRProbe::$dispatches = GRProbe::$instances = GRProbe::$inputs = [];
            $failure = new RuntimeException($boundary);
            $options = match ($boundary) {
                'constructor' => ['constructorFailure' => $failure],
                'adapter' => ['dispatchFailure' => $failure],
                default => [],
            };
            $table = new RouteTable([
                    $this->route('exact', ['x'], 'selected'), $this->route('prefix', ['x'], 'other'),
                ], [$this->target('selected', $options), $this->target('other')]);
            $parser = $this->parser(static function () use ($boundary, $failure): array {
                    if ($boundary === 'parser') {
                        throw $failure;
                    }
                    return ['x'];
                });
            try {
                (new GRouter($table, $parser))->dispatch('input');
                self::fail('Expected unchanged failure.');
            } catch (RuntimeException $caught) {
                self::assertSame($failure, $caught);
            }
            self::assertSame(1, $parser->calls);
            self::assertSame($boundary === 'parser' ? [] : ['selected' => 1], GRProbe::$constructors);
            self::assertSame($boundary === 'adapter' ? ['selected' => 1] : [], GRProbe::$dispatches);

        }
        foreach ([
                new \Error(
                    'Constructor Error'
                ),
                new TypeError(
                    'Constructor TypeError'
                ),
                new RouteNotFoundException(
                    'AdapterInterface route failure'
                )
            ] as $failure) {

            GRProbe::$constructors = GRProbe::$dispatches = [];
            $option = $failure instanceof RouteNotFoundException ? 'dispatchFailure' : 'constructorFailure';
            $table = new RouteTable([$this->route('prefix', ['x'], 'a')], [
                    $this->target('a', [$option => $failure]), $this->target('other'),
                ]);
            try {
                (new GRouter($table, new CliInputParser()))->dispatch(['x']);
                self::fail('Expected original Throwable.');
            } catch (Throwable $caught) {
                self::assertSame($failure, $caught);
            }
            self::assertSame(['a' => 1], GRProbe::$constructors);

        }

    }

    public function testNativeConstructorAndDispatchErrorsAreNotWrapped(): void
    {

        foreach (['type', 'error'] as $mode) {

            GRProbe::$constructors = GRProbe::$dispatches = [];
            $table = new RouteTable([$this->route('exact', ['x'], 'a')], [
                    $this->target('a', ['mode' => $mode], GRNativeConstructorFault::class), $this->target('other'),
                ]);
            try {
                (new GRouter($table, new CliInputParser()))->dispatch(['x']);
                self::fail('Expected native constructor failure.');
            } catch (\Error $caught) {
                self::assertSame($mode === 'type', $caught instanceof TypeError);
            }
            self::assertSame(['a' => 1], GRProbe::$constructors);
            self::assertSame([], GRProbe::$dispatches);

        }
        GRProbe::$constructors = GRProbe::$dispatches = [];
        $table = new RouteTable([$this->route('exact', ['x'], 'a')], [
                $this->target('a', [], GRInvalidReturn::class), $this->target('other'),
            ]);
        try {
            (new GRouter($table, new CliInputParser()))->dispatch(['x']);
            self::fail('Expected native AdapterInterface return TypeError.');
        } catch (TypeError) {
            self::assertSame(['a' => 1], GRProbe::$constructors);
            self::assertSame(['a' => 1], GRProbe::$dispatches);
        }

    }

    public function testLegacyFactoriesRejectedAndCliDoubleStatuses(): void
    {

        try {
            new RouteTable([], [['targetId' => 'old', 'factory' => static fn () => null]]);
            self::fail('Legacy factory accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame([], GRProbe::$constructors);
        }
        foreach ([0, 1, 255, -1, 256] as $status) {

            GRProbe::$constructors = GRProbe::$dispatches = [];
            $table = new RouteTable([
                    $this->route('exact', ['cli'], 'a'), $this->route('prefix', ['cli'], 'other'),
                ], [$this->target('a', ['status' => $status], GRCliStatus::class), $this->target('other')]);
            $router = new GRouter($table, new CliInputParser());
            if ($status < 0 || $status > 255) {

                try {
                    $router->dispatch(['cli']);
                    self::fail('Invalid CLI status accepted.');
                } catch (UnexpectedValueException) {
                    self::assertTrue(true);
                }

            } else {
                $result = $router->dispatch(['cli']);
                self::assertSame($status === 0, $result->isSuccess());
                self::assertSame($status === 0 ? 0 : 1001, $result->getCode());
                self::assertSame($status, $result->getConsoleCode());
            }
            self::assertSame(['a' => 1], GRProbe::$constructors);
            self::assertSame(['a' => 1], GRProbe::$dispatches);

        }

    }

    public function testCliParser(): void
    {

        $parser = new CliInputParser();
        foreach ([
                [[], ['list']], [['help'], ['help']], [['--help'], ['help']],
                [['scom:tools:demo', '--flag', '', '--', 'tail'], ['scom', 'tools', 'demo']],
                [['help', 'scom:demo', '--format=json'], ['scom', 'demo']],
                [['scom:demo', '--help'], ['scom', 'demo']],
                [['list'], ['list']], [['list', '--format=json', 'extra'], ['list']],
                [['Help'], ['Help']], [['01'], ['01']], [['x', '', '--', '--help'], ['x']],
            ] as [$input, $expected]) {
            self::assertSame($expected, $parser->extractKey($input));
        }
        foreach ([null, false, 1, 'x', new \stdClass(), [1 => 'x'], ['key' => 'x'], [1],
                ['x', null], [''], ['-x'], ['--', 'x'], ['--verbose', 'x'], ['--help', 'x'],
                ['help', ''], ['help', '-x'], ['help', 'x:'], [':x'], ['x:'], ['x::y']] as $input) {
            self::assertNull($parser->extractKey($input));
        }

    }

    public function testAdapterOwnsPrefixRemovalAndReceivesOriginalHelpTokens(): void
    {

        $input = ['help', 'scom:demo', '', '--', 'scom:demo'];
        $table = new RouteTable([$this->route('prefix', ['scom'], 'a')], [$this->target('a', [], GRStripPrefix::class)]);
        $result = (new GRouter($table, new CliInputParser()))->dispatch($input);
        self::assertSame($input, GRProbe::$inputs[0]);
        self::assertSame(['help', 'demo', '', '--', 'scom:demo'], $result->getData('argv'));
        self::assertSame('scom:demo', $input[1]);

    }

}

class GRProbe implements AdapterInterface
{

    public static array $constructors = [];
    public static array $dispatches = [];
    public static array $instances = [];
    public static array $inputs = [];
    public array $options;

    public function __construct(array $options = [])
    {

        $tag = $options['tag'] ?? 'default';
        self::$constructors[$tag] = (self::$constructors[$tag] ?? 0) + 1;
        $this->options = $options;
        if (isset($options['constructorFailure'])) {
            throw $options['constructorFailure'];
        }
        self::$instances[] = $this;

    }

    public function dispatch(mixed $input): GResult
    {

        $tag = $this->options['tag'] ?? 'default';
        self::$dispatches[$tag] = (self::$dispatches[$tag] ?? 0) + 1;
        self::$inputs[] = $input;
        if (isset($this->options['dispatchFailure'])) {
            throw $this->options['dispatchFailure'];
        }
        return $this->options['result'] ?? new GResult();

    }

}

class GRLocalMutation extends GRProbe
{
    public function __construct(array $options = [])
    {
        $options['nested']['value'] = 'constructor changed';
        parent::__construct($options);
    }
}
class GRNativeConstructorFault extends GRProbe
{

    public function __construct(array $options = [])
    {

        parent::__construct($options);
        if ($options['mode'] === 'type') {
            strlen([]);
        } else {
            (new \stdClass())->missingMethod();
        }

    }

}
class GRInvalidReturn extends GRProbe
{
    public function dispatch(mixed $input): GResult
    {
        parent::dispatch($input);
        return null;
    }
}
class GRCliStatus extends GRProbe
{

    public function dispatch(mixed $input): GResult
    {

        $result = parent::dispatch($input);
        $status = $this->options['status'];
        if ($status < 0 || $status > 255) {
            throw new UnexpectedValueException('CLI status must be between 0 and 255.');
        }
        if ($status !== 0) {
            $result->addError(1001, 'Command failed.', consoleCode: $status);
        }
        return $result;

    }

}
class GRStripPrefix extends GRProbe
{

    public function dispatch(mixed $input): GResult
    {
        $result = parent::dispatch($input);
        $index = $input[0] === 'help' ? 1 : 0;
        $input[$index] = substr($input[$index], strlen('scom:'));
        return $result->replaceData(['argv' => $input]);
    }

}
