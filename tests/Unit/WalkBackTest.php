<?php

declare(strict_types=1);

use Spatie\SourcemapsLookup\Internal\WalkBack;

/**
 * Helper: split a multiline string into the 0-indexed lines array WalkBack
 * expects. Keeps test bodies readable.
 */
function wbLines(string $source): array
{
    return explode("\n", $source);
}

it('returns an empty chain when no enclosing function is found', function () {
    $lines = wbLines("const x = 1;\nthrow new Error();");
    expect(WalkBack::find($lines, 2, 60))->toBe([]);
});

it('finds a plain function declaration', function () {
    $source = <<<'JS'
function foo() {
    throw new Error('boom');
}
JS;
    $chain = WalkBack::find(wbLines($source), 2, 60);
    expect($chain)->toHaveCount(1);
    expect($chain[0]['name'])->toBe('foo');
    expect($chain[0]['line'])->toBe(1);
});

it('finds a const arrow function binding', function () {
    $source = <<<'JS'
const bar = () => {
    throw new Error('boom');
};
JS;
    $chain = WalkBack::find(wbLines($source), 2, 60);
    expect($chain)->toHaveCount(1);
    expect($chain[0]['name'])->toBe('bar');
});

it('finds a const async function expression', function () {
    $source = <<<'JS'
const baz = async function () {
    throw new Error('boom');
};
JS;
    $chain = WalkBack::find(wbLines($source), 2, 60);
    expect($chain)->toHaveCount(1);
    expect($chain[0]['name'])->toBe('baz');
});

it('finds a let async arrow', function () {
    $source = <<<'JS'
let qux = async () => {
    throw new Error('boom');
};
JS;
    $chain = WalkBack::find(wbLines($source), 2, 60);
    expect($chain[0]['name'])->toBe('qux');
});

it('finds a class method', function () {
    $source = <<<'JS'
class Foo {
    render() {
        throw new Error('boom');
    }
}
JS;
    $chain = WalkBack::find(wbLines($source), 3, 60);
    expect($chain)->toHaveCount(1);
    expect($chain[0]['name'])->toBe('render');
});

it('finds an async class method', function () {
    $source = <<<'JS'
class Foo {
    async save() {
        throw new Error('boom');
    }
}
JS;
    $chain = WalkBack::find(wbLines($source), 3, 60);
    expect($chain[0]['name'])->toBe('save');
});

it('returns the enclosing chain innermost first', function () {
    $source = <<<'JS'
function Outer() {
    const onClick = () => {
        throw new Error('boom');
    };
}
JS;
    $chain = WalkBack::find(wbLines($source), 3, 60);
    expect($chain)->toHaveCount(2);
    expect($chain[0]['name'])->toBe('onClick');
    expect($chain[1]['name'])->toBe('Outer');
});

it('records the declaration line for each scope in the chain', function () {
    $source = <<<'JS'
function Outer() {
    const onClick = () => {
        throw new Error('boom');
    };
}
JS;
    $chain = WalkBack::find(wbLines($source), 3, 60);
    expect($chain[0]['line'])->toBe(2); // const onClick = () =>
    expect($chain[1]['line'])->toBe(1); // function Outer()
});

it('emits a nameless entry for an anonymous arrow callback', function () {
    $source = <<<'JS'
[1, 2, 3].map(() => {
    throw new Error('boom');
});
JS;
    $chain = WalkBack::find(wbLines($source), 2, 60);
    expect($chain)->toHaveCount(1);
    expect($chain[0]['name'])->toBeNull();
});

it('emits a nameless entry for an anonymous function expression callback', function () {
    $source = <<<'JS'
setTimeout(function () {
    throw new Error('boom');
}, 0);
JS;
    $chain = WalkBack::find(wbLines($source), 2, 60);
    expect($chain)->toHaveCount(1);
    expect($chain[0]['name'])->toBeNull();
});

it('ignores braces inside double-quoted strings', function () {
    $source = <<<'JS'
function safe() {
    const s = "}{";
    throw new Error('boom');
}
JS;
    $chain = WalkBack::find(wbLines($source), 3, 60);
    expect($chain[0]['name'])->toBe('safe');
});

it('ignores braces inside single-quoted strings', function () {
    $source = <<<'JS'
function safe() {
    const s = '}';
    throw new Error('boom');
}
JS;
    $chain = WalkBack::find(wbLines($source), 3, 60);
    expect($chain[0]['name'])->toBe('safe');
});

it('ignores braces inside single-line comments', function () {
    $source = <<<'JS'
function safe() {
    // this comment has a } and a {
    throw new Error('boom');
}
JS;
    $chain = WalkBack::find(wbLines($source), 3, 60);
    expect($chain[0]['name'])->toBe('safe');
});

it('ignores braces inside block comments', function () {
    $source = <<<'JS'
function safe() {
    /* hi } { there */
    throw new Error('boom');
}
JS;
    $chain = WalkBack::find(wbLines($source), 3, 60);
    expect($chain[0]['name'])->toBe('safe');
});

it('ignores braces inside template literals', function () {
    $source = <<<'JS'
function safe() {
    const s = `}`;
    throw new Error('boom');
}
JS;
    $chain = WalkBack::find(wbLines($source), 3, 60);
    expect($chain[0]['name'])->toBe('safe');
});

it('counts template literal interpolation as code', function () {
    // The `${...}` braces participate in normal code depth tracking; only the
    // literal-text braces are excluded. A throw inside the interpolation is
    // still inside the enclosing function.
    $source = <<<'JS'
function outer() {
    const s = `${computed()}`;
    throw new Error('boom');
}
JS;
    $chain = WalkBack::find(wbLines($source), 3, 60);
    expect($chain[0]['name'])->toBe('outer');
});

it('respects escape sequences inside strings', function () {
    $source = <<<'JS'
function safe() {
    const s = "\"}";
    throw new Error('boom');
}
JS;
    $chain = WalkBack::find(wbLines($source), 3, 60);
    expect($chain[0]['name'])->toBe('safe');
});

it('returns an empty chain when the declaration is beyond maxLinesBack', function () {
    // function decl on line 1, then 200 filler lines, then throw. Default 60 can't reach.
    $source = "function deep() {\n".str_repeat("    // filler\n", 200)."    throw new Error();\n}\n";
    $chain = WalkBack::find(wbLines($source), 202, 60);
    expect($chain)->toBe([]);
});

it('finds the declaration when maxLinesBack is raised past it', function () {
    $source = "function deep() {\n".str_repeat("    // filler\n", 200)."    throw new Error();\n}\n";
    $chain = WalkBack::find(wbLines($source), 202, 300);
    expect($chain)->toHaveCount(1);
    expect($chain[0]['name'])->toBe('deep');
});

it('handles the Flare playground pattern: const handler inside a component', function () {
    $source = <<<'JS'
function DeeplyNestedTrigger() {
    const onClick = () => {
        secondLevelCompute({ user: 'alex' });
    };
    return onClick;
}
JS;
    $chain = WalkBack::find(wbLines($source), 3, 60);
    expect($chain)->toHaveCount(2);
    expect($chain[0]['name'])->toBe('onClick');
    expect($chain[1]['name'])->toBe('DeeplyNestedTrigger');
});

it('accepts a line at the very start of the file', function () {
    $source = "throw new Error();";
    expect(WalkBack::find(wbLines($source), 1, 60))->toBe([]);
});

it('accepts an empty lines array', function () {
    expect(WalkBack::find([], 1, 60))->toBe([]);
});

it('does not mistake control-flow keywords for method declarations', function () {
    // Regression: an `if (…) {` line looks superficially like a method shorthand
    // and was being captured as a "method named `if`" before the keyword filter.
    $source = <<<'JS'
function Outer() {
    if (cond) {
        throw new Error('boom');
    }
}
JS;
    $chain = WalkBack::find(wbLines($source), 3, 60);
    expect($chain)->toHaveCount(1);
    expect($chain[0]['name'])->toBe('Outer');
});

it('skips while, for, switch, and catch the same way', function () {
    foreach (['while', 'for', 'switch', 'catch'] as $keyword) {
        $source = "function Outer() {\n    $keyword (cond) {\n        throw new Error();\n    }\n}\n";
        $chain = WalkBack::find(wbLines($source), 3, 60);
        expect($chain)->toHaveCount(1)
            ->and($chain[0]['name'])->toBe('Outer', "on keyword $keyword");
    }
});
