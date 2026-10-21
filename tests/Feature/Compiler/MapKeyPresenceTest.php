<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;

it('distinguishes null map entries from missing keys through every has-key spelling', function (string $use, string $hasKey) {
    $source = str_replace(['USE', 'HAS_KEY'], [$use, $hasKey], <<<'SCSS'
    USE
    $settings: (class: null, nested: (class: null));
    .result {
      present: HAS_KEY($settings, class);
      named: HAS_KEY($map: $settings, $key: class);
      nested: HAS_KEY($settings, nested, class);
      missing: HAS_KEY($settings, missing);
      null-parent: HAS_KEY($settings, class, child);
    }
    SCSS);

    expect((new Compiler())->compileString($source))->toEqualCss(/** @lang text */ <<<'CSS'
    .result {
      present: true;
      named: true;
      nested: true;
      missing: false;
      null-parent: false;
    }
    CSS);
})->with([
    'module'   => ['@use "sass:map";', 'map.has-key'],
    'wildcard' => ['@use "sass:map" as *;', 'has-key'],
    'legacy'   => ['', 'map-has-key'],
]);

it('generates visibility classes without a property prefix when class is explicitly null', function () {
    $source = <<<'SCSS'
    @use 'sass:map';
    @mixin generate-utilities-loop($utilities) {
      @each $name, $utility in $utilities {
        $property: map.get($utility, property);
        $class: $property;
        @if map.has-key($utility, class) {
          $class: map.get($utility, class);
        }
        @if $class == null { $class: ""; }
        @each $key, $value in map.get($utility, values) {
          $selector: $key;
          @if $class != "" { $selector: "#{$class}-#{$key}"; }
          .#{$selector} { #{$property}: $value; }
        }
      }
    }
    @include generate-utilities-loop((
      visibility: (property: visibility, class: null, values: (visible: visible, invisible: hidden)),
      display: (property: display, values: (block: block)),
      custom: (property: display, class: custom, values: (block: block)),
      empty: (property: visibility, class: "", values: (shown: visible)),
    ));
    SCSS;

    expect((new Compiler())->compileString($source))->toEqualCss(/** @lang text */ <<<'CSS'
    .visible {
      visibility: visible;
    }

    .invisible {
      visibility: hidden;
    }

    .display-block {
      display: block;
    }

    .custom-block {
      display: block;
    }

    .shown {
      visibility: visible;
    }
    CSS);
});
