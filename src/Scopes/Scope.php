<?php

namespace Spatie\SourcemapsLookup\Scopes;

use Spatie\SourcemapsLookup\Position;

/**
 * A source-language scope resolved from a generated position.
 *
 * Modeled after `OriginalScope` in the ECMA-426 Scopes proposal. Today this
 * is populated via a heuristic polyfill (see `SourceMapLookup::scopeAt()`);
 * when bundlers begin emitting the `scopes` field we'll populate it natively
 * from that data instead.
 *
 * `$parent` is the lexically enclosing scope: for an arrow function bound
 * inside a React component, the innermost `Scope` is the arrow and its
 * `$parent` is the component function.
 *
 * `$position` is the original position that's meaningful for this scope:
 *   - for the innermost scope, the source position the lookup resolved to
 *     (i.e. where the error was thrown);
 *   - for enclosing scopes, the source position of the declaration that was
 *     matched by walk-back.
 */
readonly class Scope
{
    public function __construct(
        public ?string $name,
        public Position $position,
        public ?Scope $parent = null,
    ) {}
}
