<?php

namespace Spatie\SourcemapsLookup\Internal;

use Spatie\SourcemapsLookup\Exceptions\InvalidSourceMap;

class Base64Vlq
{
    /** @var array<int,int> */
    private static array $table;

    /**
     * Decode one VLQ-encoded integer from $input starting at $offset.
     * Advances $offset past the consumed characters.
     *
     * @throws InvalidSourceMap on invalid characters or premature EOF
     */
    public static function decode(string $input, int &$offset): int
    {
        $table = self::$table ??= self::buildTable();

        $value = 0;
        $shift = 0;
        $len = strlen($input);

        while (true) {
            if ($offset >= $len) {
                throw new InvalidSourceMap('Unexpected end of VLQ sequence');
            }
            $digit = $table[ord($input[$offset])] ?? -1;
            if ($digit < 0) {
                throw new InvalidSourceMap("Invalid base64 character: '{$input[$offset]}'");
            }
            $offset++;
            $continuation = ($digit & 0x20) !== 0;
            $value |= ($digit & 0x1F) << $shift;
            $shift += 5;
            if (! $continuation) {
                break;
            }
        }

        $negative = ($value & 1) === 1;
        $value >>= 1;
        if ($negative && $value === 0) {
            return -(1 << 31);  // -2^31 per ECMA-426 spec
        }

        return $negative ? -$value : $value;
    }

    /** @return array<int,int> */
    private static function buildTable(): array
    {
        // 128-entry ord -> 6-bit base64 value (-1 = invalid)
        $table = array_fill(0, 128, -1);
        $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
        for ($i = 0; $i < 64; $i++) {
            $table[ord($alpha[$i])] = $i;
        }

        return $table;
    }
}
