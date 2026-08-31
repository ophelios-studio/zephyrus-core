<?php

declare(strict_types=1);

namespace Zephyrus\Mailer;

use Zephyrus\Core\Config\ConfigSection;
use Zephyrus\Core\Config\ConfigurationException;

/**
 * Configuration section for the mailer.
 *
 * YAML example:
 *
 *   mailer:
 *     smtp:
 *       host: !env MAIL_HOST, localhost
 *       port: !env MAIL_PORT, 587
 *       username: !env MAIL_USER
 *       password: !env MAIL_PASSWORD
 *       encryption: tls
 *     from:
 *       address: !env MAIL_FROM_ADDRESS
 *       name: !env MAIL_FROM_NAME
 *
 * ## smtp.encryption is an allow-list, not free text
 *
 * PHPMailer compares SMTPSecure with a strict identity against exactly two
 * literals, 'tls' and 'ssl'. Anything else -- a different case, a synonym, a
 * typo, a word an operator reasonably believed was the value -- matched
 * neither, fell through to the opportunistic SMTPAutoTLS path, and sent the
 * credentials over a socket a network attacker downgrades to plaintext simply
 * by not advertising STARTTLS. It failed silently and in the dangerous
 * direction:
 *
 *   encryption: STARTTLS   the RFC's own name for the mechanism -> plaintext
 *   encryption: Tls        one capital letter                   -> plaintext
 *   encryption: none       a reasonable way to say "off"        -> plaintext
 *
 * So the value is now lowercased, trimmed, and checked against ENCRYPTIONS.
 * A value outside the set throws at configuration time, where an operator can
 * still see it, instead of on the wire where nobody can.
 */
final class MailerConfig extends ConfigSection
{
    /**
     * Every accepted smtp.encryption value.
     *
     * 'tls'  implicit STARTTLS on the submission port (usually 587).
     * 'ssl'  implicit TLS from the first byte (usually 465).
     * ''     no transport encryption AT ALL. Mailer disables SMTPAutoTLS for
     *        this value, so it means what it says rather than "encrypt if the
     *        server happens to offer it". Use it only for a local sink.
     *
     * @var list<string>
     */
    public const array ENCRYPTIONS = ['tls', 'ssl', ''];

    /**
     * Config keys whose value toArray() must redact.
     *
     * @var list<string>
     */
    protected array $secretKeys = ['smtp.password'];

    public readonly string $smtpHost;
    public readonly int $smtpPort;
    public readonly string $smtpUsername;
    public readonly string $smtpPassword;
    public readonly string $smtpEncryption;
    public readonly string $fromAddress;
    public readonly string $fromName;

    /**
     * @param array<string, mixed> $values
     * @throws ConfigurationException when smtp.encryption is outside ENCRYPTIONS.
     */
    public static function fromArray(array $values): static
    {
        $instance = new static($values);
        $instance->smtpHost = $instance->getString('smtp.host', 'localhost');
        $instance->smtpPort = $instance->getInt('smtp.port', 587);
        $instance->smtpUsername = $instance->getString('smtp.username', '');
        $instance->smtpPassword = $instance->getString('smtp.password', '');
        $instance->smtpEncryption = self::normalizeEncryption(
            $instance->getString('smtp.encryption', 'tls'),
        );
        $instance->fromAddress = $instance->getString('from.address', '');
        $instance->fromName = $instance->getString('from.name', '');
        return $instance;
    }

    /**
     * Keep the SMTP password out of any dump that honours __debugInfo().
     *
     * This is a courtesy for var_dump() and for a consumer's own diagnostics.
     * It is NOT the load-bearing protection: Tracy reads properties by
     * reflection and ignores __debugInfo() unless asked, so the real masking
     * for the debugger is DebugIntegration::SENSITIVE_KEYS, and the masking for
     * a config dump is toArray()'s redaction.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'smtpHost' => $this->smtpHost,
            'smtpPort' => $this->smtpPort,
            'smtpUsername' => $this->smtpUsername,
            'smtpPassword' => $this->smtpPassword === '' ? '' : self::REDACTED,
            'smtpEncryption' => $this->smtpEncryption,
            'fromAddress' => $this->fromAddress,
            'fromName' => $this->fromName,
            'values' => $this->toArray(),
        ];
    }

    /**
     * @throws ConfigurationException
     */
    private static function normalizeEncryption(string $value): string
    {
        $normalized = strtolower(trim($value));

        if (!in_array($normalized, self::ENCRYPTIONS, true)) {
            throw ConfigurationException::invalidValue(
                'mailer',
                'smtp.encryption',
                $value,
                sprintf(
                    "must be one of 'tls', 'ssl' or '' (empty, meaning no encryption at all); "
                    . "PHPMailer matches this value with a strict identity, so '%s' would have "
                    . 'silently fallen back to opportunistic STARTTLS and sent the credentials in '
                    . 'cleartext against a server that does not advertise it',
                    $value,
                ),
            );
        }

        return $normalized;
    }
}
