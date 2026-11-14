<?php

declare(strict_types=1);

namespace Charm\Jsonl;

/**
 * A line that is not valid JSON, thrown by the Decoder. It is an \UnexpectedValueException, so
 * `catch (\UnexpectedValueException)` catches a data error from any charm package.
 *
 * `lineNumber` is 1-based and counts every line of the whole input, empty ones included, also across
 * chunks. The underlying \JsonException is available as the previous exception.
 */
final class ParseException extends \UnexpectedValueException
{
    public function __construct(
        string $reason,
        public readonly int $lineNumber,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("$reason at line $lineNumber", 0, $previous);
    }
}
