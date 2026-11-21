<?php
declare(strict_types=1);

if (count($argv) !== 2 || !is_file($argv[1] . '/vendor/autoload.php')) {
    throw new RuntimeException('Supply the installed consumer root.');
}
$consumer = realpath($argv[1]);
require $consumer . '/vendor/autoload.php';

use Galaxy\Result\GResult;
use Galaxy\Routing\Adapter\AdapterInterface;
use Galaxy\Routing\Adapter\HelpAdapter;
use Galaxy\Routing\Adapter\ListAdapter;
use Galaxy\Routing\Exception\RouteNotFoundException;
use Galaxy\Routing\GRouter;
use Galaxy\Routing\Input\CliInputParser;
use Galaxy\Routing\Input\InputParserInterface;
use Galaxy\Routing\RouteTable;

$checks = 0;
function ensure(bool $condition, string $message): void {
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$installed = json_decode(
    file_get_contents($consumer . '/vendor/composer/installed.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$names = array_column($installed['packages'], 'name');
sort($names);
ensure($names === ['denetorus/galaxy-result', 'denetorus/galaxy-routing'], 'Unexpected runtime dependencies.');
ensure($installed['dev'] === false, 'Development dependencies were installed.');

$mirror = $consumer . '/vendor/denetorus/galaxy-routing';
ensure(!is_link($mirror), 'Consumer package must be a copy.');
$types = [
    GRouter::class => 'GRouter.php',
    RouteTable::class => 'RouteTable.php',
    InputParserInterface::class => 'Input/InputParserInterface.php',
    CliInputParser::class => 'Input/CliInputParser.php',
    AdapterInterface::class => 'Adapter/AdapterInterface.php',
    ListAdapter::class => 'Adapter/ListAdapter.php',
    HelpAdapter::class => 'Adapter/HelpAdapter.php',
    RouteNotFoundException::class => 'Exception/RouteNotFoundException.php',
];
foreach ($types as $class => $relative) {

    $file = (new ReflectionClass($class))->getFileName();
    ensure(realpath($file) === realpath($mirror . '/src/' . $relative), 'Unexpected PSR-4 source.');
    ensure(
        hash_file('sha256', $file) === hash_file('sha256', dirname(__DIR__) . '/src/' . $relative),
        'Installed source differs from the package.'
    );

}
ensure(
    str_starts_with(
        realpath((new ReflectionClass(GResult::class))->getFileName()),
        realpath($consumer . '/vendor/denetorus/galaxy-result') . DIRECTORY_SEPARATOR
    ),
    'Result must come from the consumer installation.'
);

final class ConsumerAdapter implements AdapterInterface {

    public static int $created = 0;

    public function __construct(private readonly array $options = []) {
        ++self::$created;
    }

    public function dispatch(mixed $input): GResult {
        return $this->options['result'];
    }

}

$expected = (new GResult())->replaceData(['source' => 'consumer']);
$expected->addError(1001, 'Demonstration failure.', consoleCode: 3);
$table = new RouteTable([
    ['kind' => 'exact', 'key' => ['demo'], 'targetId' => 'demo'],
    ['kind' => 'prefix', 'key' => ['unused'], 'targetId' => 'unused'],
], [
    ['targetId' => 'demo', 'adapter' => ConsumerAdapter::class, 'options' => ['result' => $expected]],
    ['targetId' => 'unused', 'adapter' => ConsumerAdapter::class],
]);
$router = new GRouter($table, new CliInputParser());
ensure(ConsumerAdapter::$created === 0, 'Adapters constructed during registration.');
$actual = $router->dispatch(['demo', '--flag']);
ensure($actual === $expected, 'Result identity changed.');
ensure($actual->getCode() === 1001 && $actual->getConsoleCode() === 3, 'Codes changed.');
ensure(ConsumerAdapter::$created === 1, 'Unselected adapter constructed.');
$router->dispatch(['demo']);
ensure(ConsumerAdapter::$created === 2, 'Adapter was cached.');
foreach ([[], ['list'], ['help'], ['--help']] as $input) {
    $result = $router->dispatch($input);
    ensure($result->isSuccess() && $result->getConsoleCode() === 0, 'Built-in failed.');
    ensure(count($result->getAllData()['routes']) === 4, 'Incomplete route catalog.');
}
ensure(ConsumerAdapter::$created === 2, 'Built-in constructed an application adapter.');
ensure(!class_exists('PHPUnit\\Framework\\TestCase'), 'Consumer loaded PHPUnit.');
echo 'PASS consumer: ', $checks, ' checks; runtime-only install, PSR-4, lazy dispatch and built-ins.', PHP_EOL;
