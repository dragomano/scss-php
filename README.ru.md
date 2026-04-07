# Sass/SCSS PHP Compiler

![PHP](https://img.shields.io/badge/PHP-^8.2-blue.svg?style=flat)
[![Coverage Status](https://coveralls.io/repos/github/dragomano/scss-php/badge.svg?branch=main)](https://coveralls.io/github/dragomano/scss-php?branch=main)

[English](README.md)

## Особенности

- Компиляция Sass и SCSS в CSS
- Поддержка `@use`, `@forward`, `@import`, встроенных Sass-модулей и современных цветовых функций
- Опциональные source maps и разделение правил
- PSR-3 логирование для `@debug`, `@warn` и `@error`
- Поддержка PSR-16 для кэширования скомпилированных файлов

---

## Установка через Composer

```bash
composer require bugo/scss-php
```

## Примеры использования

### Компиляция строки

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Syntax;

$compiler = new Compiler();

// SCSS
$scss = <<<'SCSS'
@use 'sass:color';

$color: red;
body {
  color: $color;
}
footer {
  background: color.adjust(#6b717f, $red: 15);
}
SCSS;

$css = $compiler->compileString($scss);

var_dump($css);

// Sass
$sass = <<<'SASS'
@use 'sass:color';

$color: red;
body
  color: $color;
footer
  background: color.adjust(#6b717f, $red: 15);
SASS;

$css = $compiler->compileString($sass, Syntax::SASS);

var_dump($css);
```

### Компиляция файла

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Bugo\SCSS\Compiler;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Loader;
use Bugo\SCSS\Style;

$compiler = new Compiler(
    options: new CompilerOptions(style: Style::COMPRESSED, sourceMapFile: 'assets/app.css.map'),
    loader: new Loader(['styles/']),
);

$css = $compiler->compileFile(__DIR__ . '/assets/app.scss');

file_put_contents(__DIR__ . '/assets/app.css', $css);

echo "CSS скомпилирован!\n";
```

Если задан `sourceMapFile`, компилятор сам записывает source map и добавляет в возвращаемый CSS комментарий `sourceMappingURL`.

### Кэширование скомпилированных файлов через PSR-16

`CachingCompiler` кэширует только `compileFile()`. Он отслеживает все загруженные зависимости и инвалидирует кэш, если изменился любой файл, подключённый через `@use`, `@forward` или `@import`.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Bugo\SCSS\Cache\CachingCompiler;
use Bugo\SCSS\Cache\TrackingLoader;
use Bugo\SCSS\Compiler;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Loader;
use Bugo\SCSS\Style;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

$options = new CompilerOptions(
    style: Style::COMPRESSED,
    sourceMapFile: __DIR__ . '/assets/app.css.map',
);

$trackingLoader = new TrackingLoader(new Loader([__DIR__ . '/styles']));
$compiler = new Compiler($options, $trackingLoader);
$cache = new Psr16Cache(new FilesystemAdapter(namespace: 'scss', directory: __DIR__ . '/var/cache/scss'));

$cachedCompiler = new CachingCompiler(
    $compiler,
    $cache,
    $trackingLoader,
    $options,
);

$css = $cachedCompiler->compileFile(__DIR__ . '/assets/app.scss');

file_put_contents(__DIR__ . '/assets/app.css', $css);
```

Примечания:
- Один и тот же экземпляр `TrackingLoader` нужно передать и в `Compiler`, и в `CachingCompiler`.
- `compileString()` просто делегируется дальше и не кэшируется.
- `psr/simple-cache` уже входит в зависимости пакета, но нужна конкретная PSR-16 реализация, например `symfony/cache`, Laravel cache или другой совместимый backend.

### Параметры CompilerOptions

| Параметр          | Тип       | По умолчанию      | Описание                                                       |
|-------------------|-----------|-------------------|----------------------------------------------------------------|
| `style`           | `Style`   | `Style::EXPANDED` | Стиль вывода: `EXPANDED` или `COMPRESSED`                      |
| `sourceFile`      | `string`  | `'input.scss'`    | Имя исходного файла для source map                             |
| `outputFile`      | `string`  | `'output.css'`    | Имя выходного файла для source map                             |
| `sourceMapFile`   | `?string` | `null`            | Путь к файлу source map; `null` отключает генерацию source map |
| `includeSources`  | `bool`    | `false`           | Встроить исходный код в source map (`sourcesContent`)          |
| `outputHexColors` | `bool`    | `false`           | Нормализовать поддержанные функциональные цвета в hex          |
| `splitRules`      | `bool`    | `false`           | Разбить правила с несколькими селекторами на отдельные         |
| `verboseLogging`  | `bool`    | `false`           | Логировать все `@debug` (иначе только `@warn`/`@error`)        |

### Логирование `@debug`, `@warn`, `@error` через любой PSR-3 логгер

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Bugo\SCSS\Compiler;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$formatter = new LineFormatter("[%datetime%] %level_name%: %message%\n");

$handler = new StreamHandler('php://stdout');
$handler->setFormatter($formatter);

$logger = new Logger('sass');
$logger->pushHandler($handler);

// Передать логгер в конструктор
$compiler = new Compiler(logger: $logger);

$scss = <<<'SCSS'
@debug "Сборка началась";
@warn "Используется устаревший токен";
// @error "Критическая ошибка стилей";

.button {
  color: red;
}
SCSS;

$css = $compiler->compileString($scss);
echo $css;
```

Примечания:
- `@debug` -> `$logger->debug(...)`
- `@warn`  -> `$logger->warning(...)`
- `@error` -> `$logger->error(...)`, а компиляция выбрасывает `Bugo\SCSS\Exceptions\SassErrorException`

## Как это работает

```mermaid
flowchart TD
    A["Input\n(SCSS / Sass / CSS)"] --> B["Compiler"]
    B --> C["NormalizerPipeline"]
    C --> D["Tokenizer"]
    D --> E["TokenStream"]
    E --> F["Parser"]

    F --> F1["DirectiveParser\n@use, @import, @media..."]
    F --> F2["RuleParser\nselectors & blocks"]
    F --> F3["ValueParser\nexpressions & values"]

    F1 & F2 & F3 --> H["AST — RootNode tree"]

    H --> I["CompilerDispatcher\nVisitor pattern"]

    I --> J0["RootNodeHandler\nroot document"]
    I --> J1["BlockNodeHandler\nCSS rules & selectors"]
    I --> J2["DeclarationNodeHandler\nCSS properties"]
    I --> J3["AtRuleNodeHandler\n@media, @supports, @keyframes..."]
    I --> J4["FlowControlNodeHandler\n@if, @for, @each, @while"]
    I --> J5["ModuleNodeHandler\n@use, @forward, @import"]
    I --> J6["DefinitionNodeHandler\n$var, @function, @mixin"]
    I --> J7["DiagnosticNodeHandler\n@debug, @warn, @error"]
    I --> J8["CommentNodeHandler\nCSS comments"]

    J0 & J1 & J2 & J3 & J4 & J5 & J6 & J7 & J8 --> K["Services\nEvaluator · Selector · Text · Render"]

    K --> L["OutputOptimizer"]
    L --> M["OutputRenderer"]

    M --> N["CSS"]
    M --> O["Source Map (optional)"]
```

---

## Сравнение с другими пакетами

Смотрите файл [benchmark.md](benchmark.md) для просмотра результатов.

В `benchmark.php` есть и обычный `bugo/scss-php`, и отдельный сценарий `bugo/scss-php+cache`, чтобы можно было сравнить повторные вызовы `compileFile()` с тёплым кэшем.

## Нашли ошибку?

Вставьте проблемный код в [песочницу](https://sass-lang.com/playground/), затем пришлите:

- ссылку на sandbox
- фактический результат этого пакета
- ожидаемый результат

## Хотите что-то добавить?

Не забудьте протестировать и привести в порядок свой код, прежде чем отправлять пулреквест.

## Дополнительные ресурсы

* https://dragomano.github.io/dart-sass-docs-russian/
* https://github.com/sass/sass
* https://tc39.es/ecma426/
* https://evanw.github.io/source-map-visualization/
