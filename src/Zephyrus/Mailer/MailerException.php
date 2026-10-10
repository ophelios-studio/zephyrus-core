<?php

declare(strict_types=1);

namespace Zephyrus\Mailer;

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
     * Longest refused value copied into an attachment rejection message.
     */
    private const SHOWN_VALUE_MAX_LENGTH = 64;

    /**
     * A UTF-8 character is at most 4 bytes, so a character boundary lies within 3 bytes of any cut.
     */
    private const int MAX_BOUNDARY_WALK = 3;

    /**
     * C1 controls, U+061C, U+2028, U+2029 and the bidi controls, escaped as \u{XXXX} in messages.
     */
    private const string ESCAPED_CHARACTER_PATTERN = '~[\x{80}-\x{9F}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2028}\x{2029}\x{2066}-\x{2069}]~u';

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
            sprintf('Attachment not found: %s', self::quotedValue($path, true)),
            MailerFailure::AttachmentNotFound,
        );
    }

    /**
     * The attachment, its display name, its media type or its directory was refused.
     *
     * @param string $subject What was refused: path, display name, media type or directory.
     * @param string $value   The refused value, escaped for the message: invalid UTF-8 becomes "?",
     *                        C0 controls, DEL and backslashes are C-escaped (\n, \001); C1, bidi and
     *                        line separator characters become \u{XXXX}. Values over 64 bytes are cut
     *                        (paths keep their end, other values their start).
     * @param string $reason  The rule it broke, stated as the end of a sentence.
     */
    public static function attachmentRejected(string $subject, string $value, string $reason): self
    {
        $isPath = in_array($subject, ['path', 'directory'], true);

        return new self(
            sprintf('Attachment rejected: %s %s %s.', $subject, self::quotedValue($value, $isPath), $reason),
            MailerFailure::AttachmentRejected,
        );
    }

    /**
     * Quote a value for a message, escaped and cut as attachmentRejected() documents.
     */
    private static function quotedValue(string $value, bool $keepEnd): string
    {
        if (strlen($value) <= self::SHOWN_VALUE_MAX_LENGTH) {
            return '"' . self::escaped($value) . '"';
        }

        if ($keepEnd) {
            $start = strlen($value) - self::SHOWN_VALUE_MAX_LENGTH;
            $limit = $start + self::MAX_BOUNDARY_WALK;
            while ($start < $limit && (ord($value[$start]) & 0xC0) === 0x80) {
                ++$start;
            }

            return sprintf('"...%s" (%d bytes)', self::escaped(substr($value, $start)), strlen($value));
        }

        $cut = mb_strcut($value, 0, self::SHOWN_VALUE_MAX_LENGTH, 'UTF-8');

        return sprintf('"%s..." (%d bytes)', self::escaped($cut), strlen($value));
    }

    private static function escaped(string $value): string
    {
        $escaped = addcslashes(mb_scrub($value, 'UTF-8'), "\\\0..\37\177");

        return preg_replace_callback(
            self::ESCAPED_CHARACTER_PATTERN,
            static fn (array $match): string => sprintf('\u{%04X}', mb_ord($match[0], 'UTF-8')),
            $escaped,
        ) ?? '';
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
