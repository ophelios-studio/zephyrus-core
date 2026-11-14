<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\ConfigurationFile;

final class ConfigurationFileTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    public function testParsesYamlFileSuccessfully(): void
    {
        $file = new ConfigurationFile($this->fixturesDir . '/test-config.yml');
        $data = $file->toArray();

        self::assertArrayHasKey('application', $data);
        self::assertArrayHasKey('database', $data);
        self::assertSame('development', $data['application']['environment']);
        self::assertTrue($data['application']['debug']);
    }

    public function testReadReturnsSpecificSection(): void
    {
        $file = new ConfigurationFile($this->fixturesDir . '/test-config.yml');

        $db = $file->read('database');
        self::assertIsArray($db);
        self::assertSame('localhost', $db['host']);
        self::assertSame(5432, $db['port']);
    }

    public function testReadReturnsNullForMissingSection(): void
    {
        $file = new ConfigurationFile($this->fixturesDir . '/test-config.yml');

        self::assertNull($file->read('nonexistent'));
    }

    public function testReadReturnsFullConfigWhenSectionIsNull(): void
    {
        $file = new ConfigurationFile($this->fixturesDir . '/test-config.yml');

        $full = $file->read(null);
        self::assertIsArray($full);
        self::assertArrayHasKey('application', $full);
    }

    public function testHasSectionWorks(): void
    {
        $file = new ConfigurationFile($this->fixturesDir . '/test-config.yml');

        self::assertTrue($file->hasSection('application'));
        self::assertTrue($file->hasSection('database'));
        self::assertFalse($file->hasSection('missing'));
    }

    public function testSectionsReturnsSectionNames(): void
    {
        $file = new ConfigurationFile($this->fixturesDir . '/test-config.yml');
        $sections = $file->sections();

        self::assertContains('application', $sections);
        self::assertContains('session', $sections);
        self::assertContains('security', $sections);
        self::assertContains('database', $sections);
    }

    public function testResolvesEnvTagWithFallback(): void
    {
        // Env variable not set, should use fallback
        unset($_ENV['TEST_DB_HOST'], $_SERVER['TEST_DB_HOST']);

        $file = new ConfigurationFile($this->fixturesDir . '/test-config-env.yml');
        $db = $file->read('database');

        self::assertSame('fallback-host', $db['host']);
        self::assertSame('default_user', $db['username']);
    }

    public function testResolvesEnvTagFromEnvironment(): void
    {
        $_ENV['TEST_DB_HOST'] = 'env-host.example.com';
        $_ENV['TEST_DB_NAME'] = 'env_database';

        try {
            // Force re-parse by creating a new instance
            $file = new ConfigurationFile($this->fixturesDir . '/test-config-env.yml');
            $db = $file->read('database');

            self::assertSame('env-host.example.com', $db['host']);
            self::assertSame('env_database', $db['database']);
        } finally {
            unset($_ENV['TEST_DB_HOST'], $_ENV['TEST_DB_NAME']);
        }
    }

    public function testResolvesEnvTagReturnsNullWhenNoDefault(): void
    {
        unset($_ENV['TEST_DB_NAME'], $_SERVER['TEST_DB_NAME']);

        $file = new ConfigurationFile($this->fixturesDir . '/test-config-env.yml');
        $db = $file->read('database');

        self::assertNull($db['database']);
    }

    public function testThrowsForMissingFile(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Configuration file not found');

        $file = new ConfigurationFile('/nonexistent/path/config.yml');
        $file->toArray();
    }

    public function testCachesResultAfterFirstParse(): void
    {
        $file = new ConfigurationFile($this->fixturesDir . '/test-config.yml');

        $first = $file->toArray();
        $second = $file->toArray();

        self::assertSame($first, $second);
    }

    public function testASyntaxErrorNamesTheLineAndNeverQuotesTheFile(): void
    {
        $directory = sys_get_temp_dir() . '/zephyrus-yaml-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $path = $directory . '/config.yml';
        file_put_contents($path, "application:\n  debug: false\nsmtp: { host: h, password <s3cret> }\n");

        try {
            Configuration::fromYamlFile($path);
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame(
                "Failed to parse configuration file [config.yml]: invalid YAML at line 3 (the parser's detail is withheld, since it can quote a secret from the file).",
                $e->getMessage(),
            );
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('s3cret', (string) $e);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testASyntaxErrorLeavesNoParserExceptionOrFileContentInTheTrace(): void
    {
        $directory = sys_get_temp_dir() . '/zephyrus-yaml-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $path = $directory . '/config.yml';
        file_put_contents($path, "application:\n  debug: false\n  password: ArrSecret7\nsmtp: { host: h\nport: 25\n");
        $previousSetting = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        try {
            (new ConfigurationFile($path))->toArray();
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            foreach ($e->getTrace() as $frame) {
                foreach ($frame['args'] ?? [] as $argument) {
                    self::assertNotInstanceOf(ParseException::class, $argument);
                }
            }
            self::assertStringNotContainsString('ArrSecret7', print_r($e->getTrace(), true));
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previousSetting);
            @unlink($path);
            @rmdir($directory);
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedEnvNames(): iterable
    {
        $equals = '!env tag at "a": the variable name is refused: names must not contain a NUL byte or "=".';
        yield 'equals sign' => ['s3cret=abc', 's3cret', $equals];
        yield 'base64 secret' => ['c2VjcmV0LWtleS0xMjM0NQ==', 'c2VjcmV0', $equals];
        yield 'nul byte' => ['"s3\\0cret"', 's3', $equals];
        yield 'request name' => [
            'PHP_AUTH_PW',
            'PHP_AUTH_PW',
            '!env tag at "a": the variable name is refused: can be set per request by the web server or PHP, '
            . 'so it is never read as configuration; rename the variable.',
        ];
        yield 'request prefix' => [
            'http_s3cret',
            's3cret',
            '!env tag at "a": the variable name is refused: names starting with HTTP_ can be set per request by '
            . 'the client request headers, so they are never read as configuration; rename the variable.',
        ];
    }

    #[DataProvider('refusedEnvNames')]
    public function testARefusedEnvNameIsNeverQuotedOrChained(string $written, string $secret, string $message): void
    {
        $directory = sys_get_temp_dir() . '/zephyrus-yaml-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $path = $directory . '/config.yml';
        file_put_contents($path, "a: !env $written\n");

        try {
            (new ConfigurationFile($path))->toArray();
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame($message, $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsStringIgnoringCase($secret, (string) $e);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }
}
