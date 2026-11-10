<?php

declare(strict_types=1);

namespace Charm\Jsonl\Tests;

use Charm\Jsonl\Decoder;
use Charm\Jsonl\ParseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecoderTest extends TestCase
{
    private static function all(string $input, bool $assoc = true, int $depth = 512, int $flags = 0): array
    {
        $r = Support::decode($input, [], $assoc, $depth, $flags);
        self::assertCount(1, $r, 'unexpected parse error: ' . json_encode($r));
        return $r[0];
    }

    #[DataProvider('documents')]
    public function testValues(string $input, array $expected): void
    {
        $this->assertSame($expected, self::all($input));
    }

    public static function documents(): iterable
    {
        yield 'objects' => ["{\"a\":1}\n{\"b\":[1,2]}\n", [['a' => 1], ['b' => [1, 2]]]];
        yield 'scalars' => ["1\n\"s\"\ntrue\nfalse\nnull\n1.5\n", [1, 's', true, false, null, 1.5]];
        yield 'empty containers' => ["[]\n{}\n", [[], []]];
        yield 'empty input' => ['', []];
        yield 'only newlines' => ["\n\n\r\n", []];
        yield 'no trailing newline' => ["1\n2", [1, 2]];
        yield 'CRLF' => ["1\r\n2\r\n3\r\n", [1, 2, 3]];
        yield 'mixed LF and CRLF' => ["1\r\n2\n3", [1, 2, 3]];
        yield 'empty lines are skipped' => ["\n1\n\n\n2\n\r\n3\n\n", [1, 2, 3]];
        yield 'BOM' => ["\xEF\xBB\xBF1\n2\n", [1, 2]];
        yield 'BOM only' => ["\xEF\xBB\xBF", []];
        yield 'BOM, then empty line' => ["\xEF\xBB\xBF\n1", [1]];
        yield 'CR inside a line is whitespace' => ["{\"a\":\r1}\n", [['a' => 1]]];
        yield 'CRCRLF leaves one CR, which is whitespace' => ["1\r\r\n2", [1, 2]];
        yield 'surrounding whitespace is fine' => ["  {\"a\" : 1}\t \n", [['a' => 1]]];
        yield 'U+2028 inside a string does not end the line' => ["\"a\u{2028}b\"\n\"c\"", ["a\u{2028}b", 'c']];
        yield 'unicode' => ["\"é日本😀\"\n\"\\u00e9\\ud83d\\ude00\"\n", ['é日本😀', 'é😀']];
        yield 'escaped newline in a string' => ["\"a\\nb\"\n", ["a\nb"]];
        yield 'duplicate keys: last wins' => ["{\"a\":1,\"a\":2}", [['a' => 2]]];
        yield 'empty key' => ["{\"\":1}", [['' => 1]]];
        yield 'numeric keys' => ["{\"1\":\"x\"}", [[1 => 'x']]];
    }

    // ---- errors ------------------------------------------------------------------------------

    #[DataProvider('invalid')]
    public function testErrorsCarryTheLineNumber(string $input, int $line): void
    {
        foreach ([[], [1], [2, 5], [3]] as $cuts) {
            $cuts = array_values(array_filter($cuts, fn($c) => $c < strlen($input)));
            $r = Support::decode($input, $cuts);
            $this->assertSame('error', $r[0] ?? null, 'cuts ' . implode(',', $cuts));
            $this->assertSame($line, $r[1], 'cuts ' . implode(',', $cuts));
        }
        $d = new Decoder();
        try {
            $d->decode($input);
            $d->finish();
            $this->fail('expected ParseException');
        } catch (ParseException $e) {
            $this->assertSame($line, $e->lineNumber);
            $this->assertInstanceOf(\JsonException::class, $e->getPrevious());
            $this->assertStringContainsString("at line $line", $e->getMessage());
        }
    }

    public static function invalid(): iterable
    {
        yield 'first line' => ["{\n", 1];
        yield 'second line' => ["1\n{\n", 2];
        yield 'last line without newline' => ["1\n2\n{", 3];
        yield 'empty lines count' => ["1\n\n\n{\n", 4];
        yield 'CRLF lines count once' => ["1\r\n2\r\n{\r\n", 3];
        yield 'lone CR is not a line break' => ["1\r2\n", 1];
        yield 'two values on one line' => ["1 2\n", 1];
        yield 'comma between values' => ["1,2\n", 1];
        yield 'whitespace only is not an empty line' => ["1\n  \n2\n", 2];
        yield 'single quotes' => ["{'a':1}", 1];
        yield 'trailing comma' => ["[1,2,]\n", 1];
        yield 'a value split over lines' => ["[1,\n2]\n", 1];
        yield 'BOM not at the start' => ["1\n\xEF\xBB\xBF2", 2];
        yield 'BOM twice' => ["\xEF\xBB\xBF\xEF\xBB\xBF1", 1];
        yield 'literal tab in a string' => ["\"a\tb\"", 1];
    }

    public function testInvalidUtf8IsAnErrorWithItsLine(): void
    {
        $r = Support::decode("\"ok\"\n\"\xff\"\n");
        $this->assertSame(['error', 2, JSON_ERROR_UTF8], $r);
    }

    public function testInvalidUtf8CanBeSubstitutedWithAFlag(): void
    {
        $this->assertSame(["\u{FFFD}"], self::all("\"\xff\"\n", true, 512, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertSame([''], self::all("\"\xff\"\n", true, 512, JSON_INVALID_UTF8_IGNORE));
    }

    public function testExceptionPreviousIsAJsonException(): void
    {
        $d = new Decoder();
        try {
            $d->decode("1\n\n[\n");
            $this->fail('expected ParseException');
        } catch (ParseException $e) {
            $this->assertSame(3, $e->lineNumber);
            $this->assertInstanceOf(\JsonException::class, $e->getPrevious());
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }
    }

    public function testErrorFromFinish(): void
    {
        $d = new Decoder();
        $this->assertSame([1], $d->decode("1\n{"));
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('at line 2');
        $d->finish();
    }

    public function testLineNumbersCountAcrossChunks(): void
    {
        $d = new Decoder();
        $d->decode("1\n2\n");
        $d->decode("\n\n3");
        $d->decode("\n");
        try {
            $d->decode("4\n{\n");
            $this->fail('expected ParseException');
        } catch (ParseException $e) {
            $this->assertSame(7, $e->lineNumber);
        }
    }

    // ---- options -----------------------------------------------------------------------------

    public function testAssoc(): void
    {
        $this->assertSame([['a' => ['b' => 1]]], self::all('{"a":{"b":1}}'));
        $o = self::all('{"a":{"b":1}}', false)[0];
        $this->assertInstanceOf(\stdClass::class, $o);
        $this->assertInstanceOf(\stdClass::class, $o->a);
        $this->assertSame(1, $o->a->b);
    }

    public function testDepthIsPerLineAndFollowsJsonDecode(): void
    {
        // json_decode() counts the nesting of arrays and objects, and the values inside the innermost one
        $this->assertSame([1], self::all('1', true, 1));
        $this->assertSame(['error', 1, JSON_ERROR_DEPTH], Support::decode('[1]', [], true, 1));
        $this->assertSame([[1]], self::all('[1]', true, 2));
        $this->assertSame(['error', 1, JSON_ERROR_DEPTH], Support::decode('[[1]]', [], true, 2));
        $this->assertSame([[[1]]], self::all('[[1]]', true, 3));
        $this->assertSame(['error', 3, JSON_ERROR_DEPTH], Support::decode("[1]\n1\n[[1]]\n", [], true, 2));
        $ok = str_repeat('[', 511) . '1' . str_repeat(']', 511);
        $this->assertCount(1, self::all($ok));
        $tooDeep = str_repeat('[', 512) . '1' . str_repeat(']', 512);
        $this->assertSame(['error', 1, JSON_ERROR_DEPTH], Support::decode($tooDeep));
        $this->assertCount(1, self::all($tooDeep, true, 513));
        // the depth limit does not add up across lines
        $this->assertCount(100, self::all(str_repeat("[[1]]\n", 100), true, 3));
    }

    public function testInvalidDepth(): void
    {
        $this->expectException(\ValueError::class);
        new Decoder(depth: 0);
    }

    public function testBigIntegers(): void
    {
        $input = "12345678901234567890\n9223372036854775807\n-9223372036854775808\n-9223372036854775809\n[18446744073709551616]\n";
        $expected = [12345678901234567890.0, PHP_INT_MAX, PHP_INT_MIN, -9223372036854775809.0, [1.8446744073709552E+19]];
        $this->assertSame($expected, self::all($input));
        $this->assertSame(['12345678901234567890', PHP_INT_MAX, PHP_INT_MIN, '-9223372036854775809', ['18446744073709551616']],
            self::all($input, true, 512, JSON_BIGINT_AS_STRING));
    }

    // ---- the incremental protocol ------------------------------------------------------------

    public function testDecodeReturnsTheValuesOfCompletedLines(): void
    {
        $d = new Decoder();
        $this->assertSame([], $d->decode('{"a"'));
        $this->assertSame([], $d->decode(':1}'));
        $this->assertSame([['a' => 1]], $d->decode("\n[2"));
        $this->assertSame([[2], 3], $d->decode("]\n3\n"));
        $this->assertSame([], $d->decode(''));
        $this->assertSame([], $d->finish());
    }

    public function testFinishReturnsTheLastLineAndResets(): void
    {
        $d = new Decoder();
        $this->assertSame([1], $d->decode("1\n2"));
        $this->assertSame([2], $d->finish());
        $this->assertSame([], $d->finish());
        // reuse: BOM handling and line numbers start over
        $this->assertSame([5], [...$d->decode("\xEF\xBB\xBF5\n"), ...$d->finish()]);
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('at line 1');
        $d->decode("{\n\n");
    }

    public function testNullLinesAreReturnedAsNull(): void
    {
        $this->assertSame([null, null], self::all("null\nnull\n"));
    }

    public function testBomArrivingInPieces(): void
    {
        $d = new Decoder();
        $this->assertSame([], $d->decode("\xEF"));
        $this->assertSame([], $d->decode("\xBB"));
        $this->assertSame([1], $d->decode("\xBF1\n"));
        // a short input that is not a BOM is not lost
        $d = new Decoder();
        $this->assertSame([], $d->decode('1'));
        $this->assertSame([], $d->decode("\n"));
        $this->assertSame([1, 2], [...$d->decode('2'), ...$d->finish()]);
        $d = new Decoder();
        $this->assertSame([7], [...$d->decode("7\n"), ...$d->finish()]);
        $d = new Decoder();
        $this->assertSame([[], 1], [...$d->decode("[]\n"), ...$d->decode("1"), ...$d->finish()]);
    }

    public function testBomIsOnlyStrippedOnce(): void
    {
        $this->assertSame('error', Support::decode("\xEF\xBB\xBF\xEF\xBB\xBF1")[0]);
    }

    public function testALongLineInTinyChunks(): void
    {
        $line = '[' . implode(',', range(1, 3000)) . ']';
        $d = new Decoder();
        $out = [];
        foreach (str_split($line . "\n", 3) as $piece) { array_push($out, ...$d->decode($piece)); }
        $this->assertSame([range(1, 3000)], $out);
    }

    public function testManyLinesInOneChunk(): void
    {
        $lines = [];
        for ($i = 0; $i < 20000; $i++) { $lines[] = '{"i":' . $i . '}'; }
        $out = self::all(implode("\n", $lines) . "\n");
        $this->assertCount(20000, $out);
        $this->assertSame(['i' => 19999], $out[19999]);
    }

    public function testStreamLoop(): void
    {
        $h = fopen('php://memory', 'w+');
        fwrite($h, "{\"a\":1}\r\n\r\n{\"a\":2}\n{\"a\":3}");
        rewind($h);
        $decoder = new Decoder();
        $rows = [];
        while (!feof($h)) {
            foreach ($decoder->decode(fread($h, 5)) as $row) { $rows[] = $row; }
        }
        foreach ($decoder->finish() as $row) { $rows[] = $row; }
        $this->assertSame([['a' => 1], ['a' => 2], ['a' => 3]], $rows);
    }
}
