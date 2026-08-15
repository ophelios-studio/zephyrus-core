<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\EnvironmentContract;

/**
 * The boot-time environment contract.
 *
 * Unknown environment values fail open, and the result is a confident,
 * healthy-looking, wrong process: APP_ENV=prod (not `production`) served
 * absolute-path stack traces at HTTP 200 while a health check reported the
 * machine healthy. These tests pin the refusal, and pin that declaring nothing
 * changes nothing.
 */
final class EnvironmentContractTest extends TestCase
{
    // -- Non-breakage: declaring nothing ------------------------------------

    /**
     * David's condition. A consumer that declares no rules has no violations
     * and enforce() returns normally rather than terminating, so adding this
     * class to the framework changes nothing for anyone who does not use it.
     */
    public function testAContractWithNoRulesIsAlwaysSatisfied(): void
    {
        $contract = EnvironmentContract::create();

        self::assertSame([], $contract->violations());
        self::assertTrue($contract->isSatisfied());

        // Returns rather than exiting. If this ever terminated, the whole test
        // run would stop here, which is itself the assertion.
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
        // Satisfied means enforce() is a no-op, not a different code path.
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

        // The exact failure that served stack traces at HTTP 200.
        $violations = $contract->violations(['APP_ENV' => 'prod']);

        self::assertCount(1, $violations);
        self::assertStringContainsString('APP_ENV="prod" is not a recognised value', $violations[0]);
        self::assertStringContainsString('dev, staging, production', $violations[0]);
    }

    public function testRequireOneOfRejectsAnUnrecognisedTier(): void
    {
        // MODE=INGESTT served a green health check over an empty router.
        $contract = EnvironmentContract::create()->requireOneOf('MODE', ['WEB', 'INGEST']);

        self::assertCount(1, $contract->violations(['MODE' => 'INGESTT']));
        self::assertSame([], $contract->violations(['MODE' => 'INGEST']));
    }

    public function testRequireOneOfNormalisesCaseAndWhitespaceByDefault(): void
    {
        $contract = EnvironmentContract::create()->requireOneOf('MODE', ['WEB', 'API']);

        // Two parts of an application must not disagree about the same value.
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

        // An operator should learn everything wrong in one restart, not one
        // problem per deploy cycle.
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

        // The canonical literal from the allowlist, never the caller's input.
        // Readers using a strict === against 'WEB' and readers that uppercase
        // now agree.
        self::assertSame('WEB', $_ENV['CONTRACT_TEST_MODE']);
        self::assertSame('WEB', getenv('CONTRACT_TEST_MODE'));

        unset($_ENV['CONTRACT_TEST_MODE'], $_SERVER['CONTRACT_TEST_MODE']);
        putenv('CONTRACT_TEST_MODE');
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
     * During a refusal this response is the entire site, on every URL. It was
     * shipping framable and sniffable in the project this came from.
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
        // Canonicalised to the allowlist literal for the child process.
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
        // The non-breakage proof at the process level: with no rules declared
        // the same entry point boots even with the values that refuse above.
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
}
