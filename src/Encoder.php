<?php

declare(strict_types=1);

namespace Charm\Jsonl;

/**
 * JSON Lines encoder: one value becomes one line. It does no I/O, so it works with any sink:
 *
 *     $encoder = new Encoder();
 *     $h = fopen('out.jsonl', 'wb');
 *     foreach ($values as $value) { fwrite($h, $encoder->encode($value)); }
 *
 * JSON_THROW_ON_ERROR is always set, so values json_encode() cannot represent throw a \JsonException.
 * JSON_PRETTY_PRINT is rejected because it would break the one-value-per-line format.
 */
final class Encoder
{
    private readonly int $flags;

    /**
     * @param int $flags json_encode() flags; the default writes slashes and non-ASCII characters as they are
     * @param int $depth maximum nesting depth, as for json_encode()
     * @throws \ValueError if $flags contains JSON_PRETTY_PRINT or $depth is less than 1
     */
    public function __construct(int $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, private readonly int $depth = 512)
    {
        if ($flags & JSON_PRETTY_PRINT) {
            throw new \ValueError('JSON_PRETTY_PRINT would put one value on several lines');
        }
        if ($depth < 1) {
            throw new \ValueError('Depth must be at least 1');
        }
        $this->flags = $flags | JSON_THROW_ON_ERROR;
    }

    /**
     * Encode one value as one line, including the terminating "\n".
     *
     * @throws \JsonException if the value cannot be encoded
     */
    public function encode(mixed $value): string
    {
        return json_encode($value, $this->flags, $this->depth) . "\n";
    }
}
