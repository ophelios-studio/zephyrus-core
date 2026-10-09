<?php

declare(strict_types=1);

namespace Zephyrus\Session;

use Zephyrus\Exceptions\ZephyrusException;

/**
 * Thrown when PHP refuses a session operation or the session storage is misconfigured.
 *
 * The message names the possible causes and never the warning PHP raised, since
 * that warning carries absolute server paths. The warning is chained as the
 * previous exception, an ErrorException, so a logger recording the chain keeps
 * the cause.
 */
final class SessionException extends ZephyrusException
{
    public static function invalidKey(string $key): self
    {
        return new self(sprintf('Session key must be a non-empty string. Got "%s".', $key));
    }

    public static function saveHandlerRefused(?\ErrorException $phpWarning = null): self
    {
        return self::withReason(
            'PHP refused to install the session save handler, so sessions would keep going to the previous storage. '
            . 'Register the handler before the session starts.',
            $phpWarning,
        );
    }

    public static function startRefused(?\ErrorException $phpWarning = null): self
    {
        return self::withReason(
            'PHP refused to start the session. The usual causes are output already sent to the browser, '
            . 'or a save handler that could not open or read the session.',
            $phpWarning,
        );
    }

    public static function regenerationRefused(?\ErrorException $phpWarning = null): self
    {
        return self::withReason(
            'PHP refused to regenerate the session id. The usual causes are output already sent to the browser, '
            . 'a save handler that could not destroy the previous session, or a save handler that could not write '
            . 'the session (its stored session may have been destroyed by a concurrent logout).',
            $phpWarning,
        );
    }

    public static function destructionRefused(?\ErrorException $phpWarning = null): self
    {
        return self::withReason(
            'PHP refused to destroy the session, so the stored session may still be live.',
            $phpWarning,
        );
    }

    public static function dataColumnNotText(string $type): self
    {
        return new self(sprintf(
            'The data column of the session table returned %s instead of a string. '
            . 'Declare it TEXT NOT NULL, as DatabaseSessionHandler documents.',
            $type,
        ));
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

    /**
     * PHP's reason for the refusal, or null when it gave none. It carries
     * absolute paths: log it, never display it.
     */
    public function phpReason(): ?string
    {
        $warning = $this->getPrevious();

        return $warning instanceof \ErrorException ? $warning->getMessage() : null;
    }

    private static function withReason(string $message, ?\ErrorException $phpWarning): self
    {
        return new self($message, 0, $phpWarning);
    }
}
