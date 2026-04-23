---
title: Complete example
weight: 4
---

Here's an end to end example that resolves a single stack frame and prints the surrounding code.

```php
use Spatie\SourcemapsLookup\SourceMapLookup;

$map = SourceMapLookup::fromFile('bundle.js.map');

// Say we got this from a JavaScript error:
//   at bundle.js:42:17
$position = $map->lookup(42, 17);

if ($position === null) {
    echo "No original source for bundle.js:42:17\n";
    return;
}

echo "Original: {$position->sourceFileName}:{$position->sourceLine}:{$position->sourceColumn}\n";

if ($position->name !== null) {
    echo "In function: {$position->name}\n";
}

// Show the surrounding code, if the map has inlined source content.
$content = $map->sourceContent($position->sourceFileIndex);
if ($content !== null) {
    $lines = explode("\n", $content);
    $start = max(0, $position->sourceLine - 6);
    $end = min(count($lines) - 1, $position->sourceLine + 4);

    for ($i = $start; $i <= $end; $i++) {
        $marker = ($i + 1 === $position->sourceLine) ? '>' : ' ';
        echo sprintf("%s %4d | %s\n", $marker, $i + 1, $lines[$i]);
    }
}
```
