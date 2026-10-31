<?php

namespace App\Services\WebAuthn;

use RuntimeException;

/**
 * The small part of CBOR (RFC 8949) that WebAuthn uses: unsigned / negative integers, byte and
 * text strings, arrays, maps, and simple true / false / null. Byte strings are returned as
 * strings; `decode` also reports how many bytes the item used (authenticator data embeds a key).
 */
final class Cbor
{
    public static function decode(string $data, int &$offset = 0): mixed
    {
        if ($offset >= strlen($data)) {
            throw new RuntimeException('CBOR: unexpected end.');
        }
        $initial = ord($data[$offset++]);
        $major = $initial >> 5;
        $info = $initial & 0x1F;
        if ($major === 7) {
            return match ($info) {
                20 => false, 21 => true, 22, 23 => null,
                default => throw new RuntimeException('CBOR: unsupported simple value.'),
            };
        }
        $length = self::length($data, $offset, $info);

        switch ($major) {
            case 0:
                return $length;
            case 1:
                return -1 - $length;
            case 2:
            case 3:
                if ($offset + $length > strlen($data)) {
                    throw new RuntimeException('CBOR: string past the end.');
                }
                $value = substr($data, $offset, $length);
                $offset += $length;

                return $value;
            case 4:
                $items = [];
                for ($i = 0; $i < $length; $i++) {
                    $items[] = self::decode($data, $offset);
                }

                return $items;
            case 5:
                $map = [];
                for ($i = 0; $i < $length; $i++) {
                    $key = self::decode($data, $offset);
                    if (! is_int($key) && ! is_string($key)) {
                        throw new RuntimeException('CBOR: unsupported map key.');
                    }
                    $map[$key] = self::decode($data, $offset);
                }

                return $map;
            default:
                throw new RuntimeException('CBOR: unsupported type.');
        }
    }

    private static function length(string $data, int &$offset, int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        $bytes = match ($info) {
            24 => 1, 25 => 2, 26 => 4, 27 => 8,
            default => throw new RuntimeException('CBOR: indefinite lengths are not supported.'),
        };
        if ($offset + $bytes > strlen($data)) {
            throw new RuntimeException('CBOR: length past the end.');
        }
        $value = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $value = ($value << 8) | ord($data[$offset + $i]);
        }
        $offset += $bytes;
        if ($value < 0 || $value > 0x7FFFFFFF) {
            throw new RuntimeException('CBOR: length too large.');
        }

        return $value;
    }
}
