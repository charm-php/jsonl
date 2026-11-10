<?php

declare(strict_types=1);

namespace Charm\Jsonl\Tests;

use Charm\Jsonl\Decoder;
use Charm\Jsonl\Encoder;
use PHPUnit\Framework\TestCase;

final class EncoderTest extends TestCase
{
    public function testOneLinePerValue(): void
    {
        $e = new Encoder();
        $this->assertSame("{\"a\":1}\n", $e->encode(['a' => 1]));
        $this->assertSame("[1,2]\n", $e->encode([1, 2]));
        $this->assertSame("1\n", $e->encode(1));
        $this->assertSame("null\n", $e->encode(null));
        $this->assertSame("\"s\"\n", $e->encode('s'));
        $this->assertSame("{}\n", $e->encode(new \stdClass()));
        $this->assertSame("[]\n", $e->encode([]));
    }

    public function testDefaultFlagsKeepSlashesAndUnicode(): void
    {
        $this->assertSame("\"a/b é日本😀\"\n", (new Encoder())->encode('a/b é日本😀'));
    }

    public function testFlagsReplaceTheDefaults(): void
    {
        $this->assertSame("\"a\\/b \\u00e9\"\n", (new Encoder(0))->encode('a/b é'));
        $this->assertSame("\"a/b \\u00e9\"\n", (new Encoder(JSON_UNESCAPED_SLASHES))->encode('a/b é'));
        $this->assertSame("1.0\n", (new Encoder(JSON_PRESERVE_ZERO_FRACTION))->encode(1.0));
        $this->assertSame("1\n", (new Encoder())->encode(1.0));
        $this->assertSame("{\"a\":{}}\n", (new Encoder(JSON_FORCE_OBJECT))->encode(['a' => []]));
        $this->assertSame("{\"0\":1,\"1\":2}\n", (new Encoder(JSON_FORCE_OBJECT))->encode([1, 2]));
    }

    public function testNewlinesInStringsAreEscaped(): void
    {
        $line = (new Encoder())->encode(["a\nb", "c\r\nd", "\u{2028}", "\x00\x1f"]);
        $this->assertSame(1, substr_count($line, "\n"));
        $this->assertStringEndsWith("\n", $line);
        $this->assertSame([["a\nb", "c\r\nd", "\u{2028}", "\x00\x1f"]], (new Decoder())->decode($line));
    }

    public function testEvenUnescapedLineTerminatorsStayOnOneLine(): void
    {
        $line = (new Encoder(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS))->encode("a\u{2028}b");
        $this->assertSame(1, substr_count($line, "\n"));
    }

    public function testPrettyPrintIsRejected(): void
    {
        $this->expectException(\ValueError::class);
        new Encoder(JSON_PRETTY_PRINT);
    }

    public function testPrettyPrintIsRejectedAlongOtherFlags(): void
    {
        $this->expectException(\ValueError::class);
        new Encoder(JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function testInvalidDepthArgument(): void
    {
        $this->expectException(\ValueError::class);
        new Encoder(depth: 0);
    }

    public function testDepthLimit(): void
    {
        $e = new Encoder(depth: 2);
        $this->assertSame("[[1]]\n", $e->encode([[1]]));
        $this->expectException(\JsonException::class);
        $this->expectExceptionCode(JSON_ERROR_DEPTH);
        $e->encode([[[1]]]);
    }

    public function testErrorsThrowJsonException(): void
    {
        $e = new Encoder();
        foreach ([["\xff", JSON_ERROR_UTF8], [NAN, JSON_ERROR_INF_OR_NAN], [INF, JSON_ERROR_INF_OR_NAN], [fopen('php://memory', 'r'), JSON_ERROR_UNSUPPORTED_TYPE]] as [$v, $code]) {
            try {
                $e->encode($v);
                $this->fail('expected JsonException');
            } catch (\JsonException $ex) {
                $this->assertSame($code, $ex->getCode());
            }
        }
    }

    public function testSubstituteInvalidUtf8WithAFlag(): void
    {
        $this->assertSame("\"\u{FFFD}\"\n", (new Encoder(JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE))->encode("\xff"));
    }

    public function testJsonSerializable(): void
    {
        $o = new class implements \JsonSerializable { public function jsonSerialize(): mixed { return ['x' => 1]; } };
        $this->assertSame("{\"x\":1}\n", (new Encoder())->encode($o));
    }

    public function testNoEncoderStateBetweenCalls(): void
    {
        $e = new Encoder();
        try { $e->encode(NAN); } catch (\JsonException) {}
        $this->assertSame("1\n", $e->encode(1));
    }

    public function testWritingToAStream(): void
    {
        $h = fopen('php://memory', 'w+');
        $encoder = new Encoder();
        foreach ([['a' => 1], ['a' => 2]] as $value) {
            fwrite($h, $encoder->encode($value));
        }
        rewind($h);
        $this->assertSame("{\"a\":1}\n{\"a\":2}\n", stream_get_contents($h));
    }

    public function testRandomValuesRoundTripThroughTheDecoder(): void
    {
        mt_srand(20261110);
        foreach ([JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 0, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS] as $flags) {
            $e = new Encoder($flags);
            $values = [];
            $text = '';
            for ($i = 0; $i < 400; $i++) {
                $v = Support::randomValue();
                $values[] = $v;
                $text .= $e->encode($v);
            }
            $this->assertSame(400, substr_count($text, "\n"));
            $expected = array_map(fn($v) => json_decode(json_encode($v, $flags), true), $values);
            for ($k = 0; $k < 5; $k++) {
                $this->assertSame($expected, Support::decode($text, Support::randomCuts(strlen($text), 40))[0], "flags $flags, run $k");
            }
        }
    }
}
