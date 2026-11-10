<?php

declare(strict_types=1);

namespace Charm\Jsonl\Tests;

use Charm\Jsonl\Decoder;
use Charm\Jsonl\ParseException;

/** Test helpers: a naive reference decoder and ways to feed a Decoder in pieces. */
final class Support
{
    /**
     * The definition of the format in the plainest possible code: strip the BOM, split on "\n", strip a "\r"
     * before each "\n", skip empty lines, json_decode() every other line on its own.
     *
     * @return array{0: list<mixed>}|array{0: 'error', 1: int, 2: int} the values, or ['error', line, JsonException code]
     */
    public static function reference(string $input, bool $assoc = true, int $depth = 512, int $flags = 0): array
    {
        if (str_starts_with($input, "\xEF\xBB\xBF")) {
            $input = substr($input, 3);
        }
        $lines = explode("\n", $input);
        $last = count($lines) - 1;
        $values = [];
        foreach ($lines as $i => $line) {
            if ($i < $last && str_ends_with($line, "\r")) {
                $line = substr($line, 0, -1);
            }
            if ($line === '') {
                continue;
            }
            try {
                $values[] = json_decode($line, $assoc, $depth, $flags | JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return ['error', $i + 1, $e->getCode()];
            }
        }
        return [$values];
    }

    /**
     * Decode with the given cut points (strictly increasing byte offsets).
     *
     * @return array{0: list<mixed>}|array{0: 'error', 1: int, 2: int} same shape as reference()
     */
    public static function decode(string $input, array $cuts = [], bool $assoc = true, int $depth = 512, int $flags = 0): array
    {
        $d = new Decoder($assoc, $depth, $flags);
        $values = [];
        $prev = 0;
        try {
            foreach ([...$cuts, strlen($input)] as $cut) {
                array_push($values, ...$d->decode(substr($input, $prev, $cut - $prev)));
                $prev = $cut;
            }
            array_push($values, ...$d->finish());
        } catch (ParseException $e) {
            return ['error', $e->lineNumber, $e->getPrevious()->getCode()];
        }
        return [$values];
    }

    /** Random cut points, at least one byte apart. */
    public static function randomCuts(int $length, int $maxStep): array
    {
        $cuts = [];
        for ($p = mt_rand(0, $maxStep); $p < $length; $p += mt_rand(1, $maxStep)) {
            $cuts[] = $p;
        }
        return $cuts;
    }

    /** A random JSON-encodable value (lists, string-keyed maps, scalars, unicode, control characters). */
    public static function randomValue(int $depth = 0): mixed
    {
        $pick = mt_rand(0, $depth > 3 ? 6 : 9);
        switch ($pick) {
            case 0: return null;
            case 1: return (bool) mt_rand(0, 1);
            case 2: return mt_rand(-1000, 1000);
            case 3: return mt_rand(0, 1) ? PHP_INT_MAX - mt_rand(0, 5) : PHP_INT_MIN + mt_rand(0, 5);
            case 4: return mt_rand(-100000, 100000) / 64 + 0.1;
            case 5:
            case 6:
                $alphabet = ['a', 'b', ' ', '"', '\\', '/', "\n", "\r", "\t", "\0", "\x1f", 'é', '日本', '😀', "\u{2028}", "\u{FEFF}", '{', '}', ',', ':'];
                $s = '';
                for ($i = mt_rand(0, 6); $i > 0; $i--) { $s .= $alphabet[mt_rand(0, count($alphabet) - 1)]; }
                return $s;
            case 7:
            case 8:
                $a = [];
                for ($i = mt_rand(0, 4); $i > 0; $i--) { $a[] = self::randomValue($depth + 1); }
                return $a;
            default:
                $a = [];
                for ($i = mt_rand(0, 4); $i > 0; $i--) { $a['k' . mt_rand(0, 9)] = self::randomValue($depth + 1); }
                return $a;
        }
    }
}
