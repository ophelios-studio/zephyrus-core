<?php

declare(strict_types=1);

namespace Zephyrus\Http;

/**
 * Sends a Response to the PHP SAPI (status line, headers, body).
 *
 * Callers should prefer the returned value from {@see EmitterInterface::emit()}
 * as the final step in the request lifecycle:
 *
 * ```php
 * $kernel  = KernelBuilder::create()->withRouter($router)->build();
 * $emitter = new SapiEmitter();
 *
 * $request  = Request::fromGlobals();
 * $response = $kernel->handle($request);
 * $emitter->emit($response);
 * ```
 *
 * Implementors that do not interact with the real SAPI (e.g. test doubles or
 * in-memory capture emitters) may ignore header calls while still capturing
 * the body for assertion.
 */
interface EmitterInterface
{
    public function emit(Response $response): void;
}
