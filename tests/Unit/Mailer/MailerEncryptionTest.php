<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Mailer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Mailer\Mailer;
use Zephyrus\Mailer\MailerConfig;

/**
 * smtp.encryption must not be able to silently disable transport security.
 *
 * PHPMailer compares SMTPSecure with a strict identity against 'tls' and 'ssl'
 * and nothing else. Any other value matched neither branch and fell through to
 * SMTPAutoTLS, PHPMailer's opportunistic STARTTLS. Opportunistic is the
 * dangerous word: a network attacker turns it off by simply not advertising
 * STARTTLS in the EHLO banner, and PHPMailer then authenticates in cleartext
 * without raising anything. Observed on the wire with a plaintext sink:
 *
 *   encryption: 'tls'       -> refused, no credentials on the socket
 *   encryption: 'STARTTLS'  -> DELIVERED, AUTH PLAIN in cleartext
 *   encryption: 'Tls'       -> DELIVERED, cleartext
 *   encryption: 'none'      -> DELIVERED, cleartext
 *
 * Nothing here sends anything. Every case builds the transport and inspects
 * it; no socket is opened and no address is contacted.
 */
final class MailerEncryptionTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function silentlyDowngradingValues(): array
    {
        return [
            "the RFC's own name for the mechanism" => ['STARTTLS'],
            'a reasonable way to say off' => ['none'],
            'a plausible synonym' => ['starttls'],
            'a typo' => ['tsl'],
            'a boolean an operator might expect to work' => ['true'],
        ];
    }

    #[DataProvider('silentlyDowngradingValues')]
    public function testAnUnrecognisedEncryptionValueIsRefusedAtConfigurationTime(string $value): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('smtp.encryption');

        MailerConfig::fromArray(['smtp' => ['encryption' => $value]]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function acceptedValues(): array
    {
        return [
            'tls' => ['tls', 'tls'],
            'ssl' => ['ssl', 'ssl'],
            'uppercase tls is normalized, not rejected' => ['TLS', 'tls'],
            'mixed case ssl is normalized' => ['Ssl', 'ssl'],
            'surrounding whitespace is trimmed' => ["  tls\n", 'tls'],
            'empty means no encryption' => ['', ''],
        ];
    }

    #[DataProvider('acceptedValues')]
    public function testAcceptedValuesAreNormalizedToWhatPhpMailerCompares(string $given, string $expected): void
    {
        $config = MailerConfig::fromArray(['smtp' => ['encryption' => $given]]);

        self::assertSame($expected, $config->smtpEncryption);
    }

    public function testTheDefaultIsStillTls(): void
    {
        self::assertSame('tls', MailerConfig::fromArray([])->smtpEncryption);
    }

    /**
     * An empty value has to MEAN none. Leaving SMTPAutoTLS on made '' the
     * "maybe" setting: encrypted in a friendly capture, plaintext against an
     * attacker, and identical in both cases from the application's side.
     */
    public function testAnEmptyEncryptionDisablesOpportunisticStartTls(): void
    {
        $mailer = new Mailer(MailerConfig::fromArray([
            'smtp' => ['host' => 'localhost', 'port' => 1025, 'encryption' => ''],
        ]));

        self::assertFalse($mailer->getPhpMailer()->SMTPAutoTLS);
    }

    public function testTlsLeavesTheAutoTlsFallbackAlone(): void
    {
        $mailer = new Mailer(MailerConfig::fromArray([
            'smtp' => ['host' => 'localhost', 'port' => 587, 'encryption' => 'tls'],
        ]));

        self::assertSame('tls', $mailer->getPhpMailer()->SMTPSecure);
        self::assertTrue($mailer->getPhpMailer()->SMTPAutoTLS);
    }

    public function testCredentialsOnAnUnencryptedTransportAreAnnouncedInTheErrorLog(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'zephyrus-mailer-plaintext-');
        self::assertIsString($logFile);

        $previousLog = ini_get('error_log');
        ini_set('error_log', $logFile);
        ini_set('log_errors', '1');

        // The warning fires at most once per process; reset the latch so this
        // test does not depend on execution order.
        (new \ReflectionProperty(Mailer::class, 'plaintextWarningEmitted'))->setValue(null, false);

        try {
            new Mailer(MailerConfig::fromArray([
                'smtp' => [
                    'host' => 'localhost',
                    'port' => 1025,
                    'username' => 'app',
                    'password' => 'not-a-real-secret',
                    'encryption' => '',
                ],
            ]));

            $written = (string) file_get_contents($logFile);
        } finally {
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
            @unlink($logFile);
        }

        self::assertStringContainsString(Mailer::PLAINTEXT_CREDENTIALS_WARNING, $written);
        self::assertStringNotContainsString('not-a-real-secret', $written);
    }

    public function testNoWarningWhenThereAreNoCredentialsToLeak(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'zephyrus-mailer-plaintext-');
        self::assertIsString($logFile);

        $previousLog = ini_get('error_log');
        ini_set('error_log', $logFile);
        ini_set('log_errors', '1');

        (new \ReflectionProperty(Mailer::class, 'plaintextWarningEmitted'))->setValue(null, false);

        try {
            new Mailer(MailerConfig::fromArray([
                'smtp' => ['host' => 'localhost', 'port' => 1025, 'encryption' => ''],
            ]));

            $written = (string) file_get_contents($logFile);
        } finally {
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
            @unlink($logFile);
        }

        self::assertSame('', trim($written));
    }
}
