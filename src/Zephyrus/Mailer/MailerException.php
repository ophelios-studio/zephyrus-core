<?php

declare(strict_types=1);

namespace Zephyrus\Mailer;

use Zephyrus\Exceptions\MessageValue;
use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Thrown when a mail operation fails. Messages never contain credentials or message bodies.
 */
final class MailerException extends ZephyrusRuntimeException
{
    /**
     * The transport's reply.
     */
    private ?\SensitiveParameterValue $transportMessage = null;

    public function __construct(
        string $message,
        public readonly MailerFailure $failure,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * The transport did not accept the message. Its reply is only available from transportMessage().
     *
     * @param string $transportMessage The transport's own reply.
     */
    public static function sendFailed(#[\SensitiveParameter] string $transportMessage): self
    {
        return self::withheldReason(
            'The mail transport did not accept the message; the reason is withheld, '
            . 'read transportMessage() (it may name recipients).',
            MailerFailure::SendFailed,
            $transportMessage,
        );
    }

    /**
     * One or more recipients were refused. Its reply is only available from transportMessage().
     *
     * @param string $transportMessage The transport's own reply.
     */
    public static function recipientsRefused(#[\SensitiveParameter] string $transportMessage): self
    {
        return self::withheldReason(
            'One or more recipients were refused and others may have received the message; the reason is withheld, '
            . 'read transportMessage() (it may name recipients).',
            MailerFailure::RecipientsRefused,
            $transportMessage,
        );
    }

    /**
     * The transport's reply, which can name recipients: scrub it before logging.
     */
    public function transportMessage(): ?string
    {
        return $this->transportMessage?->getValue();
    }

    /**
     * The address in the from.address configuration is invalid.
     */
    public static function invalidFromAddress(): self
    {
        return new self('Invalid email address in the from.address configuration.', MailerFailure::InvalidAddress);
    }

    /**
     * @param string $method The recipient method that received the address (to, cc, bcc, replyTo).
     */
    public static function invalidAddress(string $method): self
    {
        return new self(sprintf('Invalid email address given to %s().', $method), MailerFailure::InvalidAddress);
    }

    /**
     * The attachment file does not exist.
     */
    public static function attachmentNotFound(string $path): self
    {
        return new self(
            sprintf('Attachment not found: %s', MessageValue::quote($path, keepEnd: true)),
            MailerFailure::AttachmentNotFound,
        );
    }

    /**
     * The attachment, its display name, its media type or its directory was refused.
     *
     * @param string $subject What was refused: path, display name, media type or directory.
     * @param string $value   The refused value, shown through MessageValue::quote(). Values over 64 bytes
     *                        are cut (paths keep their end, other values their start).
     * @param string $reason  The rule it broke, stated as the end of a sentence.
     */
    public static function attachmentRejected(string $subject, string $value, string $reason): self
    {
        $isPath = in_array($subject, ['path', 'directory'], true);

        return new self(
            sprintf('Attachment rejected: %s %s %s.', $subject, MessageValue::quote($value, keepEnd: $isPath), $reason),
            MailerFailure::AttachmentRejected,
        );
    }

    /**
     * The mailer was used without the configuration it needs.
     */
    public static function configurationMissing(string $detail): self
    {
        return new self(sprintf('Mailer configuration missing: %s', $detail), MailerFailure::ConfigurationMissing);
    }

    private static function withheldReason(
        string $message,
        MailerFailure $failure,
        #[\SensitiveParameter] string $transportMessage,
    ): self
    {
        $exception = new self($message, $failure);
        $exception->transportMessage = new \SensitiveParameterValue($transportMessage);

        return $exception;
    }
}
