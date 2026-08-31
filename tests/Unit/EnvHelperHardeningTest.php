<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * env() is the unhardened twin of ConfigurationFile::resolveEnvTag(), and it
 * was wrong in BOTH directions.
 *
 * Its sibling carries a sixteen-line docblock naming the two hazards and
 * handles both. env() read `$_ENV[$key] ?? $_SERVER[$key]` and handled
 * neither:
 *
 * (a) httpoxy, CVE-2016-5385. PHP writes every request header into $_SERVER as
 *     HTTP_<NAME>, so a caller sending `Proxy: http://attacker/` makes
 *     $_SERVER['HTTP_PROXY'] exist and env('HTTP_PROXY') return the attacker's
 *     value. The request writes the configuration.
 *
 * (b) A silent fail-open under php-fpm. The default variables_order is "GPCS",
 *     with no E, so $_ENV is EMPTY and every value the platform set lives only
 *     in getenv(). env() never called getenv(), so env('WEBHOOK_SECRET') was
 *     NULL where getenv() had the real value, and env('REQUIRE_MFA', false)
 *     resolved to the DEFAULT on production while resolving correctly on a
 *     developer's CLI.
 */
final class EnvHelperHardeningTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $envBackup = [];

    /** @var array<string, mixed> */
    private array $serverBackup = [];

    /** @var list<string> */
    private array $names = [
        'HTTP_PROXY',
        'HTTP_ZEPHYRUS_ANYTHING',
        'ZEPHYRUS_FPM_SECRET',
        'ZEPHYRUS_FPM_FLAG',
        'ZEPHYRUS_FASTCGI_PARAM',
        'ZEPHYRUS_PRECEDENCE',
    ];

    protected function setUp(): void
    {
        foreach ($this->names as $name) {
            $this->envBackup[$name] = $_ENV[$name] ?? null;
            $this->serverBackup[$name] = $_SERVER[$name] ?? null;
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->names as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            if ($this->envBackup[$name] !== null) {
                $_ENV[$name] = $this->envBackup[$name];
            }
            if ($this->serverBackup[$name] !== null) {
                $_SERVER[$name] = $this->serverBackup[$name];
            }
        }
    }

    // -- (a) httpoxy ---------------------------------------------------------

    public function testARequestHeaderCannotBecomeAnEnvironmentValue(): void
    {
        // Exactly what PHP does with an inbound `Proxy:` header.
        $_SERVER['HTTP_PROXY'] = 'http://attacker.test:3128';

        self::assertNull(env('HTTP_PROXY'));
    }

    public function testTheDefaultIsReturnedRatherThanTheHeaderValue(): void
    {
        $_SERVER['HTTP_PROXY'] = 'http://attacker.test:3128';

        self::assertSame('none', env('HTTP_PROXY', 'none'));
    }

    public function testTheExclusionCoversTheWholeHttpFamilyAndNotJustProxy(): void
    {
        $_SERVER['HTTP_ZEPHYRUS_ANYTHING'] = 'attacker-chosen';

        self::assertNull(env('HTTP_ZEPHYRUS_ANYTHING'));
    }

    /**
     * A real HTTP_-named variable set by the OPERATOR is still readable: the
     * exclusion is on the untrusted source, not on the name.
     */
    public function testAnOperatorSetHttpNamedVariableIsStillRead(): void
    {
        putenv('HTTP_PROXY=http://corporate-proxy.internal:3128');

        self::assertSame('http://corporate-proxy.internal:3128', env('HTTP_PROXY'));
    }

    // -- (b) the php-fpm blind spot ------------------------------------------

    public function testAValueThatExistsOnlyInTheProcessEnvironmentIsFound(): void
    {
        // The php-fpm shape: variables_order without E, so $_ENV never got it.
        putenv('ZEPHYRUS_FPM_SECRET=whsec_LIVE_abc123');

        self::assertSame('whsec_LIVE_abc123', env('ZEPHYRUS_FPM_SECRET'));
    }

    /**
     * The dangerous flavour of the same bug: a flag the operator turned ON in
     * the process environment used to read as the caller's default, so a
     * security switch was off on production and on in development.
     */
    public function testAFlagSetOnlyInTheProcessEnvironmentIsNotSilentlyOff(): void
    {
        putenv('ZEPHYRUS_FPM_FLAG=true');

        self::assertTrue(env('ZEPHYRUS_FPM_FLAG', false));
    }

    // -- what must NOT change ------------------------------------------------

    public function testTheServerFallbackStillWorksForANonHttpName(): void
    {
        // fastcgi_param / SetEnv is a documented deployment pattern.
        $_SERVER['ZEPHYRUS_FASTCGI_PARAM'] = 'from-fastcgi';

        self::assertSame('from-fastcgi', env('ZEPHYRUS_FASTCGI_PARAM'));
    }

    public function testTheResolutionOrderIsEnvThenGetenvThenServer(): void
    {
        $_SERVER['ZEPHYRUS_PRECEDENCE'] = 'from-server';
        putenv('ZEPHYRUS_PRECEDENCE=from-getenv');
        $_ENV['ZEPHYRUS_PRECEDENCE'] = 'from-env';

        self::assertSame('from-env', env('ZEPHYRUS_PRECEDENCE'));

        unset($_ENV['ZEPHYRUS_PRECEDENCE']);
        self::assertSame('from-getenv', env('ZEPHYRUS_PRECEDENCE'));

        putenv('ZEPHYRUS_PRECEDENCE');
        self::assertSame('from-server', env('ZEPHYRUS_PRECEDENCE'));
    }

    public function testThisMatchesResolveEnvTagWhichAlreadyGotItRight(): void
    {
        // The two helpers are meant to agree. resolveEnvTag() has been ordering
        // $_ENV, getenv(), then non-HTTP_ $_SERVER since it was hardened; this
        // pins that env() now says the same thing.
        $_SERVER['HTTP_PROXY'] = 'http://attacker.test:3128';
        putenv('ZEPHYRUS_FPM_SECRET=only-in-process-env');

        self::assertNull(env('HTTP_PROXY'));
        self::assertSame('only-in-process-env', env('ZEPHYRUS_FPM_SECRET'));
    }
}
