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

    public function testInvalidKeyEscapesControlBytesInTheMessage(): void
    {
        $exception = SessionException::invalidKey("a\r\nb");

        self::assertSame('Session key must be a non-empty string. Got "a\\r\\nb".', $exception->getMessage());
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

    public function testAKeyRefusalEscapesDeleteC1AndBidiCharacters(): void
    {
        self::assertSame(
            "Session key must be a non-empty string. Got \"a\\u007f\\u0085\\u202e\".",
            SessionException::invalidKey("a\x7f\u{0085}\u{202E}")->getMessage(),
        );
    }

    /**
     * @return array<string, array{callable(?\ErrorException=): SessionException, string}>
     */
    public static function refusalMessageProvider(): array
    {
        return [
            'save handler' => [
                SessionException::saveHandlerRefused(...),
                'PHP refused to install the session save handler, so sessions would keep going to the previous storage. '
                . 'Register the handler before the session starts.',
            ],
            'start' => [
                SessionException::startRefused(...),
                'PHP refused to start the session. The usual causes are output already sent to the browser, '
                . 'or a save handler that could not open or read the session.',
            ],
            'output sent' => [
                SessionException::outputAlreadySent(...),
                'The session cannot start: output has already been sent, so the session cookie cannot be set. '
                . 'Start the session before any output.',
            ],
            'regeneration' => [
                SessionException::regenerationRefused(...),
                'PHP refused to regenerate the session id. The usual causes are output already sent to the browser, '
                . 'a save handler that could not destroy the previous session, or a save handler that could not write '
                . 'the session (its stored session may have been destroyed or changed by a concurrent request).',
            ],
            'destruction' => [
                SessionException::destructionRefused(...),
                'PHP refused to destroy the session, so the stored session may still be live.',
            ],
        ];
    }

    /**
     * @param callable(?\ErrorException=): SessionException $refusal
     */
    #[DataProvider('refusalMessageProvider')]
    public function testARefusalTellsTheDeveloperWhatToFixAndCarriesNoCode(callable $refusal, string $message): void
    {
        $exception = $refusal(new \ErrorException('warning', 7, E_WARNING));

        self::assertSame($message, $exception->getMessage());
        self::assertSame(0, $exception->getCode());
    }

    public function testADataColumnThatIsNotTextTellsTheDeveloperToDeclareItText(): void
    {
        self::assertSame(
            'The data column of the table sessions returned integer instead of a string. '
            . 'Declare it TEXT NOT NULL, as DatabaseSessionHandler documents.',
            SessionException::dataColumnNotText('sessions', 'integer')->getMessage(),
        );
    }

    public function testAnUnresolvableDatabaseChainsTheCauseWithoutACode(): void
    {
        $previous = new \RuntimeException('down');

        $exception = SessionException::databaseUnavailable('sessions', $previous);

        self::assertSame('Session database for table "sessions" could not be resolved.', $exception->getMessage());
        self::assertSame(0, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testDestroyingAClosedSessionTellsTheDeveloperToReopenIt(): void
    {
        self::assertSame(
            'Cannot destroy the session: it is closed but still has an id, so its stored data may survive. '
            . 'Reopen it with start() before calling destroy().',
            SessionException::notActiveForDestruction()->getMessage(),
        );
    }
}
