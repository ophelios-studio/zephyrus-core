<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\App;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\SessionConfig;
use Zephyrus\Data\Database;
use Zephyrus\Session\DatabaseSessionHandler;
use Zephyrus\Session\SessionManager;
use Zephyrus\Tests\Support\IsolatedSessionSavePath;

/**
 * The configured idle timeout reaching storage through SessionManager::start(),
 * with no argument the application has to remember to pass.
 */
final class SessionIdleTimeoutTest extends TestCase
{
    use IsolatedSessionSavePath;

    protected function setUp(): void
    {
        $this->useIsolatedSessionSavePath();
    }

    protected function tearDown(): void
    {
        $this->removeIsolatedSessionSavePath();
    }

    #[RunInSeparateProcess]
    public function testStartAppliesTheIdleTimeoutToTheStoredExpiry(): void
    {
        $database = $this->database();
        $session = new SessionManager();
        $session->setHandler(new DatabaseSessionHandler($database, 'session'));

        $session->start(SessionConfig::fromArray(['idle_timeout' => 600]));
        $_SESSION['user_id'] = 1;
        session_write_close();

        self::assertSame('600', ini_get('session.gc_maxlifetime'));
        self::assertSame(600, $database->selectInt('SELECT expire - access FROM session'));
    }

    #[RunInSeparateProcess]
    public function testWithoutAnIdleTimeoutStartLeavesTheIniLifetimeAlone(): void
    {
        ini_set('session.gc_maxlifetime', '1234');

        (new SessionManager())->start(SessionConfig::fromArray([]));

        self::assertSame('1234', ini_get('session.gc_maxlifetime'));
    }

    /**
     * PHP's files handler never checks a session's age on read, so there the
     * timeout is only a garbage-collection age, which Debian and Ubuntu never run.
     */
    #[RunInSeparateProcess]
    public function testStartWarnsInDebugWhenTheFilesHandlerCannotEnforceTheIdleTimeout(): void
    {
        $warnings = $this->debugWarningsDuringStart(new SessionManager(), ['idle_timeout' => 600]);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('idle', $warnings[0]);
        self::assertStringContainsString('files', $warnings[0]);
        self::assertStringContainsString('DatabaseSessionHandler', $warnings[0]);
        self::assertStringContainsString('remove idleTimeout and set session.gc_maxlifetime', $warnings[0]);
    }

    #[RunInSeparateProcess]
    public function testStartDoesNotWarnWhenARegisteredHandlerOwnsTheIdleTimeout(): void
    {
        $session = new SessionManager();
        $session->setHandler(new DatabaseSessionHandler($this->database(), 'session'));

        self::assertSame([], $this->debugWarningsDuringStart($session, ['idle_timeout' => 600]));
    }

    #[RunInSeparateProcess]
    public function testStartDoesNotWarnWithoutAnIdleTimeout(): void
    {
        self::assertSame([], $this->debugWarningsDuringStart(new SessionManager(), []));
    }

    /**
     * @param array<string, mixed> $sessionValues
     * @return list<string>
     */
    private function debugWarningsDuringStart(SessionManager $session, array $sessionValues): array
    {
        App::setConfiguration(Configuration::fromArray(['application' => ['debug' => true]]));

        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);

        try {
            $session->start(SessionConfig::fromArray($sessionValues));
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }

    private function database(): Database
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE session (session_id VARCHAR PRIMARY KEY, access INTEGER NOT NULL, expire INTEGER NOT NULL, data TEXT NOT NULL DEFAULT "")');

        return new Database($pdo);
    }
}
