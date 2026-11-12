<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigurationException;
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

    public function testAMisspelledSmtpKeyIsRefusedWithASuggestion(): void
    {
        try {
            MailerConfig::fromArray(['smtp' => ['prot' => 25]]);
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame(
                "Configuration section 'mailer' field 'smtp.prot' is an unknown key: did you mean \"smtp.port\"?",
                $e->getMessage(),
            );
        }
    }

    public function testAMisspelledFromKeyIsRefusedWithASuggestion(): void
    {
        try {
            MailerConfig::fromArray(['from' => ['adress' => 'a@example.com']]);
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame(
                "Configuration section 'mailer' field 'from.adress' is an unknown key: did you mean \"from.address\"?",
                $e->getMessage(),
            );
        }
    }

    public function testAMisspelledTopLevelKeyIsRefused(): void
    {
        try {
            MailerConfig::fromArray(['smpt' => ['host' => 'mail.example.com']]);
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame(
                "Configuration section 'mailer' field 'smpt' is an unknown key: did you mean \"smtp\"?",
                $e->getMessage(),
            );
        }
    }

    public function testARefusalNeverQuotesThePassword(): void
    {
        try {
            MailerConfig::fromArray(['smtp' => ['pasword' => 'hunter2-secret', 'password' => 'hunter2-secret']]);
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame(
                "Configuration section 'mailer' field 'smtp.pasword' is an unknown key: did you mean \"smtp.password\"?",
                $e->getMessage(),
            );
            self::assertStringNotContainsString('hunter2-secret', $e->getMessage());
        }
    }

    public function testASmtpSectionThatIsNotAMappingIsRefusedWithoutQuotingIt(): void
    {
        try {
            MailerConfig::fromArray(['smtp' => 'hunter2-secret']);
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame(
                "Configuration section 'mailer' field 'smtp' must be a mapping, such as { host: mail.example.com, port: 587 }.",
                $e->getMessage(),
            );
        }
    }

    public function testAPortReadFromAStringIsExportedAsAnInteger(): void
    {
        $config = MailerConfig::fromArray(['smtp' => ['port' => '1025']]);

        self::assertSame(1025, $config->smtpPort);
        self::assertSame(1025, $config->toArray()['smtp']['port']);
    }

    public function testANonIntegerPortIsStillRefused(): void
    {
        try {
            MailerConfig::fromArray(['smtp' => ['port' => 'abc']]);
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame(
                'Configuration section \'Zephyrus\\Mailer\\MailerConfig\' field \'smtp.port\' has invalid value "abc": is not an integer.',
                $e->getMessage(),
            );
        }
    }

    public function testASectionDerivedByAnApplicationStaysPermissive(): void
    {
        $section = new class(['smtp' => ['prot' => 25]]) extends ConfigSection {
        };

        self::assertSame(25, $section->get('smtp.prot'));
    }
}
