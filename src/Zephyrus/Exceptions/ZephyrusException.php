<?php

declare(strict_types=1);

namespace Zephyrus\Exceptions;

use Exception;
use Throwable;

class ZephyrusException extends Exception
{
    /**
     * Points getFile() and getLine() at the code that called the exception's factory or constructor, not at the factory itself.
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);

        // Keep the last frame of the leading run of exception-class frames.
        $throwSite = null;
        foreach ($this->getTrace() as $frame) {
            if (!isset($frame['class']) || !is_a($frame['class'], self::class, true)) {
                break;
            }
            $throwSite = $frame;
        }

        if (isset($throwSite['file'], $throwSite['line'])) {
            $this->file = $throwSite['file'];
            $this->line = $throwSite['line'];
        }
    }
}
