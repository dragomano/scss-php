# Benchmark

This file contains a performance comparison of SCSS compilation runs across three PHP libraries:

- `bugo/scss-php` (current project) - Pure PHP compiler for SCSS/Sass, compatible with modern Dart Sass specification
- [bugo/sass-embedded-php](https://github.com/dragomano/sass-embedded-php) - A lightweight PHP bridge for native Dart Sass
- [scssphp/scssphp](https://github.com/scssphp/scssphp) - A well-known PHP library for SCSS/Sass compilation

## Test Environment

- **SCSS code**: Randomly generated, contains 200 classes with 4 nesting levels, variables, mixins and loops
- **OS**: Linux 6.18.33.2-microsoft-standard-WSL2
- **PHP version**: 8.5.10
- **Testing method**: Compilation via `compileFile()` with execution time measurement

## Results

| Compiler | Time (sec) | CSS Size (KB) | Memory (MB) |
|------------|-------------|---------------|-------------|
| bugo/scss-php | 0.5306 | 397.42 | 34.21 |
| bugo/scss-php + cache | 0.0017 | 397.42 | 26.42 |
| bugo/sass-embedded-php (cli) | 0.0977 | 397.66 | 1.88 |
| bugo/sass-embedded-php | 0.0601 | 397.66 | 1.98 |
| scssphp/scssphp | 0.3759 | 318.38 | 40.03 |
