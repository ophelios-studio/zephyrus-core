<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Session;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Session\SessionException;

final class SessionExceptionTest extends TestCase
{
    /**
     * @return array<string, array{callable(?\ErrorException=): SessionException}>
     */
    public static function refusalProvider(): array
    {
        return [
            'save handler' => [SessionException::saveHandlerRefused(...)],
            'start'        => [SessionException::startRefused(...)],
            'regeneration' => [SessionException::regenerationRefused(...)],
            'destruction'  => [SessionException::destructionRefused(...)],
            'output sent'  => [SessionException::outputAlreadySent(...)],
        ];
    }

    /**
     * @param callable(?\ErrorException=): SessionException $refusal
     */
    #[DataProvider('refusalProvider')]
    public function testARefusalChainsPhpsWarningWithoutPuttingItInTheMessage(callable $refusal): void
    {
        $warning = new \ErrorException('session_start(): failed in /srv/app/index.php', 0, E_WARNING, '/srv/app/index.php', 3);

        $exception = $refusal($warning);

        self::assertSame($warning, $exception->getPrevious());
        self::assertSame('session_start(): failed in /srv/app/index.php', $exception->phpReason());
        self::assertStringNotContainsString('/srv/app', $exception->getMessage());
    }

    /**
     * @param callable(?\ErrorException=): SessionException $refusal
     */
    #[DataProvider('refusalProvider')]
    public function testARefusalWithoutAWarningHasNoReason(callable $refusal): void
    {
        $exception = $refusal();

        self::assertNull($exception->getPrevious());
        self::assertNull($exception->phpReason());
    }
}
