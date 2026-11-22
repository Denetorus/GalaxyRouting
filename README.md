# GalaxyRouting

[Русский](README.ru.md) · [ADR-001 — Architecture](docs/adr/ADR-001-GalaxyRouting.md)

GalaxyRouting selects one adapter using exact or longest-prefix segment matching
and dispatches the original input to it. Namespace: Galaxy\Routing.

The complete architecture, public API, configuration, CLI rules, built-in commands,
presentation boundaries, setup commands and executable example are defined in
[ADR-001](docs/adr/ADR-001-GalaxyRouting.md).

Requirements: PHP >=8.4 and denetorus/galaxy-result ^0.2.0.
Development dependency: phpunit/phpunit ^13.4.

## Installation

Install the published package from [Packagist](https://packagist.org/packages/denetorus/galaxy-routing):

```bash
composer require denetorus/galaxy-routing:0.1.0
```

Composer also installs the required GalaxyResult dependency. No custom repository configuration is needed.

License: [MIT](LICENSE).
