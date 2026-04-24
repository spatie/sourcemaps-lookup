<?php

declare(strict_types=1);

uses()->in('Unit', 'Feature');

dataset('drivers', function () {
    yield 'php' => fn () => new \Spatie\SourcemapsLookup\Drivers\PhpParserDriver();

    $rustClass = '\\Spatie\\SourcemapsLookupRust\\RustParserDriver';
    if (class_exists($rustClass)
        && method_exists($rustClass, 'isAvailable')
        && $rustClass::isAvailable()) {
        yield 'rust' => fn () => new $rustClass();
    }
});
