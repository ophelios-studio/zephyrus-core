<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\DatabaseConfig;
use Zephyrus\Data\Database;
use Zephyrus\Data\DatabaseException;

/**
 * A persistent connection from fromConfig() reaches the next caller with a reset session, never a shared one.
 *
 * Skipped unless ZEPHYRUS_TEST_PGSQL_DSN holds a full pdo_pgsql DSN with its credentials.
 */
final class DatabasePersistentPostgresTest extends TestCase
{
    private const string ROLE = 'zephyrus_persistent_probe';

    private DatabaseConfig $config;

    private PDO $observer;

    protected function setUp(): void
    {
        $dsn = getenv('ZEPHYRUS_TEST_PGSQL_DSN');

        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('ZEPHYRUS_TEST_PGSQL_DSN is not set.');
        }

        $parts = [];
        foreach (explode(';', substr($dsn, strlen('pgsql:'))) as $pair) {
            [$key, $value] = explode('=', $pair, 2);
            $parts[$key] = $value;
        }

        $this->config = DatabaseConfig::fromArray([
            'host' => $parts['host'],
            'port' => (int) $parts['port'],
            'database' => $parts['dbname'],
            'username' => $parts['user'],
            'password' => $parts['password'],
            'sslMode' => 'disable',
            'persistent' => true,
        ]);

        $this->observer = new PDO($dsn, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->observer->exec('DROP TABLE IF EXISTS persistent_probe_items');
        $this->observer->exec('DROP SEQUENCE IF EXISTS persistent_probe_seq');
        $this->observer->exec('DROP ROLE IF EXISTS ' . self::ROLE);
        $this->observer->exec('CREATE TABLE persistent_probe_items (id INTEGER)');
        $this->observer->exec('CREATE SEQUENCE persistent_probe_seq');
        $this->observer->exec('CREATE ROLE ' . self::ROLE);
        $this->observer->exec('GRANT SELECT, INSERT ON persistent_probe_items TO ' . self::ROLE);
    }

    protected function tearDown(): void
    {
        if (!isset($this->observer)) {
            return;
        }

        $this->observer->exec('DROP TABLE IF EXISTS persistent_probe_items');
        $this->observer->exec('DROP SEQUENCE IF EXISTS persistent_probe_seq');
        $this->observer->exec('DROP ROLE IF EXISTS ' . self::ROLE);
    }

    public function testAReusedConnectionCarriesNothingOverFromThePreviousHolder(): void
    {
        $first = Database::fromConfig($this->config);
        $firstPid = $first->selectValue('SELECT pg_backend_pid()');
        $first->query("SELECT set_config('x.actor', 'u1', false)");
        $first->query('SELECT nextval(?)', ['persistent_probe_seq']);
        $first->query('SELECT pg_advisory_lock(7101)');
        $first->query('CREATE TEMP TABLE persistent_probe_items (id INTEGER)');
        $first->pdo()->exec('LISTEN persistent_probe_channel');
        $first->pdo()->exec('PREPARE persistent_probe_statement AS SELECT 1');
        $first->pdo()->exec('DECLARE persistent_probe_cursor CURSOR WITH HOLD FOR SELECT 1');
        $first->pdo()->exec('SET search_path TO pg_catalog');
        $first->pdo()->exec('SET ROLE ' . self::ROLE);
        $first->pdo()->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
        $first->pdo()->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_NUM);
        $first->pdo()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $first->pdo()->exec('BEGIN');
        $first->pdo()->exec('INSERT INTO public.persistent_probe_items (id) VALUES (1)');
        unset($first);
        gc_collect_cycles();

        $second = Database::fromConfig($this->config);

        self::assertSame($firstPid, $second->selectValue('SELECT pg_backend_pid()'), 'expected the pooled backend');
        $session = $second->selectOne(
            "SELECT current_setting('x.actor', true) AS actor, current_user AS role,
                    current_setting('search_path') AS search_path,
                    (SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()) AS locks,
                    to_regclass('pg_temp.persistent_probe_items') IS NULL AS no_temp_table,
                    (SELECT count(*) FROM pg_listening_channels()) AS channels,
                    (SELECT count(*) FROM pg_prepared_statements) AS prepared,
                    (SELECT count(*) FROM pg_cursors WHERE is_holdable) AS cursors,
                    (SELECT count(*) FROM public.persistent_probe_items) AS item_rows",
        );
        self::assertSame(
            [
                'actor' => '',
                'role' => $this->config->username,
                'search_path' => '"$user", public',
                'locks' => 0,
                'no_temp_table' => true,
                'channels' => 0,
                'prepared' => 0,
                'cursors' => 0,
                'item_rows' => 0,
            ],
            (array) $session,
        );
        self::assertSame(PDO::ERRMODE_EXCEPTION, $second->pdo()->getAttribute(PDO::ATTR_ERRMODE));
        self::assertSame(PDO::CASE_NATURAL, $second->pdo()->getAttribute(PDO::ATTR_CASE));
        self::assertSame('55000', self::sqlStateOf(static fn () => $second->query("SELECT currval('persistent_probe_seq')")));
        self::assertSame(0, (int) $this->observer->query('SELECT count(*) FROM persistent_probe_items')->fetchColumn());
    }

    public function testNotificationsTheClientAlreadyReceivedAreNotReadByTheNextHolder(): void
    {
        $first = Database::fromConfig($this->config);
        $firstPid = $first->selectValue('SELECT pg_backend_pid()');
        $first->pdo()->exec('LISTEN persistent_probe_channel');
        $this->observer->exec("NOTIFY persistent_probe_channel, 'first, addressed to the first holder'");
        $this->observer->exec("NOTIFY persistent_probe_channel, 'second, addressed to the first holder'");
        $first->selectValue('SELECT 1');
        unset($first);
        gc_collect_cycles();

        $second = Database::fromConfig($this->config);

        self::assertSame($firstPid, $second->selectValue('SELECT pg_backend_pid()'), 'expected the pooled backend');
        $pdo = $second->pdo();
        self::assertFalse(
            $pdo instanceof \Pdo\Pgsql ? $pdo->getNotify(PDO::FETCH_ASSOC) : $pdo->pgsqlGetNotify(PDO::FETCH_ASSOC),
        );
    }

    public function testAHandleStillHeldIsNeitherSharedNorReset(): void
    {
        $holder = Database::fromConfig($this->config);
        $holderPid = $holder->selectValue('SELECT pg_backend_pid()');
        $holder->query('SELECT pg_advisory_lock(7102)');
        $holder->pdo()->beginTransaction();
        $holder->execute('INSERT INTO persistent_probe_items (id) VALUES (1)');

        $other = Database::fromConfig($this->config);

        self::assertNotSame($holderPid, $other->selectValue('SELECT pg_backend_pid()'));
        self::assertFalse($other->pdo()->getAttribute(PDO::ATTR_PERSISTENT));
        self::assertTrue($holder->inTransaction());
        self::assertSame(1, $holder->selectValue('SELECT count(*) FROM persistent_probe_items'));
        self::assertSame(1, (int) $this->observer->query(
            "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND objid = 7102 AND granted AND pid = {$holderPid}",
        )->fetchColumn());
        $holder->pdo()->rollBack();
    }

    public function testABackendEndedByTheServerIsReplacedTransparently(): void
    {
        $first = Database::fromConfig($this->config);
        $firstPid = $first->selectValue('SELECT pg_backend_pid()');
        unset($first);
        gc_collect_cycles();
        $this->observer->query("SELECT pg_terminate_backend({$firstPid}, 5000)");

        $second = Database::fromConfig($this->config);

        self::assertNotSame($firstPid, $second->selectValue('SELECT pg_backend_pid()'));
        self::assertTrue($second->pdo()->getAttribute(PDO::ATTR_PERSISTENT));
    }

    public function testTheDefaultFactoryBuildsADriverSpecificPgsqlHandle(): void
    {
        $db = Database::fromConfig($this->config);

        self::assertInstanceOf(\Pdo\Pgsql::class, $db->pdo());
    }

    private static function sqlStateOf(callable $statement): ?string
    {
        try {
            $statement();
        } catch (DatabaseException $e) {
            return $e->sqlState();
        }

        return null;
    }
}
