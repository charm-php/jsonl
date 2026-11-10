<?php

declare(strict_types=1);

/**
 * Benchmarks charm/jsonl against the naive json_decode() loops, SplFileObject and json_encode().
 * Run it through run.php, which starts this script with and without the JIT:
 *
 *     php bench/run.php
 *
 * Options: --lines=200000  --runs=9
 * Times are best and median of --runs after two warm-up runs.
 */

use Charm\Jsonl\Bench\Cases;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Cases.php';

$opts = getopt('', ['lines::', 'runs::']);
$n = (int) ($opts['lines'] ?? 200000);
$runs = (int) ($opts['runs'] ?? 9);
$dir = __DIR__ . '/data';

/**
 * Deterministic data. "records": nested objects of about 250 bytes with strings, unicode, floats, booleans and null.
 * "tiny": about 25 bytes per line, where per-line overhead matters most.
 */
function generate(string $file, int $n, string $kind): void
{
    mt_srand($kind === 'records' ? 11 : 12);
    $words = ['alpha', 'beta', 'gamma', 'oslo', 'bergen', 'lorem ipsum', 'é', '日本語', 'x"y', 'a/b'];
    $out = fopen($file, 'wb');
    for ($i = 0; $i < $n; $i++) {
        if ($kind === 'tiny') {
            $v = ['i' => $i, 'ok' => (bool) mt_rand(0, 1), 'n' => mt_rand(0, 999)];
        } else {
            $v = [
                'id' => $i,
                'name' => $words[mt_rand(0, 9)] . ' ' . $words[mt_rand(0, 9)],
                'score' => mt_rand(0, 100000) / 100,
                'active' => (bool) mt_rand(0, 1),
                'tags' => [$words[mt_rand(0, 9)], $words[mt_rand(0, 9)], $words[mt_rand(0, 9)]],
                'address' => ['city' => $words[mt_rand(0, 9)], 'zip' => (string) mt_rand(1000, 9999), 'geo' => [mt_rand(0, 9000) / 100, mt_rand(0, 18000) / 100]],
                'note' => mt_rand(0, 3) ? null : str_repeat($words[mt_rand(0, 9)] . ' ', mt_rand(1, 8)),
            ];
        }
        fwrite($out, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
    fclose($out);
}

/** @return array{float, float} best and median in milliseconds */
function measure(int $runs, callable $fn): array
{
    for ($i = 0; $i < 2; $i++) { $fn(); } // warm-up: lets the tracing JIT see the hot loops
    $t = [];
    for ($i = 0; $i < $runs; $i++) {
        gc_collect_cycles();
        $s = hrtime(true);
        $x = $fn();
        $t[] = (hrtime(true) - $s) / 1e6;
        unset($x);
    }
    sort($t);
    return [$t[0], $t[intdiv(count($t), 2)]];
}

@mkdir($dir);
foreach (['records', 'tiny'] as $kind) {
    if (!is_file("$dir/$kind-$n.jsonl")) { generate("$dir/$kind-$n.jsonl", $n, $kind); }
}

$status = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
$jitOn = is_array($status) && ($status['jit']['on'] ?? false);
printf("PHP %s, %s, %s, opcache %s, JIT %s\n\n", PHP_VERSION, php_uname('s') . ' ' . php_uname('r'),
    trim((string) shell_exec("grep -m1 'model name' /proc/cpuinfo | cut -d: -f2")),
    is_array($status) ? 'on' : 'off', $jitOn ? 'tracing (opcache.jit=' . ini_get('opcache.jit') . ')' : 'off');

foreach (['records', 'tiny'] as $kind) {
    $file = "$dir/$kind-$n.jsonl";
    $expected = Cases::naiveCollect($file);
    foreach (['fgetsCollect', 'splFileObjectCollect', 'decoderCollect'] as $case) {
        if (Cases::$case($file) !== $expected) { echo "MISMATCH: $case on $kind data\n"; exit(1); }
    }
    $cases = [
        'explode() + json_decode(), collect' => fn() => Cases::naiveCollect($file),
        'fgets() + json_decode(), collect' => fn() => Cases::fgetsCollect($file),
        'SplFileObject + json_decode(), collect' => fn() => Cases::splFileObjectCollect($file),
        'SplFileObject + json_decode(), stream' => fn() => Cases::splFileObjectStream($file),
        'Decoder, collect' => fn() => Cases::decoderCollect($file),
        'Decoder, stream' => fn() => Cases::decoderStream($file),
    ];
    $bytes = (float) filesize($file);
    printf("### Decoding %s: %d lines, %.1f MB\n\n| Method | best ms | median ms | MB/s (best) | vs explode() loop (best) |\n|---|---:|---:|---:|---:|\n", $kind, $n, $bytes / 1e6);
    $base = null;
    foreach ($cases as $label => $fn) {
        [$best, $median] = measure($runs, $fn);
        $base ??= $best;
        printf("| %s | %.0f | %.0f | %.0f | %.2fx |\n", $label, $best, $median, $bytes / 1e6 / ($best / 1000), $base / $best);
    }
    echo "\n";
}

echo "### Encoding $n values to php://memory\n\n| Data | Method | best ms | median ms | MB/s (best) | vs json_encode() loop (best) |\n|---|---|---:|---:|---:|---:|\n";
foreach (['records', 'tiny'] as $kind) {
    $values = Cases::naiveCollect("$dir/$kind-$n.jsonl");
    $a = measure($runs, fn() => Cases::jsonEncodeLoop($values));
    $b = measure($runs, fn() => Cases::encoder($values));
    $size = Cases::encoder($values);
    printf("| %s | json_encode() . \"\\n\" loop | %.0f | %.0f | %.0f | 1.00x |\n", $kind, $a[0], $a[1], $size / 1e6 / ($a[0] / 1000));
    printf("| %s | Encoder + fwrite() | %.0f | %.0f | %.0f | %.2fx |\n", $kind, $b[0], $b[1], $size / 1e6 / ($b[0] / 1000), $a[0] / $b[0]);
}
echo "\n";

if ($jitOn) {
    $status = opcache_get_status(false);
    $used = $status['jit']['buffer_size'] - $status['jit']['buffer_free'];
    printf("JIT check: %d bytes of the %d byte JIT buffer in use after the run%s\n", $used, $status['jit']['buffer_size'], $used > 0 ? '' : ' (NOTHING COMPILED: numbers are not JIT numbers)');
    if ($used === 0) { exit(2); }
}
