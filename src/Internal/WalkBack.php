<?php

namespace Spatie\SourcemapsLookup\Internal;

/**
 * Heuristic polyfill that walks a JavaScript/TypeScript source backward to
 * recover the chain of enclosing scope names for a given source line.
 *
 * Used by `SourceMapLookup::scopeAt()`; when the ECMA-426 Scopes field starts
 * being emitted by bundlers this walker becomes one of two engines behind the
 * same public API.
 *
 * The per-line scanner skips `{`/`}` characters that appear inside string
 * literals (`"…"`, `'…'`, `` `…` ``), line comments (`// …`), and block
 * comments (`/* … *​/`). Template literal interpolation (`${…}`) is treated as
 * code. Multiline strings and block comments that span lines are treated
 * per-line — known limitation, accepted for Phase 1.
 */
class WalkBack
{
    // Function declaration: `function [*] <name> (`
    private const FUNCTION_DECLARATION_REGEX = '/\bfunction\s*\*?\s+([A-Za-z_$][\w$]*)\s*\(/';

    // `const/let/var <name> = (async? )?(function | (…) => | x =>)`
    private const BINDING_REGEX = '/\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s+)?(?:function\b|\([^)]*\)\s*=>|[A-Za-z_$][\w$]*\s*=>)/';

    // Shorthand method (class or object body): `[modifiers] <name>(…) {`.
    // The negative lookahead rejects control-flow keywords that share the same
    // surface shape (`if (…) {`, `while (…) {`, etc.) and would otherwise be
    // captured as if they were method names.
    private const METHOD_REGEX = '/^\s*(?:async\s+|static\s+|get\s+|set\s+|#)*(?!(?:if|while|for|switch|catch)\b)([A-Za-z_$][\w$]*)\s*\([^)]*\)\s*\{/';

    // Anonymous function boundary: `=> {` or `function ( … ) {`
    private const ANONYMOUS_REGEX = '/(?:=>\s*\{|\bfunction\s*\*?\s*\([^)]*\)\s*\{)/';

    // States for the per-line brace scanner.
    private const STATE_CODE = 0;

    private const STATE_MULTILINE_COMMENT = 1;   // `/* ... */`

    private const STATE_DOUBLE_QUOTE = 2;        // "..."

    private const STATE_SINGLE_QUOTE = 3;        // '...'

    private const STATE_TEMPLATE = 4;            // `...`

    /**
     * Walk backward from `$sourceLine` looking for enclosing function-like
     * scopes. Returns the chain innermost-first.
     *
     * @param  list<string>  $lines  Source file split on "\n", 0-indexed.
     * @param  int  $sourceLine  1-based source line containing the queried position.
     * @param  int  $maxLinesBack  Upper bound on how far back we scan.
     * @return list<array{name: ?string, line: int, column: int}>
     */
    public static function find(array $lines, int $sourceLine, int $maxLinesBack): array
    {
        $chain = [];
        $depth = 0;

        // Walk from the line above $sourceLine down to the floor.
        $startIndex = $sourceLine - 2; // 0-based index of the line above the throw
        if ($startIndex < 0) {
            return [];
        }
        $floor = max(0, $startIndex - $maxLinesBack + 1);

        for ($i = $startIndex; $i >= $floor; $i--) {
            $line = $lines[$i] ?? '';
            [$opens, $closes, $firstOpenPosition] = self::countBraces($line);
            $depth += $closes - $opens;

            if ($depth < 0) {
                $entry = self::matchDeclaration($line, $i + 1, $firstOpenPosition);
                if ($entry !== null) {
                    $chain[] = $entry;
                }
                $depth = 0;
            }
        }

        return $chain;
    }

    /**
     * Count `{` and `}` in code regions of a single line, skipping string
     * literals and comments. Template interpolation `${…}` is code.
     *
     * @return array{0: int, 1: int, 2: ?int} opens, closes, column of the first open brace
     */
    private static function countBraces(string $line): array
    {
        $opens = 0;
        $closes = 0;
        $firstOpenPosition = null;
        $length = strlen($line);
        $state = self::STATE_CODE;
        $templateBraceStack = []; // depth of nested {} inside the current ${ … }

        $index = 0;
        while ($index < $length) {
            $character = $line[$index];
            $next = $index + 1 < $length ? $line[$index + 1] : '';

            switch ($state) {
                case self::STATE_CODE:
                    if ($character === '/' && $next === '/') {
                        return [$opens, $closes, $firstOpenPosition];
                    }
                    if ($character === '/' && $next === '*') {
                        $state = self::STATE_MULTILINE_COMMENT;
                        $index += 2;

                        continue 2;
                    }
                    if ($character === '"') {
                        $state = self::STATE_DOUBLE_QUOTE;
                        $index++;

                        continue 2;
                    }
                    if ($character === "'") {
                        $state = self::STATE_SINGLE_QUOTE;
                        $index++;

                        continue 2;
                    }
                    if ($character === '`') {
                        $state = self::STATE_TEMPLATE;
                        $index++;

                        continue 2;
                    }
                    if ($character === '{') {
                        $opens++;
                        if ($firstOpenPosition === null) {
                            $firstOpenPosition = $index;
                        }
                        if (! empty($templateBraceStack)) {
                            $templateBraceStack[count($templateBraceStack) - 1]++;
                        }
                        $index++;

                        continue 2;
                    }
                    if ($character === '}') {
                        if (! empty($templateBraceStack)) {
                            $top = count($templateBraceStack) - 1;
                            if ($templateBraceStack[$top] > 0) {
                                $templateBraceStack[$top]--;
                                $closes++;
                            } else {
                                array_pop($templateBraceStack);
                                $state = self::STATE_TEMPLATE;
                            }
                        } else {
                            $closes++;
                        }
                        $index++;

                        continue 2;
                    }
                    $index++;
                    break;

                case self::STATE_MULTILINE_COMMENT:
                    if ($character === '*' && $next === '/') {
                        $state = self::STATE_CODE;
                        $index += 2;

                        continue 2;
                    }
                    $index++;
                    break;

                case self::STATE_DOUBLE_QUOTE:
                    if ($character === '\\') {
                        $index += 2;

                        continue 2;
                    }
                    if ($character === '"') {
                        $state = self::STATE_CODE;
                        $index++;

                        continue 2;
                    }
                    $index++;
                    break;

                case self::STATE_SINGLE_QUOTE:
                    if ($character === '\\') {
                        $index += 2;

                        continue 2;
                    }
                    if ($character === "'") {
                        $state = self::STATE_CODE;
                        $index++;

                        continue 2;
                    }
                    $index++;
                    break;

                case self::STATE_TEMPLATE:
                    if ($character === '\\') {
                        $index += 2;

                        continue 2;
                    }
                    if ($character === '`') {
                        $state = self::STATE_CODE;
                        $index++;

                        continue 2;
                    }
                    if ($character === '$' && $next === '{') {
                        $state = self::STATE_CODE;
                        $templateBraceStack[] = 0;
                        $index += 2;

                        continue 2;
                    }
                    $index++;
                    break;
            }
        }

        return [$opens, $closes, $firstOpenPosition];
    }

    /**
     * @return array{name: ?string, line: int, column: int}|null
     */
    private static function matchDeclaration(string $line, int $lineNumber, ?int $openPosition): ?array
    {
        if (preg_match(self::FUNCTION_DECLARATION_REGEX, $line, $matches, PREG_OFFSET_CAPTURE)) {
            return ['name' => $matches[1][0], 'line' => $lineNumber, 'column' => $matches[1][1]];
        }
        if (preg_match(self::BINDING_REGEX, $line, $matches, PREG_OFFSET_CAPTURE)) {
            return ['name' => $matches[1][0], 'line' => $lineNumber, 'column' => $matches[1][1]];
        }
        // ANONYMOUS is tried before METHOD because a method-decl-shaped regex
        // would otherwise greedily match call-site lines like
        // `setTimeout(function(){`, capturing `setTimeout` as if it were a
        // method name.
        if (preg_match(self::ANONYMOUS_REGEX, $line)) {
            return ['name' => null, 'line' => $lineNumber, 'column' => $openPosition ?? 0];
        }
        if (preg_match(self::METHOD_REGEX, $line, $matches, PREG_OFFSET_CAPTURE)) {
            return ['name' => $matches[1][0], 'line' => $lineNumber, 'column' => $matches[1][1]];
        }

        return null;
    }
}
