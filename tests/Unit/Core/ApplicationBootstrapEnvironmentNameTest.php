<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Bootstrap\ApplicationBootstrap;
use Zephyrus\Core\Config\ConfigurationException;

/**
 * configPathsForDirectory() builds file paths out of three caller inputs and
 * checked two of them for path separators. $baseName was checked and every
 * extraOptionalNames entry was checked; $environment, which is APP_ENV and
 * therefore the input most likely to come from outside the source file, was
 * only trimmed.
 *
 * The gap was not exploitable on its own: the mandatory `<baseName>.` prefix
 * forces any traversal to start inside an existing directory component, and
 * every variant tried resolved to no file. It is closed anyway, because a
 * guard that covers two of three inputs is a guard nobody can reason about,
 * and the third one is the one an operator can set from the environment.
 */
final class ApplicationBootstrapEnvironmentNameTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function environmentsWithSeparators(): array
    {
        return [
            'a parent traversal' => ['../secrets'],
            'an absolute path' => ['/etc/passwd'],
            'a windows separator' => ['..\\\\secrets'],
            'a nested name' => ['staging/eu'],
        ];
    }

    #[DataProvider('environmentsWithSeparators')]
    public function testAnEnvironmentNameWithAPathSeparatorIsRefused(string $environment): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must not contain path separators');

        ApplicationBootstrap::configPathsForDirectory(
            configDir: '/tmp/zephyrus-config',
            baseName: 'app',
            environment: $environment,
        );
    }

    public function testTheGuardMatchesTheOneAlreadyAppliedToTheBaseName(): void
    {
        // Same rule, same message shape, so the three inputs now read alike.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('must not contain path separators');

        ApplicationBootstrap::configPathsForDirectory(
            configDir: '/tmp/zephyrus-config',
            baseName: 'app/../app',
        );
    }

    public function testAnOrdinaryEnvironmentNameStillResolves(): void
    {
        $paths = ApplicationBootstrap::configPathsForDirectory(
            configDir: '/tmp/zephyrus-config',
            baseName: 'app',
            environment: 'production',
        );

        self::assertSame('/tmp/zephyrus-config/app.php', $paths['required']);
        self::assertContains('/tmp/zephyrus-config/app.local.php', $paths['optional']);
        self::assertContains('/tmp/zephyrus-config/app.production.php', $paths['optional']);
    }

    public function testABlankEnvironmentStillDisablesEnvironmentSpecificLoading(): void
    {
        $paths = ApplicationBootstrap::configPathsForDirectory(
            configDir: '/tmp/zephyrus-config',
            baseName: 'app',
            environment: '   ',
        );

        self::assertSame(['/tmp/zephyrus-config/app.local.php'], $paths['optional']);
    }
}
