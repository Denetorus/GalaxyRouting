# GalaxyRouting

[English](README.md) · [ADR-001 — Архитектура](docs/adr/ADR-001-GalaxyRouting.ru.md)

GalaxyRouting выбирает один адаптер по точному или наиболее длинному префиксному
совпадению сегментов и передаёт ему исходный вход. Пространство имён: Galaxy\Routing.

Архитектура, публичный API, конфигурация, правила CLI, встроенные команды,
границы представления, команды установки и исполняемый пример определены в
[ADR-001](docs/adr/ADR-001-GalaxyRouting.ru.md).

Требования: PHP >=8.4 и denetorus/galaxy-result ^0.2.0.
Dev-зависимость: phpunit/phpunit ^13.4.

## Установка

Установите опубликованный пакет из [Packagist](https://packagist.org/packages/denetorus/galaxy-routing):

```bash
composer require denetorus/galaxy-routing:0.1.0
```

Composer также установит необходимую зависимость GalaxyResult. Дополнительная настройка репозиториев не нужна.

Лицензия: [MIT](LICENSE).
