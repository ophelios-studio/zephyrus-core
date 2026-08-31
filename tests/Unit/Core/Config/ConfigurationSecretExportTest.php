<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigSection;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Mailer\MailerConfig;

/**
 * toArray() is what a debug panel or a config dump renders, and it used to
 * render the two most damaging values in the process verbatim: the application
 * encryption key and the database password. Every custom section handed back
 * its raw backing array on top of that, so a section holding an API token
 * exported the token.
 *
 * Combined with the debugger serving its output to any client that could
 * trigger a 500, that is the whole leak: one anonymous request, every secret.
 */
final class ConfigurationSecretExportTest extends TestCase
{
    private function configuration(): Configuration
    {
        return Configuration::fromArray([
            'security' => ['encryption' => ['key' => 'GsJ0Rj7pQwZ4bH1kL9nV2cX8mA5tY6uE3dF0iO7sP4w=']],
            'database' => ['database' => 'appdb', 'username' => 'app', 'password' => 'prod-db-password'],
        ]);
    }

    /**
     * The behavioural statement, independent of how redaction is spelled: an
     * unqualified export must not carry a secret VALUE anywhere in it.
     */
    public function testNoSecretValueSurvivesAnUnqualifiedExport(): void
    {
        $json = json_encode($this->configuration()->toArray(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('GsJ0Rj7pQwZ4bH1kL9nV2cX8mA5tY6uE3dF0iO7sP4w=', $json);
        self::assertStringNotContainsString('prod-db-password', $json);
    }

    public function testTheEncryptionKeyIsNotExportedByDefault(): void
    {
        $export = $this->configuration()->toArray();

        self::assertSame(ConfigSection::REDACTED, $export['security']['encryptionKey']);
        self::assertStringNotContainsString(
            'GsJ0Rj7pQwZ4bH1kL9nV2cX8mA5tY6uE3dF0iO7sP4w=',
            json_encode($export, JSON_THROW_ON_ERROR),
        );
    }

    public function testTheDatabasePasswordIsNotExportedByDefault(): void
    {
        $export = $this->configuration()->toArray();

        self::assertSame(ConfigSection::REDACTED, $export['database']['password']);
        self::assertStringNotContainsString(
            'prod-db-password',
            json_encode($export, JSON_THROW_ON_ERROR),
        );
    }

    public function testEverythingElseIsStillExported(): void
    {
        $export = $this->configuration()->toArray();

        self::assertSame('appdb', $export['database']['database']);
        self::assertSame('app', $export['database']['username']);
        self::assertArrayHasKey('trustedProxies', $export['security']);
    }

    public function testTheRareCallerThatNeedsTheValuesAsksForThemExplicitly(): void
    {
        $export = $this->configuration()->toArray(revealSecrets: true);

        self::assertSame('GsJ0Rj7pQwZ4bH1kL9nV2cX8mA5tY6uE3dF0iO7sP4w=', $export['security']['encryptionKey']);
        self::assertSame('prod-db-password', $export['database']['password']);
    }

    /**
     * An unconfigured secret must keep reading as unconfigured. Masking a null
     * would make a tier with NO encryption key look like a tier that has one,
     * which is the opposite of what a diagnostic dump is for.
     */
    public function testAnAbsentSecretIsLeftAloneRatherThanMasked(): void
    {
        $export = Configuration::fromArray([
            'database' => ['database' => 'appdb', 'username' => 'app'],
        ])->toArray();

        self::assertNull($export['security']['encryptionKey']);
        self::assertSame('', $export['database']['password']);
    }

    public function testACustomSectionCanDeclareItsOwnSecrets(): void
    {
        $config = Configuration::fromArray(
            ['mailer' => ['smtp' => ['host' => 'smtp.example.test', 'password' => 'smtp-secret']]],
            ['mailer' => MailerConfig::class],
        );

        $export = $config->toArray();

        self::assertSame('smtp.example.test', $export['mailer']['smtp']['host']);
        self::assertSame(ConfigSection::REDACTED, $export['mailer']['smtp']['password']);
        self::assertStringNotContainsString('smtp-secret', json_encode($export, JSON_THROW_ON_ERROR));
    }

    public function testACustomSectionSecretIsRevealedWithTheRestWhenAsked(): void
    {
        $config = Configuration::fromArray(
            ['mailer' => ['smtp' => ['password' => 'smtp-secret']]],
            ['mailer' => MailerConfig::class],
        );

        self::assertSame('smtp-secret', $config->toArray(revealSecrets: true)['mailer']['smtp']['password']);
    }

    public function testASectionDeclaringNoSecretsIsUnchanged(): void
    {
        $section = new class(['host' => 'example.test']) extends ConfigSection {
        };

        self::assertSame(['host' => 'example.test'], $section->toArray());
    }

    /**
     * The nested leaf is snake_case, so each dotted SEGMENT has to be
     * normalized on its own; normalizing the whole string capitalises the leaf
     * and the redaction silently misses.
     */
    public function testASnakeCaseSecretLeafIsStillFound(): void
    {
        $section = new class(['api' => ['api_key' => 'live-key', 'base_url' => 'https://example.test']]) extends ConfigSection {
            protected array $secretKeys = ['api.api_key'];
        };

        $export = $section->toArray();

        self::assertSame(ConfigSection::REDACTED, $export['api']['apiKey']);
        self::assertSame('https://example.test', $export['api']['baseUrl']);
    }

    public function testADeclaredSecretThatIsNotPresentIsNotInvented(): void
    {
        $section = new class(['api' => ['baseUrl' => 'https://example.test']]) extends ConfigSection {
            protected array $secretKeys = ['api.token', 'missing.entirely'];
        };

        $export = $section->toArray();

        self::assertArrayNotHasKey('token', $export['api']);
        self::assertArrayNotHasKey('missing', $export);
    }
}
