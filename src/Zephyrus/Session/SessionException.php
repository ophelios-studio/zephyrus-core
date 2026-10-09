<?php

declare(strict_types=1);

namespace Zephyrus\Session;

use Zephyrus\Exceptions\ZephyrusException;

/**
 * Thrown when PHP refuses a session operation.
 *
 * The message names the possible causes and never the warning PHP raised, since
 * that warning carries absolute server paths. The warning is kept on
 * phpReason() for the caller who needs to know which cause it was.
 */
final class SessionException extends ZephyrusException
{
    private ?string $phpReason = null;

    public static function invalidKey(string $key): self
    {
        return new self(sprintf('Session key must be a non-empty string. Got "%s".', $key));
    }

    public static function saveHandlerRefused(?string $phpReason = null): self
    {
        return self::withReason(
            'PHP refused to install the session save handler, so sessions would keep going to the previous storage. '
            . 'Register the handler before the session starts.',
            $phpReason,
        );
    }

    public static function regenerationRefused(?string $phpReason = null): self
    {
        return self::withReason(
            'PHP refused to regenerate the session id. The usual causes are output already sent to the browser, '
            . 'a save handler that could not destroy the previous session, or a save handler that could not write '
            . 'the session (its row may have been deleted by a concurrent logout).',
            $phpReason,
        );
    }

    public static function destructionRefused(?string $phpReason = null): self
    {
        return self::withReason(
            'PHP refused to destroy the session, so the stored session may still be live.',
            $phpReason,
        );
    }

    public static function noActiveSession(string $operation): self
    {
        return new self(sprintf('Cannot %s: no session is active. Call start() first.', $operation));
    }

    public static function notActiveForDestruction(): self
    {
        return new self(
            'Cannot destroy the session: it is closed but still has an id, so its stored data may survive. '
            . 'Reopen it with start() before calling destroy().',
        );
    }

    /** PHP's own reason for the refusal, or null when PHP gave none. */
    public function phpReason(): ?string
    {
        return $this->phpReason;
    }

    private static function withReason(string $message, ?string $phpReason): self
    {
        $exception = new self($message);
        $exception->phpReason = $phpReason;

        return $exception;
    }
}
