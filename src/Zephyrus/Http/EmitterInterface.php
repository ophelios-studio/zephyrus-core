<?php

declare(strict_types=1);

namespace Zephyrus\Http;

/**
 * Sends a Response to the PHP SAPI (status line, headers, body).
 *
 * Final step of the request lifecycle:
 *
 * ```php
 * $response = $kernel->handle(Request::fromGlobals());
 * (new SapiEmitter())->emit($response);
 * ```
 *
 * In-memory implementations (test doubles) may ignore headers and capture only the body.
 */
interface EmitterInterface
{
    public function emit(Response $response): void;
}
