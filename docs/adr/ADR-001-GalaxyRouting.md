# ADR-001 — GalaxyRouting

**Status: Accepted**

[Русский](ADR-001-GalaxyRouting.ru.md)

## 1. Purpose and dependencies

GalaxyRouting is an independent library that selects and invokes one adapter using a segment key.
Composer package: denetorus/galaxy-routing. Namespace: Galaxy\Routing.
Runtime requirements: PHP >=8.4 and denetorus/galaxy-result ^0.2.0.
Development dependency: phpunit/phpunit ^13.4. License: MIT, Copyright (c) 2026 Mylnikov Denis.

The component provides RouteTable, GRouter, InputParserInterface, AdapterInterface, CliInputParser,
ListAdapter and HelpAdapter. Core, Console, Application, Symfony and PSR-11 are not dependencies.
The application supplies routes, adapter classes, options and an input parser.

## 2. Public API

Classes are grouped by purpose. Component interface names end with Interface.

~~~text
src/
├── GRouter.php
├── RouteTable.php
├── Input/
│   ├── InputParserInterface.php
│   └── CliInputParser.php
├── Adapter/
│   ├── AdapterInterface.php
│   ├── ListAdapter.php
│   └── HelpAdapter.php
└── Exception/
    └── RouteNotFoundException.php
~~~

~~~text
namespace Galaxy\Routing;

use Galaxy\Result\GResult;
use Galaxy\Routing\Input\InputParserInterface;

final class RouteTable {
    public function __construct(array $routes, array $targets);
    public function resolve(array $key): ?string;
    public function getAdapterClass(string $targetId): string;
    public function getAdapterOptions(string $targetId): array;
    public function getRoutes(): array;
}

final class GRouter {
    public function __construct(RouteTable $table, InputParserInterface $parser);
    public function dispatch(mixed $input): GResult;
}

namespace Galaxy\Routing\Input;

interface InputParserInterface {
    public function extractKey(mixed $input): ?array;
}

final class CliInputParser implements InputParserInterface {
    public function extractKey(mixed $input): ?array;
}

namespace Galaxy\Routing\Adapter;

use Galaxy\Result\GResult;

interface AdapterInterface {
    public function __construct(array $options = []);
    public function dispatch(mixed $input): GResult;
}

final class ListAdapter implements AdapterInterface {
    public function __construct(array $options = []);
    public function dispatch(mixed $input): GResult;
}

final class HelpAdapter implements AdapterInterface {
    public function __construct(array $options = []);
    public function dispatch(mixed $input): GResult;
}

namespace Galaxy\Routing\Exception;

class RouteNotFoundException extends \RuntimeException {}
~~~

This reference describes signatures; a complete executable example is provided below.

## 3. Routes and targets

routes is a strict PHP list. Each record contains required kind (exact/prefix),
key (a nonempty list of nonempty strings), targetId (a nonempty registered ID),
and optional string description, default ''.
targets is a strict list of records with required nonempty targetId and adapter class strings,
and optional array options, default [].

Empty configuration lists and unused targets are valid. Extra fields are invalid.
Target IDs are unique; route keys are unique within a kind. The same key may be exact and prefix.
Every route references a registered target. Numeric-looking strings preserve their identity.

RouteTable validates the entire configuration and throws InvalidArgumentException for invalid definitions.
All target classes are autoloaded and checked for existence, instantiability and implementation of AdapterInterface.
Constructors are not executed by this validation.
PHP determines constructor signature compatibility, including permitted variance and extra optional parameters.
Class strings are stored verbatim; PHP-supported aliases, casing and leading backslashes are accepted.
This does not relax route/ID comparison. Autoloader exceptions propagate unchanged;
invalid class declarations may cause native PHP load/compile errors.

## 4. Keys and selection

Keys are nonempty lists of nonempty strings. Comparison is positional, byte-exact and case-sensitive.
There is no trimming, decoding, case folding, coercion or implicit segment splitting.
['a:b'] differs from ['a','b']; ['1'] differs from ['01'].

An exact match wins; otherwise the longest matching whole-segment prefix wins.
A prefix may equal the entire key. Configuration order does not affect selection.
resolve returns a target ID or null for an invalid array key or no match.
A non-array argument is subject to PHP's array parameter type.

getAdapterClass/getAdapterOptions expose registered target data.
An unknown target ID throws OutOfBoundsException.

## 5. State and options

Routes, keys and scalar registry fields are detached from caller references.
getRoutes returns normalized four-field records in application order.

Options use ordinary PHP array copy-on-write without reference-returning accessors.
Ordinary array edits do not alter the stored array. Nested references and objects/closures/resources
retain PHP shared-state semantics. There is no deep cloning, serialization or hidden callback execution.
User options have no global key/type allowlist or JSON-only restriction.
Adapters validate their own settings when constructed. Options are not automatically included in results or output.

## 6. Dispatch

GRouter calls the parser once with the original input, resolves one target,
reads its class/options, executes new $class($options), calls dispatch once
and returns exactly the same GResult instance.

A null parser key, invalid array key or unmatched key causes RouteNotFoundException without constructing adapters.
Every successful match creates a new adapter instance; adapters are not cached.
Shared state inside options may outlive an adapter.
The router uses no container, factory callbacks or autowiring.

Parser/autoloader/constructor/adapter exceptions and native TypeError/Error propagate unchanged.
There is no wrapping, automatic GResult conversion, retry or fallback.
Object input retains identity; arrays retain their values/order under ordinary PHP semantics.
The router does not remove prefixes, parse command arguments, print results, assign codes or call exit.

## 7. CLI parser

CliInputParser accepts a list of string tokens excluding the script name.
Invalid types, non-list arrays or any nonstring token return null.
[] maps to ['list']; exactly ['help'] and exactly ['--help'] map to ['help'].

Otherwise the first token is the command, except exact help uses the second token.
The command is split on literal ':'. Empty names, leading '-' and empty segments return null.
Remaining string tokens do not affect the key. Leading global options or '--' are not skipped.
No joining or normalization occurs. The entire original input reaches the selected adapter.

Examples: ['scom:demo','--flag'] gives ['scom','demo'];
['help','scom:demo','--format=json'] gives the same key.
['scom:demo','--help'] also selects the command adapter.

## 8. Built-in list/help

RouteTable adds an exact ['list'] or ['help'] route only when that exact application route is absent.
A user prefix does not disable a built-in exact route. Overrides are independent.
The IDs @galaxy-routing/list and @galaxy-routing/help are reserved:
applications cannot register or reference them in their own records.

Effective metadata keeps application order, then added list, then added help.
Fixed route descriptions are 'List available routes.' and 'Show routing help.'.
Internal targets use ListAdapter/HelpAdapter with ['routes'=>$effectiveRoutes].
They follow the same direct-new dispatch path. User options receive no injected metadata.
An internal target absent due to an override is unknown to its accessor.

ListAdapter/HelpAdapter accept only an optional routes array, default [].
It must be a list of complete kind/key/targetId/description records with valid values/segments;
invalid or extra fields throw InvalidArgumentException.
Metadata validation does not require unique route keys or a target registry.
Plain metadata is detached from caller references.

Standalone new ListAdapter()/new HelpAdapter() use empty metadata.
Applications may register those classes under their own IDs and provide their own routes option.
Only automatically added internal targets receive the table's full effective snapshot.

Each dispatch creates a fresh successful GResult: no errors and business/console codes 0/0.
List data is ['kind'=>'list','routes'=>$effectiveRoutes].
Help data adds description=>'Route an input to one registered target.' with kind=>'help' and routes.
No output is emitted and unrelated adapters/engines are not constructed.
Command-specific help goes to the command adapter; HelpAdapter provides the general catalog.

## 9. Results and presentation

AdapterInterface/GRouter return Galaxy\Result\GResult without rebuilding, cloning, reordering entries or changing codes/data.
The first current error determines getCode/getConsoleCode according to GalaxyResult.

Application controllers/actions return data and diagnostics instead of printing directly.
Adapters expose them through GResult; the application's presenter formats content without command-name branching.
The presenter does not route, alter codes or terminate the process.

A third-party CLI engine may print its own output.
The adapter does not copy already printed content into GResult for repeated presentation.
A normal status 0 produces success with codes 0/0.
For a valid nonzero status 1..255, the adapter adds one independently worded summary error,
with an application-selected business code and consoleCode equal to the engine status.
The presenter may print this summary after engine diagnostics. Business and console codes need not be equal.
An invalid status throws; there is no silent clamping/normalization.
Exceptions are not normal nonzero statuses and are not universally converted.

No alreadyPrinted/delivered flags or delivery infrastructure are needed to avoid repeated output.
The adapter controls result content. The CLI entry point arranges presentation and process termination
using getConsoleCode; stream formatting and exception presentation belong to the application.

## 10. HTTP, queues and processes

HTTP keys use only the path. Method, headers and body remain in the original input for the adapter.
GET and POST for the same path share the route key.
Request shape, root/trailing-slash/decoding policy, response statuses and presentation belong to HTTP integration.
A ready-made HTTP parser and HTTP request/response API are outside the component's public surface.

Dispatch runs in the current process.
Queue reads, acknowledgements, retries, timeouts, concurrency and child processes belong to the application.
Containers and service lifetimes inside an adapter also belong to the host.
RouteTable does not mandate a transport separator; ':' is a CliInputParser convention.

## 11. Contract verification

Checks cover strict configuration, exact keys/IDs, exact/longest-prefix priority,
route snapshots, options COW/shared references/handles, autoload/constructor compatibility,
zero eager construction, selected-only fresh adapter instances, input/result/exception identity,
built-ins, overrides and reserved IDs.

Install and run the configured suite from the package root:

~~~sh
composer --no-plugins --no-scripts install --prefer-dist --no-interaction --no-progress
php vendor/bin/phpunit --configuration phpunit.xml
~~~
GalaxyResult resolves from standard Packagist under the manifest constraint;
standalone package verification does not substitute local Result copies or synthetic versions.
CLI output and process status are checked at the host boundary.

## 12. Example

Save this example in the package root as example.php after installing dependencies.

~~~php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Galaxy\Result\GResult;
use Galaxy\Routing\Adapter\AdapterInterface;
use Galaxy\Routing\Input\CliInputParser;
use Galaxy\Routing\GRouter;
use Galaxy\Routing\RouteTable;

final class DemoAdapter implements AdapterInterface {

    public function __construct(private readonly array $options = []) {
    }

    public function dispatch(mixed $input): GResult {
        return (new GResult())->replaceData([
            'label' => $this->options['label'] ?? 'demo',
            'input' => $input,
        ]);
    }

}

$table = new RouteTable([
    ['kind' => 'prefix', 'key' => ['demo'], 'targetId' => 'demo', 'description' => 'Demonstration command'],
], [
    ['targetId' => 'demo', 'adapter' => DemoAdapter::class, 'options' => ['label' => 'example']],
]);
$router = new GRouter($table, new CliInputParser());
$result = $router->dispatch(array_slice($argv, 1));

echo json_encode($result->getAllData(), JSON_THROW_ON_ERROR), PHP_EOL;
exit($result->getConsoleCode());
~~~

~~~sh
php example.php demo --flag
php example.php list
php example.php help
~~~

demo --flag produces:

~~~json
{"label":"example","input":["demo","--flag"]}
~~~

list/help return successful route catalog data with process status 0.
