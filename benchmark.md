# Benchmark

This file contains a performance comparison of SCSS compilation runs across three PHP libraries:

- `bugo/scss-php` (current project) - Pure PHP compiler for SCSS/Sass, compatible with modern Dart Sass specification
- [bugo/sass-embedded-php](https://github.com/dragomano/sass-embedded-php) - PHP wrapper for the `sass-embedded` (npm package) using a bridge between PHP and Node.js
- [scssphp/scssphp](https://github.com/scssphp/scssphp) - A well-known PHP library for SCSS/Sass compilation

## Test Environment

- **SCSS code**: Randomly generated, contains 200 classes with 4 nesting levels, variables, mixins and loops
- **OS**: Windows 11 25H2 (Build 10.0.26200.8039)
- **PHP version**: 8.5.4
- **Testing method**: Compilation via `compileString()` with execution time measurement

## Results

| Compiler | Time (sec) | CSS Size (KB) | Memory (MB) |
|------------|-------------|---------------|-------------|
| bugo/scss-php | 0.6166 | 314.95 | 0.31 |
| bugo/scss-php+cache | 0.0004 | 314.95 | 0.00 |
| bugo/sass-embedded-php | 0.1643 | 318.07 | 0.32 |
| scssphp/scssphp | 0.6122 | 318.39 | 0.69 |
