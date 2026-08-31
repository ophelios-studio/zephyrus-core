<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigSection;
use Zephyrus\Mailer\MailerConfig;

/**
 * The SMTP password rendered TWICE in a debugger snapshot: once as the typed
 * readonly property and once in the raw backing array underneath it. Tracy's
 * default mask list covers the array leaf, whose key is literally 'password',
 * and covers nothing named 'smtpPassword'.
 *
 * Three surfaces close it, and they are different surfaces on purpose:
 *   - toArray(), the config dump, redacts via ConfigSection::$secretKeys;
 *   - __debugInfo(), for var_dump() and a consumer's own diagnostics;
 *   - DebugIntegration::SENSITIVE_KEYS, for Tracy, which reads properties by
 *     reflection and ignores __debugInfo() unless told otherwise.
 */
final class MailerConfigSecretTest extends TestCase
{
    private function config(): MailerConfig
    {
        return MailerConfig::fromArray([
            'smtp' => [
                'host' => 'smtp.example.test',
                'username' => 'app',
                'password' => 'super-secret-smtp',
                'encryption' => 'tls',
            ],
        ]);
    }

    public function testTheExportedArrayDoesNotCarryThePassword(): void
    {
        $export = $this->config()->toArray();

        self::assertSame(ConfigSection::REDACTED, $export['smtp']['password']);
        self::assertStringNotContainsString('super-secret-smtp', json_encode($export, JSON_THROW_ON_ERROR));
    }

    public function testTheTypedPropertyIsStillReadableByTheApplication(): void
    {
        // The mailer has to be able to authenticate; masking is about
        // RENDERING, never about the value the transport uses.
        self::assertSame('super-secret-smtp', $this->config()->smtpPassword);
    }

    public function testNeitherCopySurvivesADebugDump(): void
    {
        $dump = print_r($this->config()->__debugInfo(), true);

        self::assertStringNotContainsString('super-secret-smtp', $dump);
        self::assertStringContainsString(ConfigSection::REDACTED, $dump);
        self::assertStringContainsString('smtp.example.test', $dump);
    }

    public function testAnAbsentPasswordIsNotMadeToLookConfigured(): void
    {
        $debug = MailerConfig::fromArray(['smtp' => ['host' => 'localhost']])->__debugInfo();

        self::assertSame('', $debug['smtpPassword']);
    }
}
