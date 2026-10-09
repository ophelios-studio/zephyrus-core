<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tracy\Debugger;
use Zephyrus\Core\Config\ConfigSection;
use Zephyrus\Core\DebugIntegration;
use Zephyrus\Data\DatabaseException;

final class DebugIntegrationTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testInitializeWithDebugTrueEnablesTracyDevelopmentMode(): void
    {
        if (!class_exists(Debugger::class)) {
            $this->markTestSkipped('Tracy not installed.');
        }

        DebugIntegration::initialize(debug: true);

        self::assertTrue(Debugger::isEnabled());
    }

    #[RunInSeparateProcess]
    public function testInitializeWithLogDirectory(): void
    {
        if (!class_exists(Debugger::class)) {
            $this->markTestSkipped('Tracy not installed.');
        }

        $logDir = sys_get_temp_dir() . '/zephyrus-tracy-test-' . bin2hex(random_bytes(8));
        mkdir($logDir, 0755, true);

        try {
            DebugIntegration::initialize(debug: true, logDirectory: $logDir);
            self::assertTrue(Debugger::isEnabled());
        } finally {
            @rmdir($logDir);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDebugFalseTellsTracyItIsInProduction(): void
    {
        DebugIntegration::initialize(debug: false);

        self::assertTrue(Debugger::$productionMode);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLoopbackAfterADebugOffBootGetsTheDebuggerAgain(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        DebugIntegration::initialize(debug: false);
        DebugIntegration::initialize(debug: true);

        self::assertFalse(Debugger::$productionMode, 'Tracy must re-detect the client once productionMode was set by a debug-off boot.');
    }

    /**
     * Tracy writes dump() straight to STDOUT in CLI, past any output buffer,
     * so the output can only be observed from a child process. The sentinel
     * proves the child ran to the end, so a crash cannot pass as silence.
     */
    public function testDumpStaysSilentWhenDebugIsFalse(): void
    {
        $stdout = $this->runChildProcess(
            "Zephyrus\\Core\\DebugIntegration::initialize(debug: false);"
            . " Tracy\\Debugger::dump('secret-value');"
            . " echo 'SENTINEL';",
        );

        self::assertSame('SENTINEL', $stdout);
    }

    #[DataProvider('frameworkSecretKeyProvider')]
    public function testDumpMasksFrameworkSecretKeysWhenDebugIsOn(string $key): void
    {
        $secret = self::marker('secret');

        $stdout = $this->runChildProcess(
            '$_SERVER["REMOTE_ADDR"] = "127.0.0.1";'
            . " Zephyrus\\Core\\DebugIntegration::initialize(debug: true);"
            . ' Tracy\\Debugger::dump([' . var_export($key, true) . ' => ' . var_export($secret, true) . ', "username" => "visible-user"]);'
            . " echo 'SENTINEL';",
        );

        self::assertTrue(str_contains($stdout, 'visible-user'), 'Control: an unlisted key must still be dumped.');
        self::assertFalse(str_contains($stdout, $secret), 'dump() rendered the value of a masked key.');
        self::assertTrue(str_ends_with($stdout, 'SENTINEL'), 'The child process did not finish.');
    }

    public function testDumpMasksTheConfiguredSessionCookie(): void
    {
        $secret = self::marker('session');

        $stdout = $this->runChildProcess(
            '$_SERVER["REMOTE_ADDR"] = "127.0.0.1";'
            . " Zephyrus\\Core\\DebugIntegration::initialize(debug: true, sessionName: 'app_session');"
            . ' $_COOKIE = ["app_session" => ' . var_export($secret, true) . ', "username" => "visible-user"];'
            . ' Tracy\\Debugger::dump($_COOKIE);'
            . " echo 'SENTINEL';",
        );

        self::assertTrue(str_contains($stdout, 'visible-user'), 'Control: an unlisted key must still be dumped.');
        self::assertFalse(str_contains($stdout, $secret), 'dump() rendered the configured session cookie.');
    }

    public function testDumpMasksTheRawRequestBody(): void
    {
        $raw = self::marker('body');

        $stdout = $this->runChildProcess(
            '$_SERVER["REMOTE_ADDR"] = "127.0.0.1";'
            . " Zephyrus\\Core\\DebugIntegration::initialize(debug: true);"
            . ' Tracy\\Debugger::dump(new Zephyrus\\Http\\RequestBody([], ' . var_export($raw, true) . '));'
            . " echo 'SENTINEL';",
        );

        self::assertTrue(str_contains($stdout, 'RequestBody'), 'Control: the object must still be dumped.');
        self::assertFalse(str_contains($stdout, $raw), 'dump() rendered the raw request body.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sensitiveHeaderProvider(): iterable
    {
        $names = ['Cookie', 'Set-Cookie', 'Authorization', 'Proxy-Authorization', 'X-CSRF-Token', 'X-XSRF-Token', 'X-API-Key', 'X-Auth-Token', 'Tracy-Debug'];

        foreach ($names as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('sensitiveHeaderProvider')]
    public function testDumpMasksSensitiveRequestHeaders(string $name): void
    {
        $secret = self::marker('header');

        $stdout = $this->runChildProcess(
            '$_SERVER["REMOTE_ADDR"] = "127.0.0.1";'
            . " Zephyrus\\Core\\DebugIntegration::initialize(debug: true);"
            . ' $request = Zephyrus\\Http\\Request::fromArray("GET", "/", headers: ['
            . var_export($name, true) . ' => ' . var_export($secret, true) . ', "X-Visible" => "visible-header"]);'
            . ' Tracy\\Debugger::dump($request);'
            . " echo 'SENTINEL';",
        );

        self::assertTrue(str_contains($stdout, 'visible-header'), 'Control: an unlisted header must still be dumped.');
        self::assertFalse(str_contains($stdout, $secret), 'dump() rendered the value of a sensitive header.');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIsSensitiveKeyHonoursTheApplicationSessionName(): void
    {
        DebugIntegration::initialize(debug: true, sessionName: 'app_session');

        self::assertTrue(DebugIntegration::isSensitiveKey('APP_SESSION'));
        self::assertFalse(DebugIntegration::isSensitiveKey('app_session_other'));
    }

    /**
     * @return string the child's stdout
     */
    private function runChildProcess(string $body): string
    {
        $autoload = var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true);
        $code = 'require ' . $autoload . '; ' . $body;
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), 'The child process failed: ' . $stderr);

        return $stdout;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function frameworkSecretKeyProvider(): iterable
    {
        $keys = ['password', 'passwordPepper', 'password_pepper', 'pepper', 'throttleKey', 'throttle_key', '_csrf_token'];

        foreach ($keys as $key) {
            yield $key => [$key];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function patternOnlySecretKeyProvider(): iterable
    {
        $keys = ['stripeWebhookSecret', 'mailgunApiKey', 'api-key', 'sessionPassphrase', 'oauthCredential', 'refresh_token', 'sshPrivateKey', 'HTTP_AUTHORIZATION', 'PHP_AUTH_PW', 'HTTP_COOKIE', 'APP_THROTTLE_KEY', 'tracy-debug'];

        foreach ($keys as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('frameworkSecretKeyProvider')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBlueScreenMasksFrameworkSecretKeys(string $key): void
    {
        $secret = self::marker('secret');
        $html = $this->renderBlueScreenWithArgument([$key => $secret, 'username' => self::marker('visible')]);

        self::assertFalse(str_contains($html, $secret), 'A secret key rendered its value.');
    }

    #[DataProvider('patternOnlySecretKeyProvider')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBlueScreenMasksSecretKeysByPatternAlone(string $key): void
    {
        $secret = self::marker('secret');
        $html = $this->renderBlueScreenWithArgument([$key => $secret, 'username' => self::marker('visible')]);

        self::assertFalse(str_contains($html, $secret), 'A key matching the secret pattern rendered its value.');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBlueScreenMasksTheDatabaseDriverMessage(): void
    {
        DebugIntegration::initialize(debug: true);
        $email = 'jane-' . self::marker('email') . '@example.com';
        $driverMessage = 'SQLSTATE[23505]: DETAIL: Key (email)=(' . $email . ') already exists.';

        $exception = DatabaseException::queryExecutionFailed('insert into users', new \PDOException($driverMessage));
        $file = sys_get_temp_dir() . '/zephyrus-bluescreen-' . bin2hex(random_bytes(8)) . '.html';

        try {
            Debugger::getBlueScreen()->renderToFile($exception, $file);
            $html = (string) file_get_contents($file);
        } finally {
            @unlink($file);
        }

        self::assertFalse(str_contains($html, $email), 'The bluescreen rendered the driver message with a column value.');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBlueScreenKeepsScalarValuesOfPatternKeysVisible(): void
    {
        $html = $this->renderBlueScreenWithArgument([
            'passwordMinLength' => 424242,
            'tokenTtl' => 1.5,
            'passwordResetEnabled' => true,
            'apiKeyHint' => null,
        ]);

        self::assertTrue(str_contains($html, '["passwordMinLength",424242]'), 'A pattern key masked an int value.');
        self::assertTrue(str_contains($html, '["tokenTtl",1.5]'), 'A pattern key masked a float value.');
        self::assertTrue(str_contains($html, '["passwordResetEnabled",true]'), 'A pattern key masked a bool value.');
        self::assertTrue(str_contains($html, '["apiKeyHint",null]'), 'A pattern key masked a null value.');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBlueScreenMasksArraysAndObjectsOfPatternKeys(): void
    {
        $token = self::marker('tokens');
        $objectSecret = self::marker('apikeys');
        $html = $this->renderBlueScreenWithArgument([
            'tokens' => [$token],
            'apiKeys' => (object) ['stripe' => $objectSecret],
        ]);

        self::assertFalse(str_contains($html, $token), 'A pattern key rendered an array value.');
        self::assertFalse(str_contains($html, $objectSecret), 'A pattern key rendered an object value.');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBlueScreenMasksArrayValuesOfExactSecretKeys(): void
    {
        $secret = self::marker('secret');
        $html = $this->renderBlueScreenWithArgument(['password' => ['nested' => $secret]]);

        self::assertFalse(str_contains($html, $secret), 'An exact secret key rendered its array value.');
    }

    public function testDumpRendersConfigSectionSecretsRedacted(): void
    {
        $secret = self::marker('section');

        $stdout = $this->runChildProcess(
            '$_SERVER["REMOTE_ADDR"] = "127.0.0.1";'
            . " Zephyrus\\Core\\DebugIntegration::initialize(debug: true);"
            . ' $section = new class(["api" => ["signing" => ' . var_export($secret, true) . ', "host" => "visible-host"]]) extends Zephyrus\\Core\\Config\\ConfigSection { protected array $secretKeys = ["api.signing"]; };'
            . ' Tracy\\Debugger::dump($section);'
            . " echo 'SENTINEL';",
        );

        self::assertTrue(str_contains($stdout, 'visible-host'), 'Control: an unlisted value must still be dumped.');
        self::assertTrue(str_contains($stdout, ConfigSection::REDACTED), 'dump() did not redact the section secret.');
        self::assertFalse(str_contains($stdout, $secret), 'dump() rendered a config section secret.');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBlueScreenRendersConfigSectionSecretsRedacted(): void
    {
        $secret = self::marker('section');
        $section = new class(['api' => ['signing' => $secret, 'host' => 'visible-host']]) extends ConfigSection {
            protected array $secretKeys = ['api.signing'];
        };

        $html = $this->renderBlueScreenWithArgument(['section' => $section]);

        self::assertTrue(str_contains($html, 'visible-host'), 'Control: an unlisted value must still render.');
        self::assertTrue(str_contains($html, ConfigSection::REDACTED), 'The bluescreen did not redact the section secret.');
        self::assertFalse(str_contains($html, $secret), 'The bluescreen rendered a config section secret.');
    }

    public function testDumpMasksUndeclaredConfigSectionSecrets(): void
    {
        $password = self::marker('password');
        $token = self::marker('token');
        $apiKey = self::marker('apikey');

        $stdout = $this->runChildProcess(
            '$_SERVER["REMOTE_ADDR"] = "127.0.0.1";'
            . " Zephyrus\\Core\\DebugIntegration::initialize(debug: true);"
            . ' $section = new class(["password" => ' . var_export($password, true)
            . ', "token" => ' . var_export($token, true)
            . ', "apiKey" => ' . var_export($apiKey, true)
            . ', "host" => "visible-host"]) extends Zephyrus\\Core\\Config\\ConfigSection {};'
            . ' Tracy\\Debugger::dump($section);'
            . " echo 'SENTINEL';",
        );

        self::assertTrue(str_contains($stdout, 'visible-host'), 'Control: an unlisted value must still be dumped.');
        self::assertFalse(str_contains($stdout, $password), 'dump() rendered an undeclared config section password.');
        self::assertFalse(str_contains($stdout, $token), 'dump() rendered an undeclared config section token.');
        self::assertFalse(str_contains($stdout, $apiKey), 'dump() rendered an undeclared config section api key.');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBlueScreenMasksUndeclaredConfigSectionSecrets(): void
    {
        $password = self::marker('password');
        $token = self::marker('token');
        $apiKey = self::marker('apikey');
        $dbPassword = self::marker('dbpassword');
        $section = new class(['password' => $password, 'token' => $token, 'apiKey' => $apiKey, 'dbPassword' => $dbPassword, 'host' => 'visible-host']) extends ConfigSection {};

        $html = $this->renderBlueScreenWithArgument(['section' => $section]);

        self::assertTrue(str_contains($html, 'visible-host'), 'Control: an unlisted value must still render.');
        self::assertFalse(str_contains($html, $password), 'The bluescreen rendered an undeclared config section password.');
        self::assertFalse(str_contains($html, $token), 'The bluescreen rendered an undeclared config section token.');
        self::assertFalse(str_contains($html, $apiKey), 'The bluescreen rendered an undeclared config section api key.');
        self::assertFalse(str_contains($html, $dbPassword), 'The bluescreen rendered an undeclared config section pattern-only password.');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBlueScreenOmitsTheEnvironmentSection(): void
    {
        $_SERVER['SERVER_NAME'] = self::marker('visible');

        $html = $this->renderBlueScreenWithArgument([]);

        self::assertFalse(str_contains($html, $_SERVER['SERVER_NAME']), 'The environment table was rendered.');
        self::assertFalse(str_contains($html, '>Environment</a>'), 'The environment section heading was rendered.');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRepeatedInitializeDoesNotStackScrubbers(): void
    {
        DebugIntegration::initialize(debug: true);
        $first = Debugger::getBlueScreen()->scrubber;

        DebugIntegration::initialize(debug: true);

        self::assertSame($first, Debugger::getBlueScreen()->scrubber);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBlueScreenStillRendersOrdinaryKeys(): void
    {
        $visible = self::marker('visible');
        $html = $this->renderBlueScreenWithArgument(['username' => $visible]);

        self::assertTrue(str_contains($html, $visible), 'Control: an unlisted key must still render.');
    }

    public function testIsSensitiveKeyStripsTheDollarPrefixAndIgnoresCase(): void
    {
        self::assertTrue(DebugIntegration::isSensitiveKey('$stripeSecret'));
        self::assertTrue(DebugIntegration::isSensitiveKey('STRIPE_SECRET'));
        self::assertFalse(DebugIntegration::isSensitiveKey('$'));
        self::assertFalse(DebugIntegration::isSensitiveKey(''));
    }

    public function testIsSensitiveKeyMatchesEveryExactNameInTheList(): void
    {
        foreach (DebugIntegration::SENSITIVE_KEYS as $key) {
            self::assertTrue(DebugIntegration::isSensitiveKey($key), $key);
            self::assertTrue(DebugIntegration::isSensitiveKey(strtoupper($key)), strtoupper($key));
        }
    }

    public function testIsSensitiveKeyLeavesOrdinaryNamesAlone(): void
    {
        self::assertFalse(DebugIntegration::isSensitiveKey('username'));
        self::assertFalse(DebugIntegration::isSensitiveKey('0'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testApplicationScrubberIsComposedNotReplaced(): void
    {
        $appOnly = self::marker('app');
        $pattern = self::marker('pattern');
        Debugger::getBlueScreen()->scrubber = static fn (string $key): bool => $key === 'appOnlyKey';

        $html = $this->renderBlueScreenWithArgument(['appOnlyKey' => $appOnly, 'stripeSecret' => $pattern]);

        self::assertFalse(str_contains($html, $appOnly), 'The application scrubber was ignored.');
        self::assertFalse(str_contains($html, $pattern), 'Setting an application scrubber dropped the pattern.');
    }

    /**
     * A value that cannot appear in this file's source. Tracy's bluescreen
     * prints the source line of every frame, so a literal secret in the test
     * would show up in the page whether or not it was masked.
     */
    private static function marker(string $label): string
    {
        return $label . '-' . bin2hex(random_bytes(8));
    }

    /**
     * Throws from a frame whose argument carries $config, so the bluescreen
     * renders that argument as part of the stack trace.
     *
     * @param array<string, mixed> $config
     */
    private function renderBlueScreenWithArgument(array $config): string
    {
        DebugIntegration::initialize(debug: true);

        try {
            $this->throwWithConfig($config);
        } catch (\RuntimeException $exception) {
            $file = sys_get_temp_dir() . '/zephyrus-bluescreen-' . bin2hex(random_bytes(8)) . '.html';

            try {
                Debugger::getBlueScreen()->renderToFile($exception, $file);

                return (string) file_get_contents($file);
            } finally {
                @unlink($file);
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function throwWithConfig(array $config): never
    {
        throw new \RuntimeException('boom');
    }
}
