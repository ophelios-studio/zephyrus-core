<?php

declare(strict_types=1);

namespace Zephyrus\Data;

/**
 * A query parameter bound as raw bytes wherever PostgreSQL infers bytea (a bytea
 * column or ?::bytea). Reading a bytea column back returns a stream, not a string.
 */
final readonly class Binary
{
    public function __construct(public string $bytes)
    {
    }
}
