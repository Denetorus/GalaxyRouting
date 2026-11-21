# ADR-001 — GalaxyRouting

**Статус: Accepted**

[English](ADR-001-GalaxyRouting.md)

## 1. Назначение и зависимости

GalaxyRouting — самостоятельная библиотека выбора и вызова одного адаптера по сегментному ключу.
Composer-пакет: denetorus/galaxy-routing. Пространство имён: Galaxy\Routing.
Runtime: PHP >=8.4 и denetorus/galaxy-result ^0.2.0. Dev-зависимость: phpunit/phpunit ^13.4.
Лицензия: MIT, Copyright (c) 2026 Mylnikov Denis.

Компонент предоставляет RouteTable, GRouter, интерфейсы InputParserInterface и AdapterInterface,
CLI-разборщик CliInputParser, встроенные ListAdapter и HelpAdapter.
GalaxyCore, GalaxyConsole, GalaxyApplication, Symfony и PSR-11 не являются его зависимостями.

Приложение определяет маршруты, классы адаптеров, их настройки и подходящий разборщик.
Маршрутизатор выбирает одну цель и передаёт её адаптеру исходный вход.

## 2. Публичный API

Классы сгруппированы по назначению. Имена интерфейсов компонента заканчиваются на Interface.

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

Этот блок описывает сигнатуры; исполняемый пример приведён ниже.

## 3. Маршруты и цели

routes — строгий PHP list записей:

| Поле | Контракт |
|---|---|
| kind | Обязательная строка exact или prefix |
| key | Обязательный непустой list непустых строк |
| targetId | Обязательная непустая строка зарегистрированной цели |
| description | Необязательная строка, по умолчанию '' |

targets — строгий PHP list записей:

| Поле | Контракт |
|---|---|
| targetId | Обязательная уникальная непустая строка |
| adapter | Обязательная непустая строка класса, реализующего AdapterInterface |
| options | Необязательный массив, по умолчанию [] |

Пустые исходные списки допустимы. Неиспользуемые цели допустимы.
Дополнительные поля в записях запрещены. Каждый маршрут должен ссылаться на зарегистрированную цель.
Числовые строки ID не преобразуются: '1' и '01' — разные цели.

RouteTable полностью проверяет определения при создании.
Ошибочная форма, неизвестная ссылка, дубликат targetId или одинакового key в пределах одного kind
вызывают InvalidArgumentException. Одинаковый key у exact и prefix допустим.

Все классы целей проверяются через autoload и Reflection: класс существует,
является concrete/instantiable и реализует AdapterInterface. Конструкторы адаптеров при этом не вызываются.
PHP определяет совместимость конструктора с интерфейсом: допустимы совместимые варианты
типов и дополнительные необязательные параметры. Искусственная проверка точного имени
или количества параметров не вводится.

Строка класса хранится без нормализации. Допустимые PHP aliases, регистр имени класса
и начальный backslash принимаются по правилам PHP. Это не меняет точность сравнения маршрутов и targetId.
Исключения из чужого autoloader проходят наружу; ошибки объявления класса могут быть native PHP load/compile errors.

## 4. Ключи и выбор маршрута

Ключ — непустой list непустых строк. Сегменты сравниваются позиционно, побайтно и с учётом регистра.
Нет trim, декодирования, изменения регистра, приведения типов или скрытого разделения сегментов.
['a:b'] отличается от ['a', 'b']; ['1'] отличается от ['01'].
Внутри сегмента двоеточие не имеет специального значения.

Приоритет выбора:

1. Точное совпадение exact.
2. Самый длинный совпавший prefix по числу полных сегментов.
3. Отсутствие результата.

Префикс совпадает и с ключом той же длины. Порядок определений не влияет на выбор.
resolve возвращает targetId либо null для некорректного массива ключа или отсутствия совпадения.
Тип аргумента resolve — array; неправильный PHP-тип обрабатывается PHP.

getAdapterClass/getAdapterOptions возвращают данные зарегистрированной цели.
Неизвестный targetId вызывает OutOfBoundsException.

## 5. Состояние и options

Маршруты, их ключи и скалярные поля реестра отделяются от внешних ссылок.
getRoutes возвращает по значению нормализованные записи с четырьмя полями:
kind, key, targetId, description. Порядок пользовательских маршрутов сохраняется.

options передаётся по значению с обычной PHP array/copy-on-write семантикой.
Обычная модификация массива в адаптере не меняет сохранённый массив таблицы.
Объекты, closures, resources и вложенные PHP references сохраняют обычную идентичность и разделяемое состояние.
Глубокое копирование, сериализация и скрытое исполнение значений options не выполняются.

Для пользовательских options нет глобального списка ключей, ограничений типов значений
или требований JSON-совместимости. Собственную схему и обязательные настройки проверяет адаптер.
options автоматически не включается в GResult или вывод: там могут находиться служебные данные и секреты.

## 6. Исполнение GRouter

Один dispatch выполняет следующие действия:

1. Один раз вызывает parser->extractKey с исходным input.
2. Находит цель через RouteTable.
3. Получает выбранные adapter class и options.
4. Создаёт только выбранный адаптер вызовом new $class($options).
5. Один раз вызывает adapter->dispatch с исходным input.
6. Возвращает тот же экземпляр GResult без преобразования.

null от разборщика, некорректный массив ключа или отсутствие маршрута приводят к RouteNotFoundException.
Конструкторы адаптеров в этих случаях не вызываются.

Каждый совпавший dispatch создаёт новый экземпляр адаптера. Кеш адаптеров отсутствует.
Общие объекты и ссылки внутри options при этом могут сохранять состояние между вызовами.
GRouter не использует контейнер, фабрики, autowiring или повторный выбор другого маршрута.

Исключения разборщика, autoloader, конструктора и адаптера, а также native TypeError/Error
проходят наружу без оборачивания и автоматического преобразования в GResult.
Повторных вызовов и fallback нет.

Объект input сохраняет идентичность; массивы передаются по обычным правилам PHP,
без изменения значений и порядка. GRouter не снимает префикс, не разбирает параметры команды,
не печатает результат, не назначает коды и не вызывает exit. Специализированный разбор выполняет адаптер.

## 7. CliInputParser

Вход — list<string> токенов без имени PHP-скрипта, например array_slice($argv, 1).
Неверный тип входа, non-list или нестроковый токен в любой позиции дают null.
Токены не склеиваются и не нормализуются.

| Вход | Ключ |
|---|---|
| [] | ['list'] |
| ['help'] | ['help'] |
| ['--help'] | ['help'] |
| ['scom:demo', '--flag', '', '--', 'tail'] | ['scom', 'demo'] |
| ['help', 'scom:demo', '--format=json'] | ['scom', 'demo'] |
| ['scom:demo', '--help'] | ['scom', 'demo'] |

Команда берётся целиком из первого токена, кроме точного help: для него используется второй токен.
Имя команды делится буквальным ':'. Пустое имя, начальный дефис или пустой сегмент дают null.
Глобальные опции перед командой и начальный -- автоматически не пропускаются.
Остальные строковые токены не анализируются как опции и не влияют на ключ.
Исходный массив целиком передаётся адаптеру, включая help, префикс, пустые строки и --.

## 8. Встроенные list/help

Если отсутствует точный пользовательский маршрут ['list'] или ['help'],
RouteTable добавляет соответствующий exact-маршрут.
Пользовательский prefix не отключает встроенный exact.
Точный маршрут приложения переопределяет только соответствующую встроенную команду.

Зарезервированные targetId:

- @galaxy-routing/list
- @galaxy-routing/help

Приложение не может объявлять эти ID или ссылаться на них в собственных маршрутах.
Внутренняя цель создаётся только для действительно добавленного встроенного маршрута.
Если цель не создана из-за override, прямой accessor к ней даёт OutOfBoundsException.

getRoutes содержит пользовательские записи в исходном порядке, затем добавленный list и затем help.
Описание list: 'List available routes.'; описание help: 'Show routing help.'.

Внутренние цели используют ListAdapter/HelpAdapter и options ['routes' => $effectiveRoutes].
GRouter создаёт их тем же способом, что и пользовательские адаптеры.
Пользовательским целям метаданные таблицы автоматически не подмешиваются.

ListAdapter и HelpAdapter принимают только необязательный ключ options.routes, по умолчанию [].
routes — list полных записей kind/key/targetId/description с корректными значениями и сегментами.
Лишние поля или неправильная форма вызывают InvalidArgumentException.
Их собственная проверка метаданных не требует уникальности ключей или существования реестра целей.
Переданные plain-value метаданные отделяются от внешних ссылок.

Прямое new ListAdapter()/new HelpAdapter() использует пустой каталог.
Приложение может зарегистрировать эти классы под собственным targetId и передать свой каталог через options.
Полный effective snapshot получают именно автоматически добавленные внутренние цели.

Каждый dispatch встроенного адаптера возвращает новый успешный GResult:
errors=[], getCode()=0, getConsoleCode()=0.
Данные list:

~~~php
['kind' => 'list', 'routes' => $effectiveRoutes]
~~~

Данные help:

~~~php
[
    'kind' => 'help',
    'description' => 'Route an input to one registered target.',
    'routes' => $effectiveRoutes,
]
~~~

Встроенные адаптеры ничего не печатают. При их выборе другие адаптеры и движки не создаются.
Справка по конкретной команде направляется её адаптеру; встроенный HelpAdapter показывает общий каталог.

## 9. Результаты и представление

AdapterInterface и GRouter возвращают Galaxy\Result\GResult.
Данные, записи, их порядок, бизнес-код и consoleCode сохраняются без пересборки или копирования.
Агрегаты getCode/getConsoleCode определяет первая текущая ошибка по контракту GalaxyResult.

Собственные контроллеры/действия приложения возвращают данные и диагностические записи,
не выполняя непосредственный вывод. Адаптер передаёт их через GResult.
Presenter приложения представляет результат по общим правилам, без распознавания имени команды.
Он не выбирает маршрут, не меняет коды и не завершает процесс.

Сторонний CLI-движок может выводить собственные данные и диагностику.
Адаптер не включает уже выведенное содержимое в GResult для повторной печати.
Штатный статус 0 становится успешным результатом с кодами 0/0.
Для допустимого ненулевого статуса 1..255 адаптер добавляет одну собственную итоговую ошибку,
с отдельно выбранным бизнес-кодом и consoleCode, равным статусу движка.
Presenter может вывести эту итоговую запись после диагностики движка.
Это отдельный итог, а не копия ранее напечатанной диагностики.
Недопустимый статус вызывает исключение; скрытое ограничение диапазона или нормализация запрещены.

Исключение не считается штатным ненулевым статусом; универсального преобразования исключений нет.
Флаги alreadyPrinted/delivered и инфраструктура доставки для подавления повторного вывода не требуются.
Неповторение вывода обеспечивается тем, что адаптер включает в GResult.

CLI-точка входа организует вызов Presenter и завершение через $result->getConsoleCode().
Конкретное оформление, потоки вывода и обработку исключений определяет приложение.

## 10. HTTP, очереди и процессы

Для HTTP ключ образуется только из пути. Метод, заголовки, тело и остальные данные
сохраняются в исходном запросе для адаптера.
GET /salary/calculate и POST /salary/calculate соответствуют одному сегментному ключу.
Конкретная форма HTTP-входа, политика корневого/завершающего '/', декодирование,
статусы ответа и представление определяются HTTP-интеграцией приложения.
Готовый HTTP-разборщик и HTTP request/response API не входят в публичный состав компонента.

GRouter выполняет dispatch в текущем процессе.
Чтение очереди, ack/nack, повторы, тайм-ауты, параллельность и дочерние процессы принадлежат приложению.
Контейнер и время жизни сервисов внутри адаптера также организует приложение.
Универсальная таблица маршрутов не задаёт транспортный разделитель: ':' использует CliInputParser.

## 11. Проверки контракта

Проверки охватывают строгую форму конфигурации, точные ключи и ID, exact/longest-prefix,
отделение маршрутов, options COW/shared handles/references, autoload и совместимость конструкторов,
нулевое создание до dispatch, selected-only new, новый адаптер при повторном dispatch,
идентичность input/result/исключений, встроенные команды, override и зарезервированные ID.

Установка и штатные тесты из каталога пакета:

~~~sh
composer --no-plugins --no-scripts install --prefer-dist --no-interaction --no-progress
php vendor/bin/phpunit --configuration phpunit.xml
~~~

Зависимость GalaxyResult устанавливается по manifest constraint из стандартного Packagist.
Проверка автономного пакета не использует локальные подмены Result или синтетические версии.
Вывод и код завершения проверяются на уровне CLI-хоста.

## 12. Пример

Сохраните пример в корне пакета как example.php после установки зависимостей.

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

Результат demo --flag:

~~~json
{"label":"example","input":["demo","--flag"]}
~~~

list/help возвращают успешный каталог маршрутов; код завершения процесса — 0.
