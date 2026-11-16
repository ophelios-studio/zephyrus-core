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
        /**
         * The largest of the valid declared Content-Length (a client claim, possibly PHP_INT_MAX), the raw body
         * length, and the uploaded files plus, when the raw body is empty, the keys and values of the text fields
         * (a 1500-byte field named "a" counts 1501).
         */
        public int $bytes,
        public int $maxBytes,
    ) {
    }
}
