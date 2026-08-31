<?php

declare(strict_types=1);

namespace Tests\Unit\Localization;

use PHPUnit\Framework\TestCase;
use Zephyrus\Localization\JsonLocaleLoader;
use Zephyrus\Localization\LocalizationException;

final class JsonLocaleLoaderTest extends TestCase
{
    // -----------------------------------------------------------------
    // Single-file mode (backward compat: {basePath}/{locale}.json)
    // -----------------------------------------------------------------

    public function testLoadSingleFileReturnsNestedArray(): void
    {
        $loader = new JsonLocaleLoader(__DIR__ . '/../../Fixtures/locales');

        $catalog = $loader->load('en');

        self::assertSame('Welcome {name}', $catalog['messages']['welcome']);
        self::assertSame('The {field} field is required', $catalog['errors']['required']);
    }

    public function testLoadReturnsEmptyCatalogWhenFileAndDirectoryAreMissing(): void
    {
        $loader = new JsonLocaleLoader(__DIR__ . '/../../Fixtures/locales');

        self::assertSame([], $loader->load('es'));
    }

    public function testLoadSupportsUnderscoreLocaleFileFallback(): void
    {
        $tempDir = sys_get_temp_dir() . '/zephyrus-locales-' . uniqid('', true);
        mkdir($tempDir);
        file_put_contents($tempDir . '/fr_CA.json', '{"messages":{"welcome":"Bienvenue {name}"}}');

        $loader = new JsonLocaleLoader($tempDir);

        try {
            $catalog = $loader->load('fr-CA');
            self::assertSame('Bienvenue {name}', $catalog['messages']['welcome']);
        } finally {
            @unlink($tempDir . '/fr_CA.json');
            @rmdir($tempDir);
        }
    }

    public function testLoadSupportsLowercaseRegionLocaleFileFallback(): void
    {
        $tempDir = sys_get_temp_dir() . '/zephyrus-locales-' . uniqid('', true);
        mkdir($tempDir);
        file_put_contents($tempDir . '/fr_ca.json', '{"messages":{"welcome":"Salut {name}"}}');

        $loader = new JsonLocaleLoader($tempDir);

        try {
            $catalog = $loader->load('fr-CA');
            self::assertSame('Salut {name}', $catalog['messages']['welcome']);
        } finally {
            @unlink($tempDir . '/fr_ca.json');
            @rmdir($tempDir);
        }
    }

    public function testLoadThrowsWhenJsonIsInvalid(): void
    {
        $tempDir = sys_get_temp_dir() . '/zephyrus-locales-' . uniqid('', true);
        mkdir($tempDir);
        file_put_contents($tempDir . '/en.json', '{invalid');

        $loader = new JsonLocaleLoader($tempDir);

        try {
            $this->expectException(LocalizationException::class);
            $this->expectExceptionMessage('Invalid JSON in locale file');
            $loader->load('en');
        } finally {
            @unlink($tempDir . '/en.json');
            @rmdir($tempDir);
        }
    }

    public function testSingleFilePreservesNullValues(): void
    {
        $tempDir = sys_get_temp_dir() . '/zephyrus-locales-' . uniqid('', true);
        mkdir($tempDir);
        file_put_contents($tempDir . '/en.json', '{"messages":{"empty":null,"text":"hello"}}');

        $loader = new JsonLocaleLoader($tempDir);

        try {
            $catalog = $loader->load('en');
            self::assertNull($catalog['messages']['empty']);
            self::assertSame('hello', $catalog['messages']['text']);
        } finally {
            @unlink($tempDir . '/en.json');
            @rmdir($tempDir);
        }
    }

    public function testSingleFileReturnsNestedStructure(): void
    {
        $tempDir = sys_get_temp_dir() . '/zephyrus-locales-' . uniqid('', true);
        mkdir($tempDir);
        file_put_contents($tempDir . '/en.json', '{"obj":{"a":"val-a"},"plain":"hello"}');

        $loader = new JsonLocaleLoader($tempDir);

        try {
            $catalog = $loader->load('en');
            self::assertSame('val-a', $catalog['obj']['a']);
            self::assertSame('hello', $catalog['plain']);
            // 'obj' key exists as an intermediate array node
            self::assertIsArray($catalog['obj']);
        } finally {
            @unlink($tempDir . '/en.json');
            @rmdir($tempDir);
        }
    }

    // -----------------------------------------------------------------
    // Directory mode ({basePath}/{locale}/ with multiple *.json files)
    // -----------------------------------------------------------------

    public function testLoadDirectoryMergesMultipleJsonFiles(): void
    {
        $tempDir = sys_get_temp_dir() . '/zephyrus-locales-' . uniqid('', true);
        mkdir($tempDir . '/en', 0755, true);
        file_put_contents($tempDir . '/en/strings.json', '{"messages":{"welcome":"Hello"}}');
        file_put_contents($tempDir . '/en/errors.json', '{"errors":{"required":"Required"}}');

        $loader = new JsonLocaleLoader($tempDir);

        try {
            $catalog = $loader->load('en');
            self::assertSame('Hello', $catalog['messages']['welcome']);
            self::assertSame('Required', $catalog['errors']['required']);
        } finally {
            @unlink($tempDir . '/en/strings.json');
            @unlink($tempDir . '/en/errors.json');
            @rmdir($tempDir . '/en');
            @rmdir($tempDir);
        }
    }

    public function testLoadDirectoryRecursivelyFindsSubdirectoryFiles(): void
    {
        $tempDir = sys_get_temp_dir() . '/zephyrus-locales-' . uniqid('', true);
        mkdir($tempDir . '/en/admin', 0755, true);
        file_put_contents($tempDir . '/en/strings.json', '{"messages":{"hello":"Hello"}}');
        file_put_contents($tempDir . '/en/admin/users.json', '{"admin":{"users":{"title":"Users"}}}');

        $loader = new JsonLocaleLoader($tempDir);

        try {
            $catalog = $loader->load('en');
            self::assertSame('Hello', $catalog['messages']['hello']);
            self::assertSame('Users', $catalog['admin']['users']['title']);
        } finally {
            @unlink($tempDir . '/en/admin/users.json');
            @unlink($tempDir . '/en/strings.json');
            @rmdir($tempDir . '/en/admin');
            @rmdir($tempDir . '/en');
            @rmdir($tempDir);
        }
    }

    public function testLoadDirectoryMergesOverlappingKeys(): void
    {
        $tempDir = sys_get_temp_dir() . '/zephyrus-locales-' . uniqid('', true);
        mkdir($tempDir . '/en', 0755, true);
        file_put_contents($tempDir . '/en/a_base.json', '{"messages":{"hello":"Base Hello","bye":"Goodbye"}}');
        file_put_contents($tempDir . '/en/z_override.json', '{"messages":{"hello":"Override Hello"}}');

        $loader = new JsonLocaleLoader($tempDir);

        try {
            $catalog = $loader->load('en');
            self::assertSame('Override Hello', $catalog['messages']['hello']);
            self::assertSame('Goodbye', $catalog['messages']['bye']);
        } finally {
            @unlink($tempDir . '/en/a_base.json');
            @unlink($tempDir . '/en/z_override.json');
            @rmdir($tempDir . '/en');
            @rmdir($tempDir);
        }
    }

    public function testDirectoryModePreferredOverSingleFile(): void
    {
        $tempDir = sys_get_temp_dir() . '/zephyrus-locales-' . uniqid('', true);
        mkdir($tempDir . '/en', 0755, true);
        file_put_contents($tempDir . '/en/strings.json', '{"source":"directory"}');
        file_put_contents($tempDir . '/en.json', '{"source":"single-file"}');

        $loader = new JsonLocaleLoader($tempDir);

        try {
            $catalog = $loader->load('en');
            self::assertSame('directory', $catalog['source']);
        } finally {
            @unlink($tempDir . '/en/strings.json');
            @unlink($tempDir . '/en.json');
            @rmdir($tempDir . '/en');
            @rmdir($tempDir);
        }
    }

    public function testLoadDirectorySupportsUnderscoreLocale(): void
    {
        $tempDir = sys_get_temp_dir() . '/zephyrus-locales-' . uniqid('', true);
        mkdir($tempDir . '/fr_CA', 0755, true);
        file_put_contents($tempDir . '/fr_CA/strings.json', '{"greeting":"Bonjour"}');

        $loader = new JsonLocaleLoader($tempDir);

        try {
            $catalog = $loader->load('fr-CA');
            self::assertSame('Bonjour', $catalog['greeting']);
        } finally {
            @unlink($tempDir . '/fr_CA/strings.json');
            @rmdir($tempDir . '/fr_CA');
            @rmdir($tempDir);
        }
    }

    public function testLoadEmptyDirectoryReturnsEmptyCatalog(): void
    {
        $tempDir = sys_get_temp_dir() . '/zephyrus-locales-' . uniqid('', true);
        mkdir($tempDir . '/en', 0755, true);

        $loader = new JsonLocaleLoader($tempDir);

        try {
            self::assertSame([], $loader->load('en'));
        } finally {
            @rmdir($tempDir . '/en');
            @rmdir($tempDir);
        }
    }

    public function testLoadDirectoryThrowsOnInvalidJson(): void
    {
        $tempDir = sys_get_temp_dir() . '/zephyrus-locales-' . uniqid('', true);
        mkdir($tempDir . '/en', 0755, true);
        file_put_contents($tempDir . '/en/bad.json', '{invalid');

        $loader = new JsonLocaleLoader($tempDir);

        try {
            $this->expectException(LocalizationException::class);
            $this->expectExceptionMessage('Invalid JSON in locale file');
            $loader->load('en');
        } finally {
            @unlink($tempDir . '/en/bad.json');
            @rmdir($tempDir . '/en');
            @rmdir($tempDir);
        }
    }

    public function testLoadThrowsWhenJsonRootIsNotAnObject(): void
    {
        $tempDir = sys_get_temp_dir() . '/zephyrus-locales-' . uniqid('', true);
        mkdir($tempDir);
        file_put_contents($tempDir . '/en.json', '"just a string"');

        $loader = new JsonLocaleLoader($tempDir);

        try {
            $this->expectException(LocalizationException::class);
            $loader->load('en');
        } finally {
            @unlink($tempDir . '/en.json');
            @rmdir($tempDir);
        }
    }

    public function testLoadDirectoryIgnoresNonJsonFiles(): void
    {
        $tempDir = sys_get_temp_dir() . '/zephyrus-locales-' . uniqid('', true);
        mkdir($tempDir . '/en', 0755, true);
        file_put_contents($tempDir . '/en/strings.json', '{"hello":"world"}');
        file_put_contents($tempDir . '/en/readme.txt', 'not a json file');

        $loader = new JsonLocaleLoader($tempDir);

        try {
            $catalog = $loader->load('en');
            self::assertSame(['hello' => 'world'], $catalog);
        } finally {
            @unlink($tempDir . '/en/strings.json');
            @unlink($tempDir . '/en/readme.txt');
            @rmdir($tempDir . '/en');
            @rmdir($tempDir);
        }
    }

    // -----------------------------------------------------------------
    // The locale is a filesystem path, so it is untrusted input
    // -----------------------------------------------------------------

    public function testLoadRefusesALocaleThatTraversesOutOfTheBasePath(): void
    {
        $root = sys_get_temp_dir() . '/zephyrus-locale-traversal-' . uniqid('', true);
        mkdir($root . '/locale', 0755, true);
        mkdir($root . '/secrets', 0755, true);
        file_put_contents($root . '/secrets/en.json', '{"db_password":"S3CR3T-PROD-PASSWORD"}');

        $loader = new JsonLocaleLoader($root . '/locale');

        try {
            // "../secrets" carries no "-" so the Accept-Language normaliser
            // passes it through byte for byte.
            self::assertSame([], $loader->load('../secrets'));
            self::assertSame([], $loader->load('..'));
            self::assertSame([], $loader->load('/etc'));
        } finally {
            @unlink($root . '/secrets/en.json');
            @rmdir($root . '/secrets');
            @rmdir($root . '/locale');
            @rmdir($root);
        }
    }

    public function testLoadRefusesALocaleThatIsNotALanguageTag(): void
    {
        $root = sys_get_temp_dir() . '/zephyrus-locale-shape-' . uniqid('', true);
        mkdir($root, 0755, true);
        // A directory whose name is a traversal chain would otherwise be walked.
        file_put_contents($root . '/x.json', '{"a":"b"}');

        $loader = new JsonLocaleLoader($root);

        try {
            foreach ([str_repeat('../', 20), 'en/../../..', "en\0", '.', 'e', 'a_very_long_language'] as $hostile) {
                self::assertSame([], $loader->load($hostile), 'Refused: ' . addcslashes($hostile, "\0"));
            }
        } finally {
            @unlink($root . '/x.json');
            @rmdir($root);
        }
    }

    public function testWellFormedLocaleAcceptsRealTagsAndRejectsPaths(): void
    {
        self::assertTrue(JsonLocaleLoader::isWellFormedLocale('en'));
        self::assertTrue(JsonLocaleLoader::isWellFormedLocale('fr-CA'));
        self::assertTrue(JsonLocaleLoader::isWellFormedLocale('fr_CA'));
        self::assertTrue(JsonLocaleLoader::isWellFormedLocale('zh-Hant-TW'));

        self::assertFalse(JsonLocaleLoader::isWellFormedLocale('../storage/uploads'));
        self::assertFalse(JsonLocaleLoader::isWellFormedLocale('..'));
        self::assertFalse(JsonLocaleLoader::isWellFormedLocale('en.json'));
        self::assertFalse(JsonLocaleLoader::isWellFormedLocale(''));
    }

    public function testLoadRefusesACatalogDirectoryThatSymlinksOutOfTheBasePath(): void
    {
        $root = sys_get_temp_dir() . '/zephyrus-locale-symlink-' . uniqid('', true);
        mkdir($root . '/locale', 0755, true);
        mkdir($root . '/outside', 0755, true);
        file_put_contents($root . '/outside/strings.json', '{"leaked":"yes"}');
        symlink($root . '/outside', $root . '/locale/fr');

        $loader = new JsonLocaleLoader($root . '/locale');

        try {
            self::assertSame([], $loader->load('fr'));
        } finally {
            @unlink($root . '/locale/fr');
            @unlink($root . '/outside/strings.json');
            @rmdir($root . '/outside');
            @rmdir($root . '/locale');
            @rmdir($root);
        }
    }

    public function testUnreadableCatalogDirectoryIsWrappedWithoutLeakingTheServerPath(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Root ignores directory permissions.');
        }

        $root = sys_get_temp_dir() . '/zephyrus-locale-unreadable-' . uniqid('', true);
        mkdir($root . '/en', 0755, true);
        file_put_contents($root . '/en/strings.json', '{"a":"b"}');
        chmod($root . '/en', 0000);

        $loader = new JsonLocaleLoader($root);

        try {
            $loader->load('en');
            self::fail('An unreadable catalog directory must raise a LocalizationException.');
        } catch (LocalizationException $exception) {
            self::assertStringContainsString('en', $exception->getMessage());
            self::assertStringNotContainsString($root, $exception->getMessage());
            self::assertInstanceOf(\UnexpectedValueException::class, $exception->getPrevious());
        } finally {
            chmod($root . '/en', 0755);
            @unlink($root . '/en/strings.json');
            @rmdir($root . '/en');
            @rmdir($root);
        }
    }
}
