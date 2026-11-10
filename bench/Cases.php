<?php

declare(strict_types=1);

namespace Charm\Jsonl\Bench;

use Charm\Jsonl\Decoder;
use Charm\Jsonl\Encoder;

/**
 * The code being timed. It lives in an included file on purpose: the tracing JIT only compiles
 * code from files that opcache has cached, and the main script is never cached.
 * "collect" keeps every value (the result array is part of the cost), "stream" counts and discards.
 */
final class Cases
{
    private const CHUNK = 65536;

    /** The naive loop: read everything, explode("\n"), json_decode() each line. */
    public static function naiveCollect(string $file): array
    {
        $o = [];
        foreach (explode("\n", file_get_contents($file)) as $line) {
            if ($line !== '') { $o[] = json_decode($line, true); }
        }
        return $o;
    }

    /** The same with fgets(), the way a stream is usually read. */
    public static function fgetsCollect(string $file): array
    {
        $h = fopen($file, 'rb');
        $o = [];
        while (($line = fgets($h)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line !== '') { $o[] = json_decode($line, true); }
        }
        fclose($h);
        return $o;
    }

    public static function splFileObjectCollect(string $file): array
    {
        $f = new \SplFileObject($file);
        $f->setFlags(\SplFileObject::DROP_NEW_LINE | \SplFileObject::SKIP_EMPTY | \SplFileObject::READ_AHEAD);
        $o = [];
        foreach ($f as $line) { $o[] = json_decode($line, true); }
        return $o;
    }

    public static function splFileObjectStream(string $file): int
    {
        $f = new \SplFileObject($file);
        $f->setFlags(\SplFileObject::DROP_NEW_LINE | \SplFileObject::SKIP_EMPTY | \SplFileObject::READ_AHEAD);
        $n = 0;
        foreach ($f as $line) { json_decode($line, true); $n++; }
        return $n;
    }

    public static function decoderCollect(string $file): array
    {
        $d = new Decoder();
        $h = fopen($file, 'rb');
        $o = [];
        while (!feof($h)) {
            foreach ($d->decode(fread($h, self::CHUNK)) as $v) { $o[] = $v; }
        }
        foreach ($d->finish() as $v) { $o[] = $v; }
        fclose($h);
        return $o;
    }

    public static function decoderStream(string $file): int
    {
        $d = new Decoder();
        $h = fopen($file, 'rb');
        $n = 0;
        while (!feof($h)) {
            foreach ($d->decode(fread($h, self::CHUNK)) as $v) { $n++; }
        }
        foreach ($d->finish() as $v) { $n++; }
        fclose($h);
        return $n;
    }

    public static function jsonEncodeLoop(array $values): int
    {
        $h = fopen('php://memory', 'w+b');
        foreach ($values as $v) { fwrite($h, json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"); }
        return ftell($h);
    }

    public static function encoder(array $values): int
    {
        $h = fopen('php://memory', 'w+b');
        $e = new Encoder();
        foreach ($values as $v) { fwrite($h, $e->encode($v)); }
        return ftell($h);
    }
}
