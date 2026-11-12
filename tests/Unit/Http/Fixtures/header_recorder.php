<?php

declare(strict_types=1);

namespace Zephyrus\Http;

/**
 * Shadows header() for the Zephyrus\Http namespace and records each call as [line, replace].
 * Require it only from a test that runs in a separate process: a function cannot be undefined.
 */
function header(string $header, bool $replace = true, int $response_code = 0): void
{
    $GLOBALS['recordedHeaders'][] = [$header, $replace];
}
