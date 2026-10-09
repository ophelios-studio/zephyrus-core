<?php

declare(strict_types=1);

namespace Zephyrus\Mailer;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Thrown when a mail operation fails.
 */
final class MailerException extends ZephyrusRuntimeException
{
    public function __construct(
        string $message,
        public readonly MailerFailure $failure,
        ?\Throwable $previous = null,
        #[\SensitiveParameter] public readonly ?string $transportMessage = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * Failure to send. The transport's reply goes to transportMessage, not the message, because it can name recipients.
     *
     * @param string $transportMessage The transport's own reply.
     */
    public static function sendFailed(#[\SensitiveParameter] string $transportMessage): self
    {
        return new self('The mail transport refused the message.', MailerFailure::SendFailed, null, $transportMessage);
    }

    /**
     * @param string $method The recipient method that received the address (to, cc, bcc, replyTo, from).
     */
    public static function invalidAddress(string $method): self
    {
        return new self(sprintf('Invalid email address given to %s().', $method), MailerFailure::InvalidAddress);
    }

    public static function attachmentNotFound(string $path): self
    {
        return new self(sprintf('Attachment not found: %s', $path), MailerFailure::AttachmentNotFound);
    }

    /**
     * The attachment path or display name broke a rule attach() enforces.
     *
     * Separate from attachmentNotFound() on purpose: "this file is not there"
     * and "this path is not allowed to be attached" are different answers, and
     * a caller logging them should be able to tell them apart.
     */
    public static function attachmentRejected(string $value, string $reason): self
    {
        return new self(sprintf('Attachment rejected: %s %s.', $value, $reason), MailerFailure::AttachmentRejected);
    }

    public static function configurationMissing(string $detail): self
    {
        return new self(sprintf('Mailer configuration missing: %s', $detail), MailerFailure::ConfigurationMissing);
    }
}
