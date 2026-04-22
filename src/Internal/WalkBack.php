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
    private const FUNC_DECL_RE = '/\bfunction\s*\*?\s+([A-Za-z_$][\w$]*)\s*\(/';

    // `const/let/var <name> = (async? )?(function | (…) => | x =>)`
    private const BIND_RE = '/\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s+)?(?:function\b|\([^)]*\)\s*=>|[A-Za-z_$][\w$]*\s*=>)/';

    // Shorthand method (class or object body): `[modifiers] <name>(…) {`.
    // The negative lookahead rejects control-flow keywords that share the same
    // surface shape (`if (…) {`, `while (…) {`, etc.) and would otherwise be
    // captured as if they were method names.
    private const METHOD_RE = '/^\s*(?:async\s+|static\s+|get\s+|set\s+|#)*(?!(?:if|while|for|switch|catch)\b)([A-Za-z_$][\w$]*)\s*\([^)]*\)\s*\{/';

    // Anonymous function boundary: `=> {` or `function ( … ) {`
    private const ANON_RE = '/(?:=>\s*\{|\bfunction\s*\*?\s*\([^)]*\)\s*\{)/';

    // States for the per-line brace scanner.
    private const STATE_CODE = 0;
    private const STATE_MLC = 1;   // `/* ... */`
    private const STATE_DQ = 2;    // "..."
    private const STATE_SQ = 3;    // '...'
    private const STATE_TPL = 4;   // `...`

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
        $startIdx = $sourceLine - 2; // 0-based index of the line above the throw
        if ($startIdx < 0) {
            return [];
        }
        $floor = max(0, $startIdx - $maxLinesBack + 1);

        for ($i = $startIdx; $i >= $floor; $i--) {
            $line = $lines[$i] ?? '';
            [$opens, $closes, $firstOpenPos] = self::countBraces($line);
            $depth += $closes - $opens;

            if ($depth < 0) {
                $entry = self::matchDeclaration($line, $i + 1, $firstOpenPos);
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
        $firstOpenPos = null;
        $len = strlen($line);
        $state = self::STATE_CODE;
        $templateBraceStack = []; // depth of nested {} inside the current ${ … }

        $k = 0;
        while ($k < $len) {
            $ch = $line[$k];
            $next = $k + 1 < $len ? $line[$k + 1] : '';

            switch ($state) {
                case self::STATE_CODE:
                    if ($ch === '/' && $next === '/') {
                        return [$opens, $closes, $firstOpenPos];
                    }
                    if ($ch === '/' && $next === '*') {
                        $state = self::STATE_MLC;
                        $k += 2;

                        continue 2;
                    }
                    if ($ch === '"') {
                        $state = self::STATE_DQ;
                        $k++;

                        continue 2;
                    }
                    if ($ch === "'") {
                        $state = self::STATE_SQ;
                        $k++;

                        continue 2;
                    }
                    if ($ch === '`') {
                        $state = self::STATE_TPL;
                        $k++;

                        continue 2;
                    }
                    if ($ch === '{') {
                        $opens++;
                        if ($firstOpenPos === null) {
                            $firstOpenPos = $k;
                        }
                        if (! empty($templateBraceStack)) {
                            $templateBraceStack[count($templateBraceStack) - 1]++;
                        }
                        $k++;

                        continue 2;
                    }
                    if ($ch === '}') {
                        if (! empty($templateBraceStack)) {
                            $top = count($templateBraceStack) - 1;
                            if ($templateBraceStack[$top] > 0) {
                                $templateBraceStack[$top]--;
                                $closes++;
                            } else {
                                array_pop($templateBraceStack);
                                $state = self::STATE_TPL;
                            }
                        } else {
                            $closes++;
                        }
                        $k++;

                        continue 2;
                    }
                    $k++;
                    break;

                case self::STATE_MLC:
                    if ($ch === '*' && $next === '/') {
                        $state = self::STATE_CODE;
                        $k += 2;

                        continue 2;
                    }
                    $k++;
                    break;

                case self::STATE_DQ:
                    if ($ch === '\\') {
                        $k += 2;

                        continue 2;
                    }
                    if ($ch === '"') {
                        $state = self::STATE_CODE;
                        $k++;

                        continue 2;
                    }
                    $k++;
                    break;

                case self::STATE_SQ:
                    if ($ch === '\\') {
                        $k += 2;

                        continue 2;
                    }
                    if ($ch === "'") {
                        $state = self::STATE_CODE;
                        $k++;

                        continue 2;
                    }
                    $k++;
                    break;

                case self::STATE_TPL:
                    if ($ch === '\\') {
                        $k += 2;

                        continue 2;
                    }
                    if ($ch === '`') {
                        $state = self::STATE_CODE;
                        $k++;

                        continue 2;
                    }
                    if ($ch === '$' && $next === '{') {
                        $state = self::STATE_CODE;
                        $templateBraceStack[] = 0;
                        $k += 2;

                        continue 2;
                    }
                    $k++;
                    break;
            }
        }

        return [$opens, $closes, $firstOpenPos];
    }

    /**
     * @return array{name: ?string, line: int, column: int}|null
     */
    private static function matchDeclaration(string $line, int $lineNumber, ?int $openPos): ?array
    {
        if (preg_match(self::FUNC_DECL_RE, $line, $m, PREG_OFFSET_CAPTURE)) {
            return ['name' => $m[1][0], 'line' => $lineNumber, 'column' => $m[1][1]];
        }
        if (preg_match(self::BIND_RE, $line, $m, PREG_OFFSET_CAPTURE)) {
            return ['name' => $m[1][0], 'line' => $lineNumber, 'column' => $m[1][1]];
        }
        // ANON is tried before METHOD because a method-decl-shaped regex would
        // otherwise greedily match call-site lines like `setTimeout(function(){`,
        // capturing `setTimeout` as if it were a method name.
        if (preg_match(self::ANON_RE, $line)) {
            return ['name' => null, 'line' => $lineNumber, 'column' => $openPos ?? 0];
        }
        if (preg_match(self::METHOD_RE, $line, $m, PREG_OFFSET_CAPTURE)) {
            return ['name' => $m[1][0], 'line' => $lineNumber, 'column' => $m[1][1]];
        }

        return null;
    }
}
