<?php

declare(strict_types=1);

namespace Zephyrus\Session;

use Zephyrus\Exceptions\ZephyrusException;

final class SessionException extends ZephyrusException
{
    public static function invalidKey(string $key): self
    {
        return new self(sprintf('Session key must be a non-empty string. Got "%s".', $key));
    }

    public static function saveHandlerRefused(): self
    {
        return new self(
            'PHP refused to install the session save handler, so sessions would keep going to the previous storage. '
            . 'Register the handler before the session starts.',
        );
    }

    public static function regenerationRefused(): self
    {
        return new self(
            'PHP refused to regenerate the session id. The usual causes are output already sent to the browser, '
            . 'a save handler that could not destroy the previous session, or a save handler that could not write '
            . 'the session (its row may have been deleted by a concurrent logout).',
        );
    }

    public static function destructionRefused(): self
    {
        return new self('PHP refused to destroy the session, so the stored session may still be live.');
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
}
