<?php

declare(strict_types=1);

namespace Zephyrus\Mailer;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Thrown when a mail operation fails.
 */
final class MailerException extends ZephyrusRuntimeException
{
    /**
     * The transport's reply. Held as a field so that reading it is an explicit act.
     */
    private ?string $transportMessage = null;

    public function __construct(
        string $message,
        public readonly MailerFailure $failure,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * The transport did not accept the message. Its reply goes to transportMessage(), not the message, because it can name recipients.
     *
     * @param string $transportMessage The transport's own reply.
     */
    public static function sendFailed(#[\SensitiveParameter] string $transportMessage): self
    {
        $exception = new self(
            'The mail transport did not accept the message; the reason is withheld, '
            . 'read transportMessage() (it may name recipients).',
            MailerFailure::SendFailed,
        );
        $exception->transportMessage = $transportMessage;

        return $exception;
    }

    /**
     * The transport's own reply, which can name recipients: scrub it before writing it to any sink.
     */
    public function transportMessage(): ?string
    {
        return $this->transportMessage;
    }

    /**
     * @param string $method The recipient method that received the address (to, cc, bcc, replyTo).
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
