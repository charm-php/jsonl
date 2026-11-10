<?php

declare(strict_types=1);

namespace Charm\Jsonl;

/**
 * Incremental JSON Lines decoder. Feed it input in chunks of any size and any alignment (a chunk may
 * end in the middle of a line or of a multibyte character); it returns the values of the lines
 * completed so far. Where the chunks are cut never changes the result.
 *
 *     $decoder = new Decoder();
 *     $h = fopen('data.jsonl', 'rb');
 *     while (!feof($h)) {
 *         foreach ($decoder->decode(fread($h, 65536)) as $value) { ... }
 *     }
 *     foreach ($decoder->finish() as $value) { ... }
 *
 * Semantics:
 *  - Lines end with "\n"; a "\r" directly before it is stripped.
 *  - A UTF-8 BOM at the very start of the input is stripped.
 *  - Empty lines are skipped (common NDJSON practice). A line holding only whitespace is not empty: it is invalid JSON.
 *  - Every other line must be exactly one JSON value, decoded with json_decode() and the given
 *    $assoc, $depth and $flags (JSON_THROW_ON_ERROR is always added). So big integers, depth limits and
 *    JSON_BIGINT_AS_STRING behave exactly as they do for json_decode() on that line alone.
 *  - A last line without "\n" is returned by finish().
 *  - A line that is not valid JSON throws ParseException with its 1-based line number over the whole
 *    input, and the \JsonException as previous. After that the decoder is in an undefined state: discard it.
 *  - Values are returned as soon as their line is complete, except that the first three bytes of the
 *    input are held back until they are known not to be a BOM.
 *  - Limit: a line is held in memory until its "\n" arrives.
 */
final class Decoder
{
    private const BOM = "\xEF\xBB\xBF";

    private string $tail = '';
    private bool $head = true;
    /** Number of "\n" consumed so far. */
    private int $newlines = 0;
    private readonly int $flags;

    /**
     * @param bool $assoc true: objects become associative arrays; false: stdClass objects
     * @param int $depth maximum nesting depth of a line, as for json_decode()
     * @param int $flags json_decode() flags, e.g. JSON_BIGINT_AS_STRING
     * @throws \ValueError if $depth is less than 1
     */
    public function __construct(private readonly bool $assoc = true, private readonly int $depth = 512, int $flags = 0)
    {
        if ($depth < 1) {
            throw new \ValueError('Depth must be at least 1');
        }
        $this->flags = $flags | JSON_THROW_ON_ERROR;
    }

    /**
     * Add input and return the values of the lines it completed (possibly none).
     *
     * @return list<mixed>
     * @throws ParseException
     */
    public function decode(string $chunk): array
    {
        if ($this->head) { // a BOM may arrive in pieces
            $this->tail .= $chunk;
            if (strlen($this->tail) < 3) {
                return [];
            }
            $this->head = false;
            $chunk = str_starts_with($this->tail, self::BOM) ? substr($this->tail, 3) : $this->tail;
            $this->tail = '';
        }
        if (!str_contains($chunk, "\n")) { // the common case for a long line: just append, never re-scan
            $this->tail .= $chunk;
            return [];
        }
        return $this->lines($this->tail . $chunk, false);
    }

    /**
     * Signal the end of input: returns the value of the last line (the one without "\n") and
     * resets the decoder so it can be used for another input.
     *
     * @return list<mixed>
     * @throws ParseException
     */
    public function finish(): array
    {
        $buf = $this->tail;
        if ($this->head && str_starts_with($buf, self::BOM)) {
            $buf = substr($buf, 3);
        }
        $first = $this->newlines;
        $this->tail = '';
        $this->head = true;
        $this->newlines = 0;
        return $buf === '' ? [] : $this->lines($buf, true, $first);
    }

    /** @return list<mixed> */
    private function lines(string $buf, bool $final, int $first = 0): array
    {
        if (str_contains($buf, "\r")) {
            $buf = str_replace("\r\n", "\n", $buf); // a raw CR can only end a line as part of CRLF; inside JSON it is whitespace
        }
        $lines = explode("\n", $buf);
        $rest = array_pop($lines);
        if ($final) {
            $lines[] = $rest;
        } else {
            $first = $this->newlines;
            $this->newlines += count($lines);
            $this->tail = $rest;
        }
        $out = [];
        foreach ($lines as $i => $line) {
            if ($line === '') { continue; }
            try {
                $out[] = json_decode($line, $this->assoc, $this->depth, $this->flags);
            } catch (\JsonException $e) {
                throw new ParseException($e->getMessage(), $first + $i + 1, $e);
            }
        }
        return $out;
    }
}
