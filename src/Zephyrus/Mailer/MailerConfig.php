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
 * Options (type, default):
 *
 *   smtp.host        string  'localhost'
 *   smtp.port        int     587
 *   smtp.username    string  ''
 *   smtp.password    string  '' (redacted in toArray())
 *   smtp.encryption  string  'tls', one of ENCRYPTIONS
 *   from.address     string  '' (setFrom() is skipped; PHPMailer sends an empty From header)
 *   from.name        string  ''
 *
 * smtp.encryption is an allow-list. It is lowercased, trimmed and checked against
 * ENCRYPTIONS at configuration time: any other value would silently fall back to
 * opportunistic STARTTLS, which a network attacker can strip to send credentials in plaintext.
 */
final class MailerConfig extends ConfigSection
{
    /**
     * Accepted smtp.encryption values: 'tls' (STARTTLS, usually port 587), 'ssl' (implicit TLS,
     * usually port 465), or '' for no encryption, which disables opportunistic STARTTLS. Local sinks only.
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
     * @throws ConfigurationException when a value has the wrong type or smtp.encryption is outside ENCRYPTIONS.
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
     * Mask the SMTP password in var_dump(). Not a security boundary: reflection-based
     * debuggers ignore it, see DebugIntegration::SENSITIVE_KEYS.
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
