<?php

declare(strict_types=1);

namespace Zephyrus\Mailer;

use Zephyrus\Core\Config\ConfigSection;

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
 */
final class MailerConfig extends ConfigSection
{
    public readonly string $smtpHost;
    public readonly int $smtpPort;
    public readonly string $smtpUsername;
    public readonly string $smtpPassword;
    public readonly string $smtpEncryption;
    public readonly string $fromAddress;
    public readonly string $fromName;

    public static function fromArray(array $values): static
    {
        $instance = new static($values);
        $instance->smtpHost = $instance->getString('smtp.host', 'localhost');
        $instance->smtpPort = $instance->getInt('smtp.port', 587);
        $instance->smtpUsername = $instance->getString('smtp.username', '');
        $instance->smtpPassword = $instance->getString('smtp.password', '');
        $instance->smtpEncryption = $instance->getString('smtp.encryption', 'tls');
        $instance->fromAddress = $instance->getString('from.address', '');
        $instance->fromName = $instance->getString('from.name', '');
        return $instance;
    }
}
