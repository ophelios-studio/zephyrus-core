<?php

declare(strict_types=1);

namespace Zephyrus\Mailer;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Thrown when a mail operation fails.
 */
final class MailerException extends ZephyrusRuntimeException
{
    public static function sendFailed(string $reason, ?\Throwable $previous = null): self
    {
        return new self(sprintf('Failed to send email: %s', $reason), previous: $previous);
    }

    public static function invalidAddress(string $address): self
    {
        return new self(sprintf('Invalid email address: %s', $address));
    }

    public static function attachmentNotFound(string $path): self
    {
        return new self(sprintf('Attachment not found: %s', $path));
    }

    public static function configurationMissing(string $detail): self
    {
        return new self(sprintf('Mailer configuration missing: %s', $detail));
    }
}
