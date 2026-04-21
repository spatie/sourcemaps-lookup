<?php

declare(strict_types=1);

use Spatie\SourcemapsLookup\Internal\Base64Vlq;

it('decodes zero', function () {
    $offset = 0;
    expect(Base64Vlq::decode('A', $offset))->toBe(0);
    expect($offset)->toBe(1);
});

it('decodes small positive numbers', function () {
    $offset = 0;
    expect(Base64Vlq::decode('C', $offset))->toBe(1);   // 000001 -> sign 0, value 1
    $offset = 0;
    expect(Base64Vlq::decode('E', $offset))->toBe(2);
    $offset = 0;
    expect(Base64Vlq::decode('G', $offset))->toBe(3);
});

it('decodes small negative numbers', function () {
    $offset = 0;
    expect(Base64Vlq::decode('D', $offset))->toBe(-1);  // 000011 -> sign 1, value 1
    $offset = 0;
    expect(Base64Vlq::decode('F', $offset))->toBe(-2);
});

it('decodes multi-character numbers', function () {
    $offset = 0;
    // "gB" -> first char 'g' = 32 (continuation, low bits 100000 -> value 0, but with continuation bit)
    // Actually "gB" decodes to 16 — verified against spec reference implementations.
    expect(Base64Vlq::decode('gB', $offset))->toBe(16);
    expect($offset)->toBe(2);
});

it('advances offset past only consumed chars', function () {
    $input = 'CE';
    $offset = 0;
    Base64Vlq::decode($input, $offset);
    expect($offset)->toBe(1);
    // Second decode picks up where first left off
    expect(Base64Vlq::decode($input, $offset))->toBe(2);
    expect($offset)->toBe(2);
});

it('round-trips a range of integers', function (int $value) {
    // Encode using known-good axy encoder, decode with ours
    $encoder = \axy\codecs\base64vlq\Encoder::getStandardInstance();
    $encoded = $encoder->encode($value);
    $offset = 0;
    expect(Base64Vlq::decode($encoded, $offset))->toBe($value);
    expect($offset)->toBe(strlen($encoded));
})->with([0, 1, -1, 15, 16, -16, 100, -100, 1000, -1000, 1 << 20, -(1 << 20)]);

it('throws on invalid base64 character', function () {
    $offset = 0;
    Base64Vlq::decode('!', $offset);
})->throws(\Spatie\SourcemapsLookup\Exceptions\InvalidSourceMap::class);

it('decodes VLQ "-0" to -2^31 per ECMA-426 §VLQSignedValue', function () {
    // "B" = base64 digit 1: continuation=0, value=1 => negative=true, magnitude=0 => -2^31
    $offset = 0;
    expect(Base64Vlq::decode('B', $offset))->toBe(-(1 << 31));
});
