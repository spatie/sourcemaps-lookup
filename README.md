# Fast and memory-efficient JavaScript source map lookup for PHP

[![Latest Version on Packagist](https://img.shields.io/packagist/v/spatie/sourcemaps-lookup.svg?style=flat-square)](https://packagist.org/packages/spatie/sourcemaps-lookup)
[![Tests](https://img.shields.io/github/actions/workflow/status/spatie/sourcemaps-lookup/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/spatie/sourcemaps-lookup/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/spatie/sourcemaps-lookup.svg?style=flat-square)](https://packagist.org/packages/spatie/sourcemaps-lookup)

`spatie/sourcemaps-lookup` resolves JavaScript stack frame positions against a [Source Map v3](https://tc39.es/ecma426/) file. It returns the original source file, line, column, symbol name, and enclosing scope. The package is tuned for stack frame resolution (for example, symbolicating JavaScript errors using an uploaded source map), and is narrowly focused on the read path. It does not write, merge, or transform maps.

```php
use Spatie\SourcemapsLookup\SourceMapLookup;

$map = SourceMapLookup::fromFile('bundle.js.map');

// Resolve a generated position to its original source location.
$position = $map->lookup(42, 17);
echo $position->sourceFileName;  // "src/app.tsx"
echo $position->sourceLine;      // 1-based
echo $position->sourceColumn;    // 0-based

// Resolve the enclosing source-language scope.
$scope = $map->scopeAt(42, 17);
echo $scope->name;               // "onClick"
echo $scope->parent?->name;      // "DeeplyNestedTrigger"
```

Resolving 20 stack frames against a 6 MB production source map takes around 3.8 ms and uses about 18 MiB of memory on an Apple M1 Pro. See the [benchmarks page](https://spatie.be/docs/sourcemaps-lookup/v1/benchmarks) for the full picture.

## Support us

[<img src="https://github-ads.s3.eu-central-1.amazonaws.com/sourcemaps-lookup.jpg?t=2" width="419px" />](https://spatie.be/github-ad-click/sourcemaps-lookup)

We invest a lot of resources into creating [best in class open source packages](https://spatie.be/open-source). You can support us by [buying one of our paid products](https://spatie.be/open-source/support-us).

We highly appreciate you sending us a postcard from your hometown, mentioning which of our package(s) you are using. You'll find our address on [our contact page](https://spatie.be/about-us). We publish all received postcards on [our virtual postcard wall](https://spatie.be/open-source/postcards).

## Documentation

You'll find the full documentation on [our website](https://spatie.be/docs/sourcemaps-lookup).

## Installation

You can install the package via Composer:

```bash
composer require spatie/sourcemaps-lookup
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](https://github.com/spatie/.github/blob/main/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Alex Vanderbist](https://github.com/AlexVanderbist)
- [All Contributors](../../contributors)

Correctness test fixtures in `tests/fixtures/axy/` are copied verbatim from [`axy/sourcemap`](https://github.com/axypro/sourcemap) under its MIT licence, and used as reference data for spec conformance.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
