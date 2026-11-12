<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Support;

use RuntimeException;
use Zephyrus\Http\Response;

/** A route handler that fails with an exception no built-in mapping answers. */
final class UnhandledExceptionController
{
    public const string MESSAGE = 'connection refused for db.example.test';

    public function boom(): Response
    {
        throw new RuntimeException(self::MESSAGE);
    }
}
