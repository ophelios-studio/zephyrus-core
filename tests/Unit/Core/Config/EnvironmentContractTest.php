<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\EnvironmentContract;

/**
 * Boot-time environment contract: an unrecognised value must refuse to boot,
 * and declaring no rules must change nothing.
 */
final class EnvironmentContractTest extends TestCase
{
    // -- Non-breakage: declaring nothing ------------------------------------

    public function testAContractWithNoRulesIsAlwaysSatisfied(): void
    {
        $contract = EnvironmentContract::create();

        self::assertSame([], $contract->violations());
        self::assertTrue($contract->isSatisfied());

        // Returning rather than exiting is the assertion.
        $contract->enforce();
    }

    public function testASatisfiedContractProducesNoViolations(): void
    {
        $contract = EnvironmentContract::create()
            ->requireSet('DATABASE_URL')
            ->requireOneOf('APP_ENV', ['dev', 'production'])
            ->requireBase64Bytes('ENCRYPTION_KEY', 32);

        $values = [
            'DATABASE_URL' => 'postgres://localhost/app',
            'APP_ENV' => 'production',
            'ENCRYPTION_KEY' => base64_encode(str_repeat('k', 32)),
        ];

        self::assertSame([], $contract->violations($values));
        self::assertTrue($contract->isSatisfied($values));
        $contract->enforce($values);
    }

    // -- requireSet -----------------------------------------------------------

    public function testRequireSetRejectsMissingAndBlankValues(): void
    {
        $contract = EnvironmentContract::create()->requireSet('DATABASE_URL');

        self::assertSame(['DATABASE_URL is not set.'], $contract->violations([]));
        self::assertSame(['DATABASE_URL is not set.'], $contract->violations(['DATABASE_URL' => '']));
        self::assertSame(['DATABASE_URL is not set.'], $contract->violations(['DATABASE_URL' => '   ']));
        self::assertSame([], $contract->violations(['DATABASE_URL' => 'postgres://x']));
    }

    // -- requireOneOf ---------------------------------------------------------

    public function testRequireOneOfRejectsAnUnrecognisedValue(): void
    {
        $contract = EnvironmentContract::create()
            ->requireOneOf('APP_ENV', ['dev', 'staging', 'production']);

        // 'prod' is not 'production': it must refuse, not fall back.
        $violations = $contract->violations(['APP_ENV' => 'prod']);

        self::assertCount(1, $violations);
        self::assertStringContainsString('APP_ENV="prod" is not a recognised value', $violations[0]);
        self::assertStringContainsString('dev, staging, production', $violations[0]);
    }

    public function testRequireOneOfRejectsAnUnrecognisedTier(): void
    {
        $contract = EnvironmentContract::create()->requireOneOf('MODE', ['WEB', 'INGEST']);

        self::assertCount(1, $contract->violations(['MODE' => 'INGESTT']));
        self::assertSame([], $contract->violations(['MODE' => 'INGEST']));
    }

    public function testRequireOneOfNormalisesCaseAndWhitespaceByDefault(): void
    {
        $contract = EnvironmentContract::create()->requireOneOf('MODE', ['WEB', 'API']);

        // Normalisation is the default so that readers of the value agree.
        self::assertSame([], $contract->violations(['MODE' => 'web']));
        self::assertSame([], $contract->violations(['MODE' => '  WEB  ']));
        self::assertSame([], $contract->violations(['MODE' => 'Api']));
    }

    public function testRequireOneOfCanBeCaseSensitive(): void
    {
        $contract = EnvironmentContract::create()
            ->requireOneOf('MODE', ['WEB'], caseSensitive: true);

        self::assertSame([], $contract->violations(['MODE' => 'WEB']));
        self::assertCount(1, $contract->violations(['MODE' => 'web']));
    }

    public function testRequireOneOfReportsAMissingValueWithTheAllowedList(): void
    {
        $contract = EnvironmentContract::create()->requireOneOf('MODE', ['WEB', 'API']);

        $violations = $contract->violations([]);

        self::assertCount(1, $violations);
        self::assertStringContainsString('MODE is not set', $violations[0]);
        self::assertStringContainsString('WEB, API', $violations[0]);
    }

    // -- requireBase64Bytes ---------------------------------------------------

    public function testRequireBase64BytesAcceptsAKeyOfTheExactLength(): void
    {
        $contract = EnvironmentContract::create()->requireBase64Bytes('ENCRYPTION_KEY', 32);

        self::assertSame([], $contract->violations([
            'ENCRYPTION_KEY' => base64_encode(random_bytes(32)),
        ]));
    }

    public function testRequireBase64BytesRejectsMissingInvalidAndWrongLengthKeys(): void
    {
        $contract = EnvironmentContract::create()->requireBase64Bytes('ENCRYPTION_KEY', 32);

        self::assertSame(['ENCRYPTION_KEY is not set.'], $contract->violations([]));

        $invalid = $contract->violations(['ENCRYPTION_KEY' => 'not valid base64 !!!']);
        self::assertStringContainsString('is not valid base64', $invalid[0]);

        $short = $contract->violations(['ENCRYPTION_KEY' => base64_encode(random_bytes(16))]);
        self::assertStringContainsString('decodes to 16 byte(s); exactly 32 are required', $short[0]);
    }

    /**
     * The reason must identify the variable and the decoded length, and must
     * never leak the secret or any prefix of it.
     */
    public function testABadSecretIsReportedWithoutEverEchoingItsValue(): void
    {
        $secret = base64_encode(str_repeat('S', 16));

        $violations = EnvironmentContract::create()
            ->requireBase64Bytes('ENCRYPTION_KEY', 32)
            ->violations(['ENCRYPTION_KEY' => $secret]);

        $joined = implode("\n", $violations);

        self::assertStringContainsString('ENCRYPTION_KEY', $joined);
        self::assertStringContainsString('16 byte(s)', $joined);
        self::assertStringNotContainsString($secret, $joined);
        self::assertStringNotContainsString(substr($secret, 0, 6), $joined);
    }

    // -- Several rules --------------------------------------------------------

    public function testEveryViolationIsReportedNotJustTheFirst(): void
    {
        $contract = EnvironmentContract::create()
            ->requireOneOf('APP_ENV', ['dev', 'production'])
            ->requireSet('DATABASE_URL')
            ->requireBase64Bytes('ENCRYPTION_KEY', 32);

        $violations = $contract->violations(['APP_ENV' => 'prod']);

        // All problems surface in one restart, not one per deploy cycle.
        self::assertCount(3, $violations);
    }

    public function testRulesAreImmutableSoABuilderCanBeReused(): void
    {
        $base = EnvironmentContract::create()->requireSet('A');
        $extended = $base->requireSet('B');

        self::assertCount(1, $base->violations([]));
        self::assertCount(2, $extended->violations([]));
    }

    // -- Canonicalisation -----------------------------------------------------

    public function testCanonicaliseWritesBackTheAllowlistLiteralNotTheInput(): void
    {
        unset($_ENV['CONTRACT_TEST_MODE'], $_SERVER['CONTRACT_TEST_MODE']);
        putenv('CONTRACT_TEST_MODE');

        EnvironmentContract::create()
            ->requireOneOf('CONTRACT_TEST_MODE', ['WEB', 'API'], canonicalise: true)
            ->enforce(['CONTRACT_TEST_MODE' => '  web ']);

        // Writes the allowlist literal, never the caller's input.
        self::assertSame('WEB', $_ENV['CONTRACT_TEST_MODE']);
        self::assertSame('WEB', getenv('CONTRACT_TEST_MODE'));

        unset($_ENV['CONTRACT_TEST_MODE'], $_SERVER['CONTRACT_TEST_MODE']);
        putenv('CONTRACT_TEST_MODE');
    }

    public function testARequiredVariableSetOnlyInServerIsReportedMissing(): void
    {
        $_SERVER['CONTRACT_TEST_SERVER_ONLY'] = 'set';

        try {
            self::assertFalse(EnvironmentContract::create()
                ->requireSet('CONTRACT_TEST_SERVER_ONLY')
                ->isSatisfied());
        } finally {
            unset($_SERVER['CONTRACT_TEST_SERVER_ONLY']);
        }
    }

    public function testARequiredVariableFromTheProcessEnvironmentIsSatisfied(): void
    {
        putenv('CONTRACT_TEST_PROCESS_ONLY=set');

        try {
            self::assertTrue(EnvironmentContract::create()
                ->requireSet('CONTRACT_TEST_PROCESS_ONLY')
                ->isSatisfied());
        } finally {
            putenv('CONTRACT_TEST_PROCESS_ONLY');
        }
    }

    public function testAnAllowlistedVariableSetOnlyInServerIsNotSatisfied(): void
    {
        $_SERVER['CONTRACT_TEST_SERVER_MODE'] = 'web';

        try {
            self::assertFalse(EnvironmentContract::create()
                ->requireOneOf('CONTRACT_TEST_SERVER_MODE', ['WEB', 'API'], canonicalise: true)
                ->isSatisfied());
            self::assertArrayNotHasKey('CONTRACT_TEST_SERVER_MODE', $_ENV);
        } finally {
            unset($_SERVER['CONTRACT_TEST_SERVER_MODE'], $_ENV['CONTRACT_TEST_SERVER_MODE']);
        }
    }

    public function testAnHttpPrefixedRequirementReportsTheRefusalNotAMissingValue(): void
    {
        $_ENV['HTTP_TIMEOUT'] = '5';

        try {
            $violations = EnvironmentContract::create()
                ->requireSet('HTTP_TIMEOUT')
                ->violations();
        } finally {
            unset($_ENV['HTTP_TIMEOUT']);
        }

        self::assertCount(1, $violations);
        self::assertStringContainsString('HTTP_TIMEOUT', $violations[0]);
        self::assertStringContainsString('rename the variable', $violations[0]);
        self::assertStringNotContainsString('is not set', $violations[0]);
    }

    public function testAVariableSetOnlyByTheWebServerIsReportedWithoutItsValue(): void
    {
        $_SERVER['CONTRACT_TEST_WEB_SERVER'] = 'secret-value-123';

        try {
            $violations = EnvironmentContract::create()
                ->requireSet('CONTRACT_TEST_WEB_SERVER')
                ->violations();
        } finally {
            unset($_SERVER['CONTRACT_TEST_WEB_SERVER']);
        }

        self::assertCount(1, $violations);
        self::assertStringContainsString('fastcgi_param/SetEnv', $violations[0]);
        self::assertStringNotContainsString('secret-value-123', $violations[0]);
    }

    public function testCanonicaliseIsNotAppliedWithoutTheFlag(): void
    {
        unset($_ENV['CONTRACT_TEST_PLAIN'], $_SERVER['CONTRACT_TEST_PLAIN']);
        putenv('CONTRACT_TEST_PLAIN');

        EnvironmentContract::create()
            ->requireOneOf('CONTRACT_TEST_PLAIN', ['WEB'])
            ->enforce(['CONTRACT_TEST_PLAIN' => 'web']);

        self::assertArrayNotHasKey('CONTRACT_TEST_PLAIN', $_ENV);
        self::assertFalse(getenv('CONTRACT_TEST_PLAIN'));
    }

    // -- The refusal payload --------------------------------------------------

    /**
     * During a refusal this response is the whole site, so it must forbid framing and sniffing.
     */
    public function testRefusalResponseIs500WithAnEmptyBodyAndSecurityHeaders(): void
    {
        $refusal = EnvironmentContract::refusalResponse();

        self::assertSame(500, $refusal['status']);
        self::assertSame('', $refusal['body'], 'the cause must never reach the client');
        self::assertSame('SAMEORIGIN', $refusal['headers']['X-Frame-Options']);
        self::assertSame('nosniff', $refusal['headers']['X-Content-Type-Options']);
        self::assertSame('no-store', $refusal['headers']['Cache-Control']);
        self::assertArrayHasKey('Referrer-Policy', $refusal['headers']);
    }

    public function testRefusalTextListsEveryReason(): void
    {
        $text = EnvironmentContract::refusalText(['first reason', 'second reason']);

        self::assertStringContainsString('Refusing to boot:', $text);
        self::assertStringContainsString('- first reason', $text);
        self::assertStringContainsString('- second reason', $text);
    }

    // -- The real CLI path, in a subprocess -----------------------------------

    public function testCliEntryPointBootsWhenTheContractIsSatisfied(): void
    {
        $result = $this->runEntryPoint([
            'APP_ENV' => 'production',
            'MODE' => 'web',
            'ENCRYPTION_KEY' => base64_encode(str_repeat('k', 32)),
        ]);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('BOOTED', $result['stdout']);
        self::assertStringContainsString('MODE=WEB', $result['stdout']);
        self::assertSame('', trim($result['stderr']));
    }

    public function testCliEntryPointExitsNonZeroWithTheReasonOnStderr(): void
    {
        $result = $this->runEntryPoint([
            'APP_ENV' => 'prod',
            'MODE' => 'WEB',
            'ENCRYPTION_KEY' => base64_encode(str_repeat('k', 32)),
        ]);

        self::assertSame(EnvironmentContract::EXIT_CONFIG, $result['exit']);
        self::assertStringContainsString('Refusing to boot', $result['stderr']);
        self::assertStringContainsString('APP_ENV="prod"', $result['stderr']);
        // Nothing was served: the process died instead of booting.
        self::assertStringNotContainsString('BOOTED', $result['stdout']);
    }

    public function testCliEntryPointRefusesAnUnknownTier(): void
    {
        $result = $this->runEntryPoint([
            'APP_ENV' => 'production',
            'MODE' => 'INGESTT',
            'ENCRYPTION_KEY' => base64_encode(str_repeat('k', 32)),
        ]);

        self::assertSame(EnvironmentContract::EXIT_CONFIG, $result['exit']);
        self::assertStringContainsString('MODE="INGESTT"', $result['stderr']);
        self::assertStringNotContainsString('BOOTED', $result['stdout']);
    }

    public function testCliRefusalNeverPrintsTheSecretAnywhere(): void
    {
        $secret = base64_encode(str_repeat('S', 16));

        $result = $this->runEntryPoint([
            'APP_ENV' => 'production',
            'MODE' => 'WEB',
            'ENCRYPTION_KEY' => $secret,
        ]);

        $everything = $result['stdout'] . $result['stderr'];

        self::assertSame(EnvironmentContract::EXIT_CONFIG, $result['exit']);
        self::assertStringContainsString('ENCRYPTION_KEY decodes to 16 byte(s)', $everything);
        self::assertStringNotContainsString($secret, $everything);
        self::assertStringNotContainsString(substr($secret, 0, 6), $everything);
    }

    public function testCliEntryPointDeclaringNothingBootsWhateverTheEnvironment(): void
    {
        // With no rules declared, the same entry point boots with the refused values.
        $result = $this->runEntryPoint([
            'CONTRACT_EMPTY' => '1',
            'APP_ENV' => 'prod',
            'MODE' => 'INGESTT',
        ]);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('BOOTED', $result['stdout']);
    }

    /**
     * @param array<string, string> $environment
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runEntryPoint(array $environment): array
    {
        $script = dirname(__DIR__, 3) . '/Fixtures/boot_contract_entrypoint.php';

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [PHP_BINARY, $script],
            $descriptors,
            $pipes,
            null,
            $environment + ['PATH' => (string) getenv('PATH')],
        );

        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    public function testRequireOneOfEscapesTheRefusedValue(): void
    {
        $contract = EnvironmentContract::create()->requireOneOf('APP_ENV', ['dev', 'production']);

        self::assertSame(
            ["APP_ENV=\"prod\\u001b[2K\" is not a recognised value. Use one of: dev, production."],
            $contract->violations(['APP_ENV' => "prod\x1b[2K"]),
        );
    }
}
