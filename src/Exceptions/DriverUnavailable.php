<?php

namespace Spatie\SourcemapsLookup\Exceptions;

/**
 * Thrown when a driver is explicitly requested but cannot initialize
 * (e.g. RustParserDriver with ext-ffi disabled, or prebuilt .dylib missing).
 *
 * Auto-detect never throws this; it falls back to PhpParserDriver silently.
 */
class DriverUnavailable extends \RuntimeException {}
