<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigSection;
use Zephyrus\Mailer\MailerConfig;

final class MailerConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = MailerConfig::fromArray([]);

        self::assertSame('localhost', $config->smtpHost);
        self::assertSame(587, $config->smtpPort);
        self::assertSame('', $config->smtpUsername);
        self::assertSame('', $config->smtpPassword);
        self::assertSame('tls', $config->smtpEncryption);
        self::assertSame('', $config->fromAddress);
        self::assertSame('', $config->fromName);
    }

    public function testCustomValues(): void
    {
        $config = MailerConfig::fromArray([
            'smtp' => [
                'host' => 'mail.example.com',
                'port' => 465,
                'username' => 'user@example.com',
                'password' => 'secret',
                'encryption' => 'ssl',
            ],
            'from' => [
                'address' => 'noreply@example.com',
                'name' => 'My App',
            ],
        ]);

        self::assertSame('mail.example.com', $config->smtpHost);
        self::assertSame(465, $config->smtpPort);
        self::assertSame('user@example.com', $config->smtpUsername);
        self::assertSame('secret', $config->smtpPassword);
        self::assertSame('ssl', $config->smtpEncryption);
        self::assertSame('noreply@example.com', $config->fromAddress);
        self::assertSame('My App', $config->fromName);
    }

    public function testExtendsConfigSection(): void
    {
        $config = MailerConfig::fromArray([]);
        self::assertInstanceOf(ConfigSection::class, $config);
    }

    public function testPartialSmtpConfig(): void
    {
        $config = MailerConfig::fromArray([
            'smtp' => [
                'host' => 'custom-host',
            ],
        ]);

        self::assertSame('custom-host', $config->smtpHost);
        self::assertSame(587, $config->smtpPort); // Default.
    }
}
