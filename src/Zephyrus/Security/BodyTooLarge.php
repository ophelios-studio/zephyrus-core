<?php

declare(strict_types=1);

namespace Zephyrus\Security;

/**
 * Why MaxBodySizeMiddleware refused a request.
 *
 * The failure callback receives attacker-controlled input, see MaxBodySizeMiddleware::__construct().
 */
final readonly class BodyTooLarge
{
    public function __construct(
        /** The declared Content-Length when valid (a client claim, possibly PHP_INT_MAX), otherwise the raw body length. */
        public int $bytes,
        public int $maxBytes,
    ) {
    }
}
