<?php

declare(strict_types=1);

namespace Charm\Jsonl\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The Decoder must agree with the plain reference (split, strip, json_decode per line) and ignore chunking. */
final class DifferentialTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function documents(): iterable
    {
        $big = '[1,12345678901234567890,-9223372036854775809,9223372036854775807,1e400,0.1,-0.0,1E2]';
        $docs = [
            'simple' => "{\"a\":1}\n[2]\n\"x\"\n",
            'big ints' => "$big\n12345678901234567890\n",
            'unicode' => "\"é日本😀\"\n\"\\ud83d\\ude00\"\n\"\\ud83d\"\n\"\u{2028}\"\n",
            'CRLF' => "1\r\n2\r\n\r\n3\r\n",
            'BOM' => "\xEF\xBB\xBF{\"a\":1}\n",
            'empty lines' => "\n\n1\n\n\n2\n\n",
            'no final newline' => "1\n2",
            'invalid in the middle' => "1\n{\n3\n",
            'invalid utf8' => "1\n\"\xc3\"\n3\n",
            'invalid utf8 pair across lines' => "\"\xc3\n\xa9\"\n",
            'lone CR' => "1\r2\n3\n",
            'whitespace line' => "1\n \n2\n",
            'deep' => str_repeat('[', 40) . str_repeat(']', 40) . "\n[[1]]\n",
            'split value' => "[1,\n2]\n",
            'just a BOM' => "\xEF\xBB\xBF",
            'trailing garbage' => "1\n2 x",
        ];
        foreach ($docs as $name => $doc) {
            yield $name => [$doc];
        }
    }

    /** Every combination of options on every document, with a single cut at every byte offset. */
    #[DataProvider('documents')]
    public function testEverySingleSplit(string $doc): void
    {
        foreach ([[true, 512, 0], [false, 512, 0], [true, 1, 0], [true, 3, JSON_BIGINT_AS_STRING], [true, 512, JSON_INVALID_UTF8_SUBSTITUTE]] as [$assoc, $depth, $flags]) {
            $expected = Support::reference($doc, $assoc, $depth, $flags);
            $this->assertEquals($expected, Support::decode($doc, [], $assoc, $depth, $flags), 'no cuts');
            for ($cut = 0; $cut <= strlen($doc); $cut++) {
                $this->assertEquals($expected, Support::decode($doc, [$cut], $assoc, $depth, $flags), "cut at $cut, depth $depth, flags $flags");
            }
        }
    }

    #[DataProvider('documents')]
    public function testEveryChunkSize(string $doc): void
    {
        $expected = Support::reference($doc);
        for ($size = 1; $size <= 9; $size++) {
            $cuts = [];
            for ($p = $size; $p < strlen($doc); $p += $size) { $cuts[] = $p; }
            $this->assertEquals($expected, Support::decode($doc, $cuts), "chunk size $size");
        }
    }

    public function testThreeWaySplitsOfASmallDocument(): void
    {
        $doc = "\xEF\xBB\xBF\"é\"\r\n\n[1]\r\n{\n";
        $expected = Support::reference($doc);
        $n = strlen($doc);
        for ($i = 0; $i <= $n; $i++) {
            for ($j = $i + 1; $j <= $n; $j++) {
                $this->assertEquals($expected, Support::decode($doc, [$i, $j]), "cuts $i, $j");
            }
        }
    }

    /** Random mixtures of valid, invalid and empty lines with random line endings and random chunking. */
    public function testRandomDocuments(): void
    {
        mt_srand(1110);
        $junk = ['{', '}', '[1,', ']', '"', '1 2', '1,2', "'a'", 'nul', 'tru', '{"a":}', '  ', "\xff", "\"\xc3\"", '1e', '--1', '"\\u12"', '[' . str_repeat('[', 600)];
        for ($n = 0; $n < 400; $n++) {
            $doc = mt_rand(0, 9) === 0 ? "\xEF\xBB\xBF" : '';
            for ($i = mt_rand(0, 12); $i > 0; $i--) {
                $r = mt_rand(0, 19);
                if ($r < 11) { $line = json_encode(Support::randomValue(), mt_rand(0, 1) ? JSON_UNESCAPED_UNICODE : 0); }
                elseif ($r < 14) { $line = ''; }
                elseif ($r < 15) { $line = ' ' . json_encode(Support::randomValue()) . "\t"; }
                elseif ($r < 16) { $line = ' '; }
                else { $line = mt_rand(0, 3) ? '1' : $junk[mt_rand(0, count($junk) - 1)]; }
                $doc .= $line . ['', "\n", "\r\n", "\n", "\r\n", "\r"][mt_rand(0, 5)] . (mt_rand(0, 6) === 0 ? "\n" : '');
            }
            if (mt_rand(0, 3) === 0) { $doc = rtrim($doc, "\r\n"); }
            [$assoc, $depth, $flags] = [(bool) mt_rand(0, 1), [1, 2, 5, 512][mt_rand(0, 3)], [0, JSON_BIGINT_AS_STRING, JSON_INVALID_UTF8_SUBSTITUTE][mt_rand(0, 2)]];
            $expected = Support::reference($doc, $assoc, $depth, $flags);
            $label = json_encode($doc, JSON_INVALID_UTF8_SUBSTITUTE) . " depth $depth flags $flags";
            $this->assertEquals($expected, Support::decode($doc, [], $assoc, $depth, $flags), $label);
            for ($k = 0; $k < 3; $k++) {
                $cuts = Support::randomCuts(strlen($doc), mt_rand(1, 12));
                $this->assertEquals($expected, Support::decode($doc, $cuts, $assoc, $depth, $flags), "$label cuts " . implode(',', $cuts));
            }
        }
    }

    public function testBigIntegersMatchJsonDecodeLineByLine(): void
    {
        mt_srand(7);
        $lines = [];
        for ($i = 0; $i < 300; $i++) {
            $digits = mt_rand(1, 25);
            $s = (string) mt_rand(1, 9);
            for ($d = 1; $d < $digits; $d++) { $s .= mt_rand(0, 9); }
            $lines[] = (mt_rand(0, 1) ? '-' : '') . $s;
        }
        $doc = implode("\n", $lines);
        foreach ([0, JSON_BIGINT_AS_STRING] as $flags) {
            $expected = array_map(fn($l) => json_decode($l, true, 512, $flags), $lines);
            $r = Support::decode($doc, Support::randomCuts(strlen($doc), 30), true, 512, $flags);
            $this->assertSame($expected, $r[0]);
        }
    }

    public function testMultibyteCharactersSplitAtEveryByte(): void
    {
        $doc = "\"é日本😀\"\n[\"\u{1F600}\"]\n";
        $expected = Support::reference($doc);
        for ($cut = 0; $cut <= strlen($doc); $cut++) {
            $this->assertSame($expected, Support::decode($doc, [$cut]), "cut $cut");
        }
    }
}
