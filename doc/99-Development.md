# Development

## PHP Lint

```bash
# Composer in a container, but can also be done without
podman run -ti --rm -v $(pwd):/app --entrypoint bash docker.io/composer:latest

# Install development dependency
composer install

# Run linter
make lint
```

## PHPBench

Install phpbench with composer and see Makefile for dependency setup.

```bash
# Downloads the Icinga Web dependencies
make setup

# Composer in a container, but can also be done without
podman run -ti --rm -v $(pwd):/app --entrypoint bash docker.io/composer:latest

# Install development dependency
composer install

# Run the benchmark
./vendor/bin/phpbench run test/benchmark --output=html --report=default

# To store and tag a benchmark
./vendor/bin/phpbench run test/benchmark --output=html --report=default --store --tag=main
```
