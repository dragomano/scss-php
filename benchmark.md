# Benchmark

This file contains a performance comparison of SCSS compilation runs across three PHP libraries:

- `bugo/scss-php` (current project) - Pure PHP compiler for SCSS/Sass, compatible with modern Dart Sass specification
- [bugo/sass-embedded-php](https://github.com/dragomano/sass-embedded-php) - A lightweight PHP bridge for native Dart Sass
- [scssphp/scssphp](https://github.com/scssphp/scssphp) - A well-known PHP library for SCSS/Sass compilation
- [shyim/sasso-ffi](https://github.com/shyim/php-sasso-ffi) - Pure-PHP FFI polyfill for ext-sasso

## Test Environment

- **SCSS code**: Randomly generated, contains 200 classes with 4 nesting levels, variables, mixins and loops
- **OS**: Linux 6.18.33.2-microsoft-standard-WSL2
- **PHP version**: 8.5.10
- **Testing method**: Compilation via `compileFile()` with execution time measurement

## Results

| Compiler | Time (sec) | CSS Size (KB) | Memory (MB) |
|------------|-------------|---------------|-------------|
| bugo/scss-php | 0.6502 | 397.68 | 26.79 |
| bugo/scss-php (with cache) | 0.0019 | 397.68 | 26.35 |
| bugo/sass-embedded-php (cli) | 0.1057 | 397.66 | 1.57 |
| bugo/sass-embedded-php | 0.0661 | 397.66 | 2.38 |
| scssphp/scssphp | 0.4249 | 318.38 | 33.98 |
| shyim/sasso-ffi | 0.0176 | 397.66 | 1.07 |
