# Benchmark

This file contains a performance comparison of SCSS compilation runs across three PHP libraries:

- `bugo/scss-php` (current project) - Pure PHP compiler for SCSS/Sass, compatible with modern Dart Sass specification
- [bugo/sass-embedded-php](https://github.com/dragomano/sass-embedded-php) - PHP wrapper for the `sass-embedded` (npm package) using a bridge between PHP and Node.js
- [scssphp/scssphp](https://github.com/scssphp/scssphp) - A well-known PHP library for SCSS/Sass compilation

## Test Environment

- **SCSS code**: Randomly generated, contains 200 classes with 4 nesting levels, variables, mixins and loops
- **OS**: Linux 6.6.87.2-microsoft-standard-WSL2
- **PHP version**: 8.5.5
- **Testing method**: Compilation via `compileString()` with execution time measurement

## Results

| Compiler | Time (sec) | CSS Size (KB) | Memory (MB) |
|------------|-------------|---------------|-------------|
| bugo/scss-php | 0.4199 | 314.84 | 0.31 |
| bugo/scss-php+cache | 0.0034 | 314.84 | 0.00 |
| bugo/sass-embedded-php | 0.1433 | 318.20 | 0.65 |
| scssphp/scssphp | 0.3577 | 318.38 | 10.48 |
