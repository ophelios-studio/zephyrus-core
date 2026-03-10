<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ApplicationConfig;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\DatabaseConfig;
use Zephyrus\Core\Config\Environment;
use Zephyrus\Core\Config\LocalizationConfig;
use Zephyrus\Core\Config\SecurityConfig;
use Zephyrus\Core\Config\SessionConfig;

final class ConfigurationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // defaults() factory
    // -------------------------------------------------------------------------

    public function testDefaultsCreatesFullTreeWithNullDatabase(): void
    {
        $config = Configuration::defaults();

        self::assertInstanceOf(ApplicationConfig::class,  $config->application);
        self::assertInstanceOf(SessionConfig::class,      $config->session);
        self::assertInstanceOf(SecurityConfig::class,     $config->security);
        self::assertInstanceOf(LocalizationConfig::class, $config->localization);
        self::assertNull($config->database);
    }

    public function testDefaultsEquivalentToFromArrayEmpty(): void
    {
        $defaults = Configuration::defaults();
        $empty    = Configuration::fromArray([]);

        self::assertSame($defaults->application->environment,   $empty->application->environment);
        self::assertSame($defaults->session->name,              $empty->session->name);
        self::assertSame($defaults->security->csrfEnabled,      $empty->security->csrfEnabled);
        self::assertSame($defaults->localization->locale,        $empty->localization->locale);
        self::assertNull($empty->database);
    }

    // -------------------------------------------------------------------------
    // Application section
    // -------------------------------------------------------------------------

    public function testApplicationSectionIsHydrated(): void
    {
        $config = Configuration::fromArray([
            'application' => ['environment' => 'dev', 'debug' => true],
        ]);

        self::assertSame(Environment::Development, $config->application->environment);
        self::assertTrue($config->application->debug);
    }

    public function testMissingApplicationSectionUsesDefaults(): void
    {
        $config = Configuration::fromArray([]);

        self::assertSame(Environment::Production, $config->application->environment);
    }

    // -------------------------------------------------------------------------
    // Session section
    // -------------------------------------------------------------------------

    public function testSessionSectionIsHydrated(): void
    {
        $config = Configuration::fromArray([
            'session' => ['name' => 'MYAPP', 'sameSite' => 'Strict'],
        ]);

        self::assertSame('MYAPP', $config->session->name);
        self::assertSame('Strict', $config->session->sameSite);
    }

    public function testMissingSessionSectionUsesDefaults(): void
    {
        $config = Configuration::fromArray([]);

        self::assertSame('PHPSESSID', $config->session->name);
    }

    // -------------------------------------------------------------------------
    // Security section
    // -------------------------------------------------------------------------

    public function testSecuritySectionIsHydrated(): void
    {
        $config = Configuration::fromArray([
            'security' => [
                'forceHttps' => true,
                'csrfAutoHtml' => true,
                'csrfExceptions' => ['#^/hooks/#'],
                'allowedHosts' => ['example.com'],
            ],
        ]);

        self::assertTrue($config->security->forceHttps);
        self::assertTrue($config->security->csrfAutoHtml);
        self::assertSame(['#^/hooks/#'], $config->security->csrfExceptions);
        self::assertSame(['example.com'], $config->security->allowedHosts);
    }

    public function testMissingSecuritySectionUsesDefaults(): void
    {
        $config = Configuration::fromArray([]);

        self::assertFalse($config->security->forceHttps);
        self::assertTrue($config->security->csrfEnabled);
        self::assertFalse($config->security->csrfAutoHtml);
        self::assertSame([], $config->security->csrfExceptions);
    }

    // -------------------------------------------------------------------------
    // Localization section
    // -------------------------------------------------------------------------

    public function testLocalizationSectionIsHydrated(): void
    {
        $config = Configuration::fromArray([
            'localization' => [
                'locale' => 'fr',
                'supportedLocales' => ['fr', 'en'],
                'localePath' => '/app/locales',
                'timezone' => 'America/Montreal',
                'currency' => 'CAD',
            ],
        ]);

        self::assertSame('fr', $config->localization->locale);
        self::assertSame(['fr', 'en'], $config->localization->supportedLocales);
        self::assertSame('/app/locales', $config->localization->localePath);
        self::assertSame('America/Montreal', $config->localization->timezone);
        self::assertSame('CAD', $config->localization->currency);
    }

    public function testMissingLocalizationSectionUsesDefaults(): void
    {
        $config = Configuration::fromArray([]);

        self::assertSame('en', $config->localization->locale);
        self::assertSame([], $config->localization->supportedLocales);
        self::assertNull($config->localization->localePath);
        self::assertSame('UTC', $config->localization->timezone);
        self::assertNull($config->localization->currency);
    }

    // -------------------------------------------------------------------------
    // Database section
    // -------------------------------------------------------------------------

    public function testDatabaseSectionIsHydratedWhenPresent(): void
    {
        $config = Configuration::fromArray([
            'database' => ['database' => 'zephyrus', 'username' => 'root'],
        ]);

        self::assertInstanceOf(DatabaseConfig::class, $config->database);
        self::assertSame('zephyrus', $config->database->database);
        self::assertSame('root',     $config->database->username);
        self::assertSame('localhost', $config->database->host);
    }

    public function testDatabaseIsNullWhenSectionAbsent(): void
    {
        $config = Configuration::fromArray([
            'application' => ['environment' => 'production'],
        ]);

        self::assertNull($config->database);
    }

    // -------------------------------------------------------------------------
    // Full config array
    // -------------------------------------------------------------------------

    public function testAllSectionsHydratedTogether(): void
    {
        $config = Configuration::fromArray([
            'application' => ['environment' => 'production', 'debug' => false],
            'session'     => ['name' => 'APP', 'secure' => true],
            'security'    => [
                'forceHttps' => true,
                'csrfEnabled' => true,
                'csrfAutoHtml' => true,
                'csrfExceptions' => ['#^/webhooks/#'],
            ],
            'localization' => ['locale' => 'fr', 'supportedLocales' => ['fr', 'en']],
            'database'    => ['database' => 'mydb', 'username' => 'user', 'password' => 's3cr3t'],
        ]);

        self::assertSame(Environment::Production, $config->application->environment);
        self::assertFalse($config->application->debug);
        self::assertSame('APP',  $config->session->name);
        self::assertTrue($config->session->secure);
        self::assertTrue($config->security->forceHttps);
        self::assertTrue($config->security->csrfAutoHtml);
        self::assertSame(['#^/webhooks/#'], $config->security->csrfExceptions);
        self::assertSame('fr', $config->localization->locale);
        self::assertSame('mydb', $config->database->database);
        self::assertSame('s3cr3t', $config->database->password);
    }

    // -------------------------------------------------------------------------
    // Exception propagation
    // -------------------------------------------------------------------------

    public function testInvalidSessionSectionPropagatesException(): void
    {
        $this->expectException(ConfigurationException::class);

        Configuration::fromArray(['session' => ['sameSite' => 'Invalid']]);
    }

    public function testInvalidDatabaseSectionPropagatesException(): void
    {
        $this->expectException(ConfigurationException::class);

        Configuration::fromArray(['database' => ['username' => 'root']]);
    }

    public function testInvalidSecuritySectionPropagatesException(): void
    {
        $this->expectException(ConfigurationException::class);

        Configuration::fromArray(['security' => ['maxBodySize' => -100]]);
    }

    public function testInvalidLocalizationSectionPropagatesException(): void
    {
        $this->expectException(ConfigurationException::class);

        Configuration::fromArray(['localization' => ['locale' => '']]);
    }

    public function testFromFileLoadsAndHydratesConfiguration(): void
    {
        $path = sys_get_temp_dir() . '/zephyrus-config-' . uniqid('', true) . '.php';
        file_put_contents($path, "<?php\nreturn " . var_export([
            'localization' => ['locale' => 'fr'],
            'security' => ['forceHttps' => true],
        ], true) . ";\n");

        try {
            $config = Configuration::fromFile($path);
            self::assertSame('fr', $config->localization->locale);
            self::assertTrue($config->security->forceHttps);
        } finally {
            @unlink($path);
        }
    }

    public function testFromFileThrowsWhenFileMissing(): void
    {
        $this->expectException(ConfigurationException::class);

        Configuration::fromFile('/tmp/zephyrus-missing-' . uniqid('', true) . '.php');
    }

    public function testFromFileThrowsWhenPayloadIsNotArray(): void
    {
        $path = sys_get_temp_dir() . '/zephyrus-config-invalid-' . uniqid('', true) . '.php';
        file_put_contents($path, "<?php return 'bad';");

        try {
            $this->expectException(ConfigurationException::class);
            Configuration::fromFile($path);
        } finally {
            @unlink($path);
        }
    }

    public function testFromFilesMergesLaterFilesOverEarlierFiles(): void
    {
        $basePath = sys_get_temp_dir() . '/zephyrus-config-base-' . uniqid('', true) . '.php';
        $envPath = sys_get_temp_dir() . '/zephyrus-config-env-' . uniqid('', true) . '.php';

        file_put_contents($basePath, "<?php\nreturn " . var_export([
            'application' => ['environment' => 'production', 'debug' => false],
            'localization' => [
                'locale' => 'en',
                'supportedLocales' => ['en'],
                'localePath' => '/base/locales',
            ],
        ], true) . ";\n");

        file_put_contents($envPath, "<?php\nreturn " . var_export([
            'application' => ['debug' => true],
            'localization' => [
                'supportedLocales' => ['en', 'fr'],
                'localePath' => '/env/locales',
            ],
        ], true) . ";\n");

        try {
            $config = Configuration::fromFiles([$basePath, $envPath]);

            self::assertTrue($config->application->debug);
            self::assertSame('production', $config->application->environment->value);
            self::assertSame(['en', 'fr'], $config->localization->supportedLocales);
            self::assertSame('/env/locales', $config->localization->localePath);
        } finally {
            @unlink($basePath);
            @unlink($envPath);
        }
    }

    public function testToArrayExportsExpectedSectionKeys(): void
    {
        $config = Configuration::fromArray([
            'application' => ['environment' => 'production', 'debug' => false],
            'localization' => ['locale' => 'fr', 'supportedLocales' => ['fr']],
        ]);

        $export = $config->toArray();

        self::assertArrayHasKey('application', $export);
        self::assertArrayHasKey('session', $export);
        self::assertArrayHasKey('security', $export);
        self::assertArrayHasKey('localization', $export);
        self::assertArrayHasKey('database', $export);
        self::assertArrayHasKey('csrfAutoHtml', $export['security']);
        self::assertArrayHasKey('csrfExceptions', $export['security']);
        self::assertSame('fr', $export['localization']['locale']);
        self::assertArrayHasKey('timezone', $export['localization']);
        self::assertArrayHasKey('currency', $export['localization']);
    }

    public function testFromOptionalFilesSkipsMissingOverrides(): void
    {
        $basePath = sys_get_temp_dir() . '/zephyrus-config-optional-base-' . uniqid('', true) . '.php';
        file_put_contents($basePath, "<?php\nreturn " . var_export([
            'localization' => ['locale' => 'fr'],
        ], true) . ";\n");

        $missingPath = sys_get_temp_dir() . '/zephyrus-config-optional-missing-' . uniqid('', true) . '.php';

        try {
            $config = Configuration::fromOptionalFiles([$basePath, $missingPath]);
            self::assertSame('fr', $config->localization->locale);
        } finally {
            @unlink($basePath);
        }
    }

    public function testFromOptionalFilesStillThrowsOnExistingInvalidFile(): void
    {
        $path = sys_get_temp_dir() . '/zephyrus-config-optional-invalid-' . uniqid('', true) . '.php';
        file_put_contents($path, "<?php return 'bad';");

        try {
            $this->expectException(ConfigurationException::class);
            Configuration::fromOptionalFiles([$path]);
        } finally {
            @unlink($path);
        }
    }

    public function testFromFilesRejectsNonStringPathEntries(): void
    {
        $this->expectException(ConfigurationException::class);

        /** @phpstan-ignore-next-line */
        Configuration::fromFiles(['valid.php', 123]);
    }

    public function testFromFilesRejectsEmptyPathEntries(): void
    {
        $this->expectException(ConfigurationException::class);

        Configuration::fromFiles(['   ']);
    }

    public function testFromFilesDeDuplicatesDuplicatePaths(): void
    {
        $path = sys_get_temp_dir() . '/zephyrus-config-dedupe-' . uniqid('', true) . '.php';
        file_put_contents($path, "<?php\nreturn " . var_export([
            'localization' => ['locale' => 'fr'],
        ], true) . ";\n");

        try {
            $config = Configuration::fromFiles([$path, $path]);
            self::assertSame('fr', $config->localization->locale);
        } finally {
            @unlink($path);
        }
    }

    public function testFromFileWrapsThrownExceptionWithContext(): void
    {
        $path = sys_get_temp_dir() . '/zephyrus-config-throws-' . uniqid('', true) . '.php';
        file_put_contents($path, "<?php throw new RuntimeException('boom');");

        try {
            try {
                Configuration::fromFile($path);
                self::fail('Expected ConfigurationException was not thrown.');
            } catch (ConfigurationException $exception) {
                self::assertStringContainsString('failed to load', $exception->getMessage());
                self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
                self::assertSame('boom', $exception->getPrevious()?->getMessage());
            }
        } finally {
            @unlink($path);
        }
    }
}
