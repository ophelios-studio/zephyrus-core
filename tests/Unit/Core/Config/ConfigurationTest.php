<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ApplicationConfig;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\ConfigSection;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\DatabaseConfig;
use Zephyrus\Core\Config\Environment;
use Zephyrus\Core\Config\LocalizationConfig;
use Zephyrus\Core\Config\SecurityConfig;
use Zephyrus\Core\Config\SessionConfig;
use Zephyrus\Mailer\MailerConfig;

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
                'csrfAutoHtml' => false,
                'csrfExceptions' => ['#^/hooks/#'],
                'allowedHosts' => ['example.com'],
            ],
        ]);

        self::assertTrue($config->security->forceHttps);
        self::assertFalse($config->security->csrfAutoHtml);
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
                'csrfAutoHtml' => false,
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
        self::assertFalse($config->security->csrfAutoHtml);
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
        $path = sys_get_temp_dir() . '/zephyrus-config-' . bin2hex(random_bytes(8)) . '.php';
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

        Configuration::fromFile('/tmp/zephyrus-missing-' . bin2hex(random_bytes(8)) . '.php');
    }

    public function testFromFileThrowsWhenPayloadIsNotArray(): void
    {
        $path = sys_get_temp_dir() . '/zephyrus-config-invalid-' . bin2hex(random_bytes(8)) . '.php';
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
        $basePath = sys_get_temp_dir() . '/zephyrus-config-base-' . bin2hex(random_bytes(8)) . '.php';
        $envPath = sys_get_temp_dir() . '/zephyrus-config-env-' . bin2hex(random_bytes(8)) . '.php';

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
        self::assertArrayNotHasKey('csrfAutoHtml', $export['security']);
        self::assertArrayHasKey('csrfExceptions', $export['security']);
        self::assertSame('fr', $export['localization']['locale']);
        self::assertArrayHasKey('timezone', $export['localization']);
        self::assertArrayHasKey('currency', $export['localization']);
    }

    /**
     * toArray() is what a debug panel or a config dump renders. It must not keep
     * advertising a knob the framework removed: a reader seeing
     * `emulate_prepares: false` would reasonably conclude it could be set to true.
     */
    public function testToArrayNoLongerExportsTheRemovedEmulatePreparesKey(): void
    {
        $config = Configuration::fromArray([
            'database' => ['database' => 'db', 'username' => 'u'],
        ]);

        $export = $config->toArray();

        self::assertIsArray($export['database']);
        self::assertArrayNotHasKey('emulate_prepares', $export['database']);
        self::assertArrayNotHasKey('emulatePrepares', $export['database']);
    }

    public function testFromOptionalFilesSkipsMissingOverrides(): void
    {
        $basePath = sys_get_temp_dir() . '/zephyrus-config-optional-base-' . bin2hex(random_bytes(8)) . '.php';
        file_put_contents($basePath, "<?php\nreturn " . var_export([
            'localization' => ['locale' => 'fr'],
        ], true) . ";\n");

        $missingPath = sys_get_temp_dir() . '/zephyrus-config-optional-missing-' . bin2hex(random_bytes(8)) . '.php';

        try {
            $config = Configuration::fromOptionalFiles([$basePath, $missingPath]);
            self::assertSame('fr', $config->localization->locale);
        } finally {
            @unlink($basePath);
        }
    }

    public function testFromOptionalFilesStillThrowsOnExistingInvalidFile(): void
    {
        $path = sys_get_temp_dir() . '/zephyrus-config-optional-invalid-' . bin2hex(random_bytes(8)) . '.php';
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
        $path = sys_get_temp_dir() . '/zephyrus-config-dedupe-' . bin2hex(random_bytes(8)) . '.php';
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
        $path = sys_get_temp_dir() . '/zephyrus-config-throws-' . bin2hex(random_bytes(8)) . '.php';
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

    public function testSectionRefusesEveryBuiltInNameAndNamesTheTypedProperty(): void
    {
        $config = Configuration::fromArray(['database' => ['database' => 'zephyrus', 'username' => 'root']]);

        foreach (['application', 'session', 'security', 'localization', 'database'] as $name) {
            try {
                $config->section($name);
                self::fail("section('$name') should refuse a built-in name");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('$configuration->' . $name, $e->getMessage());
            }
        }
    }

    public function testSectionRefusesAnUnconfiguredBuiltInName(): void
    {
        $config = Configuration::fromArray([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$configuration->database');

        $config->section('database');
    }

    public function testHasSectionStaysFalseForBuiltInNames(): void
    {
        $config = Configuration::fromArray(['database' => ['database' => 'zephyrus', 'username' => 'root']]);

        self::assertFalse($config->hasSection('database'));
        self::assertFalse($config->hasSection('application'));
    }

    public function testFromArrayRefusesFactoryRegisteredUnderBuiltInNameEvenWithoutConfiguredValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('database');

        Configuration::fromArray([], ['database' => MailerConfig::class]);
    }

    public function testFromArrayNamesTheBuiltInSectionAFactoryCollidesWith(): void
    {
        try {
            Configuration::fromArray([], ['_database' => MailerConfig::class]);
            self::fail('A factory keyed by a spelling of a built-in name must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('"_database" collides with the built-in section "database"', $e->getMessage());
            self::assertStringContainsString('$configuration->database', $e->getMessage());
        }
    }

    public function testFromArrayRefusesAFactoryKeyedByListIndexNamingTheClassAndTheFix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Section factory MailerConfig at index 0 must be keyed by its section name, for example ['mailer' => MailerConfig::class].",
        );

        Configuration::fromArray([], [MailerConfig::class]);
    }

    public function testFromArrayRefusesAFactoryWithANonStringValueAtAListIndex(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Section factory at index 0 must be keyed by its section name.');

        Configuration::fromArray([], [new \stdClass()]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankFactoryNames(): iterable
    {
        yield 'empty string' => [''];
        yield 'spaces' => ['   '];
        yield 'tab and newline' => ["\t\n"];
    }

    #[DataProvider('blankFactoryNames')]
    public function testFromArrayRefusesABlankFactoryNameWithAnExample(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("['mailer' => MailerConfig::class]");

        Configuration::fromArray([], [$name => MailerConfig::class]);
    }

    public function testFromArrayRefusesAFactoryNamedAsABuiltInInAnotherCase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"Security" collides with the built-in section "security"');

        Configuration::fromArray([], ['Security' => MailerConfig::class]);
    }

    public function testSectionRefusesABuiltInNameInAnotherCase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$configuration->database');

        Configuration::fromArray([])->section('Database');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function misspelledBuiltInKeys(): iterable
    {
        yield 'capitalised' => ['Database', 'database'];
        yield 'upper case' => ['SECURITY', 'security'];
        yield 'underscore inside' => ['Data_base', 'database'];
    }

    #[DataProvider('misspelledBuiltInKeys')]
    public function testFromArrayRefusesATopLevelKeyThatMisspellsABuiltInSection(string $key, string $suggestion): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            sprintf("Configuration section '%s' is not recognised: did you mean '%s'?", $key, $suggestion),
        );

        Configuration::fromArray([$key => []]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function customSectionSpellings(): iterable
    {
        yield 'file snake_case, factory camelCase' => ['payment_gateway', 'paymentGateway'];
        yield 'file camelCase, factory snake_case' => ['paymentGateway', 'payment_gateway'];
    }

    #[DataProvider('customSectionSpellings')]
    public function testFactoryReadsTheCustomSectionWrittenUnderAnotherSpelling(string $key, string $factoryName): void
    {
        $config = Configuration::fromArray(
            [$key => ['name' => 'stripe']],
            [$factoryName => FactoryNameSectionConfig::class],
        );

        $section = $config->section('paymentGateway');
        self::assertInstanceOf(FactoryNameSectionConfig::class, $section);
        self::assertSame('stripe', $section->getString('name'));
    }

    public function testFromArrayRefusesACustomSectionWrittenTwiceInTwoSpellings(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("'payment_gateway' and 'paymentGateway': keep one");

        Configuration::fromArray(
            ['payment_gateway' => ['name' => 'a'], 'paymentGateway' => ['name' => 'b']],
            ['paymentGateway' => FactoryNameSectionConfig::class],
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unnamedFactoryExamples(): iterable
    {
        yield 'two words' => ['App\Billing\PaymentGatewayConfig', "['payment_gateway' => PaymentGatewayConfig::class]"];
        yield 'acronym' => ['App\HTTPClientConfig', "['http_client' => HTTPClientConfig::class]"];
    }

    #[DataProvider('unnamedFactoryExamples')]
    public function testUnnamedFactoryMessageSuggestsTheSnakeCaseKey(string $className, string $example): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($example);

        Configuration::fromArray([], [$className]);
    }

    public function testFromArrayRefusesAFactoryWhoseNameFoldsOntoABuiltInThroughSpaces(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('collides with the built-in section "database"');

        Configuration::fromArray(
            ['database' => ['database' => 'app', 'username' => 'root', 'password' => 's3cr3t']],
            ['database _' => FactoryNameSectionConfig::class],
        );
    }

    public function testToArrayNeverLetsACustomSectionOverwriteABuiltInEntry(): void
    {
        $config = new Configuration(
            application: ApplicationConfig::fromArray([]),
            session: SessionConfig::fromArray([]),
            security: SecurityConfig::fromArray([]),
            localization: LocalizationConfig::fromArray([]),
            database: DatabaseConfig::fromArray(['database' => 'app', 'username' => 'root', 'password' => 's3cr3t']),
            customSections: ['database' => FactoryNameSectionConfig::fromArray(['password' => 's3cr3t'])],
        );

        $exported = $config->toArray();

        self::assertIsArray($exported['database']);
        self::assertSame(ConfigSection::REDACTED, $exported['database']['password']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function normalisedBlankFactoryNames(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['  '];
        yield 'underscores' => ['__'];
        yield 'single underscore' => ['_'];
        yield 'underscores and spaces' => ['_ _'];
    }

    #[DataProvider('normalisedBlankFactoryNames')]
    public function testFromArrayRefusesAFactoryNameThatIsBlankOnceNormalised(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('has an empty section name');

        Configuration::fromArray(['_' => ['a' => 1]], [$name => FactoryNameSectionConfig::class]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function caseVariantsOfACustomSection(): iterable
    {
        yield 'file capitalised' => ['Payment', 'payment'];
        yield 'file snake_case, factory PascalCase' => ['payment_gateway', 'PaymentGateway'];
        yield 'file upper case' => ['PAYMENT', 'payment'];
    }

    #[DataProvider('caseVariantsOfACustomSection')]
    public function testFromArrayRefusesACaseVariantOfACustomSectionName(string $key, string $factoryName): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            sprintf("Configuration section '%s' is not recognised: did you mean '%s'?", $key, $factoryName),
        );

        Configuration::fromArray([$key => ['name' => 'x']], [$factoryName => FactoryNameSectionConfig::class]);
    }

    public function testFromYamlFilesRefusesACaseVariantOfACustomSectionAfterTheMerge(): void
    {
        $firstBase = tempnam(sys_get_temp_dir(), 'zcfg');
        $secondBase = tempnam(sys_get_temp_dir(), 'zcfg');
        $first = $firstBase . '.yml';
        $second = $secondBase . '.yml';
        file_put_contents($first, "payment:\n  name: a\n");
        file_put_contents($second, "Payment:\n  name: b\n");

        try {
            $this->expectException(ConfigurationException::class);
            $this->expectExceptionMessage("did you mean 'payment'?");

            Configuration::fromYamlFiles([$first, $second], ['payment' => FactoryNameSectionConfig::class]);
        } finally {
            @unlink($firstBase);
            @unlink($first);
            @unlink($secondBase);
            @unlink($second);
        }
    }

    public function testFromArrayAllowsATopLevelKeyMatchingNoRegisteredFactory(): void
    {
        $config = Configuration::fromArray(
            ['Other' => ['a' => 1], 'payment' => ['name' => 'x']],
            ['payment' => FactoryNameSectionConfig::class],
        );

        self::assertTrue($config->hasSection('payment'));
    }

    public function testSectionAdviceForABuiltInNameAlsoPointsToTheConfigHelper(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("or config('database') instead");

        Configuration::fromArray([])->section('Database');
    }
}

/**
 * Minimal custom section used by the factory name tests.
 */
final class FactoryNameSectionConfig extends ConfigSection
{
}
