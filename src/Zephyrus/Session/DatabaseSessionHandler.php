<?php

declare(strict_types=1);

namespace Zephyrus\Session;

use Closure;
use PDO;
use Throwable;
use Zephyrus\Data\Database;
use Zephyrus\Data\DatabaseException;

/**
 * SessionHandlerInterface implementation that stores session data in a database table.
 *
 * Requires INSERT ... ON CONFLICT DO UPDATE (PostgreSQL 9.5+, SQLite 3.24+).
 *
 * Expected table:
 *
 *   CREATE TABLE <table> (
 *       session_id VARCHAR PRIMARY KEY,
 *       access     INTEGER NOT NULL,
 *       expire     INTEGER NOT NULL,
 *       data       TEXT NOT NULL DEFAULT ''
 *   );
 *
 *   CREATE INDEX <table>_access_idx ON <table> (access);
 *
 * gc() deletes by access, so the index belongs on access, not on expire.
 * A row past its expire, or idle for longer than session.gc_maxlifetime, is neither adopted nor read.
 * Columns this handler does not write stay NULL: give them a default or drop them.
 * Payloads that are not valid UTF-8, contain a NUL byte or start with "base64:" are stored base64-encoded,
 * so a `data LIKE` search does not find them.
 *
 * Register through SessionManager, which also sets the cookie flags:
 *
 *   $session = new SessionManager();
 *   $session->setHandler(new DatabaseSessionHandler($db));
 *   $session->start($config->session);
 *
 * Pass a Closure instead of the Database to register the handler before the database can be built. It runs
 * at most once, at the first callback that needs the database. When it throws or returns anything else, that
 * callback and every later one throw a SessionException: there is no fallback and no retry.
 * A new session that stays empty is not stored.
 *
 * A wrapper must forward open() and close() as well as the data callbacks: close() releases the
 * advisory lock taken by read().
 * Close, regenerate or destroy the session outside a Database transaction on the same connection: a failing
 * session write aborts it and the lock stays held until the connection closes.
 * Behind transaction pooling (PgBouncer pool_mode=transaction), construct the handler with lockSessions: false.
 */
final class DatabaseSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    /**
     * Session ids PHP can produce: sid_bits_per_character 4 to 6, sid_length 22 to 256.
     *
     * Anchored with \A and \z: PCRE's `$` also matches before a final newline.
     */
    public const DEFAULT_ID_PATTERN = '/\A[A-Za-z0-9,-]{22,256}\z/';

    /**
     * The classid of every advisory lock taken here: "ZEPH" as a 32-bit integer.
     *
     * Only the two-key form is used, which PostgreSQL keeps apart from the bigint form. Another
     * application contends with it only by using this exact classid with two keys. To check:
     *
     *   SELECT * FROM pg_locks
     *    WHERE locktype = 'advisory' AND classid = 1514491976 AND objsubid = 2;
     */
    public const ADVISORY_LOCK_NAMESPACE = 0x5A455048;

    /**
     * Seconds a blocked read() waits for a contended session lock before going on without it.
     *
     * Giving up at worst loses an update; an unbounded wait blocks every later request for that session.
     */
    private const LOCK_WAIT_SECONDS = 5;

    /** A row is live before its expire and while idle for less than the current lifetime. */
    private const LIVE_ROW = 'expire > ? AND access > ?';

    /** Marks a payload stored as base64 because the data column cannot hold it verbatim. */
    private const ENCODED_PAYLOAD_PREFIX = 'base64:';

    /** Savepoint isolating the bounded lock wait inside the caller's transaction. */
    private const LOCK_SAVEPOINT = 'zephyrus_session_lock';

    /** read() found a live row: this request resumed an existing session. */
    private const STATE_RESUMED = 'resumed';

    /** read() found no live row: this request is creating the session. */
    private const STATE_CREATED = 'created';

    /** destroy() ran: the row is deliberately gone and must stay gone. */
    private const STATE_DESTROYED = 'destroyed';

    /** read() failed: the stored payload is unknown, so nothing may replace it. */
    private const STATE_READ_FAILED = 'read_failed';

    /**
     * What this request knows about each session id it has handled. An absent id was never read.
     *
     * @var array<string, self::STATE_*>
     */
    private array $idStates = [];

    /** The id whose advisory lock this handler currently holds, if any. */
    private ?string $lockedId = null;

    /** Whether read() takes the advisory lock. Turned off for the request once an unlock misses. */
    private bool $locking;

    /** Memoized PDO driver name; '' once the lookup has failed. */
    private ?string $driver = null;

    /** Set once the Closure has run successfully. */
    private ?Database $resolved = null;

    /** Set once the Closure has failed; every later callback refuses the same way. */
    private ?SessionException $unavailable = null;

    /**
     * @param Database|Closure(): Database $database
     * @param string $idPattern Accepted session id shape. Override only for ids generated in another format.
     * @param bool $lockSessions Serialize the concurrent requests of one session (PostgreSQL only). With false,
     *   concurrent requests of one session can overwrite each other's changes. Pass false only behind transaction
     *   or statement pooling: the lock would stay on a server connection after the request and fill the shared
     *   lock table until PostgreSQL runs out of shared memory.
     */
    public function __construct(
        private readonly Database|Closure $database,
        private readonly string $table = 'public.session',
        private readonly string $idColumn = 'session_id',
        private readonly string $idPattern = self::DEFAULT_ID_PATTERN,
        bool $lockSessions = true,
    ) {
        $this->locking = $lockSessions;
        self::forceStrictMode();
    }

    /**
     * Turn session.use_strict_mode on from the handler's own construction.
     *
     * The flag must be set before session_start(): PHP calls validateId() only under strict mode, so
     * a handler registered without SessionManager would otherwise adopt any id the client sends.
     * PHP refuses session.* changes once the session is active or output has begun, so those cases are skipped.
     */
    private static function forceStrictMode(): void
    {
        if (ini_get('session.use_strict_mode') === '1') {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
    }

    /**
     * @throws SessionException when the Closure given to the constructor failed or did not return a Database.
     */
    private function database(): Database
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        if ($this->unavailable !== null) {
            throw SessionException::databaseUnavailable($this->table, $this->unavailable->getPrevious());
        }

        if ($this->database instanceof Database) {
            return $this->resolved = $this->database;
        }

        try {
            /** @var mixed $database */
            $database = ($this->database)();
        } catch (Throwable $failure) {
            throw $this->unavailable = SessionException::databaseUnavailable($this->table, $failure);
        }

        if (!$database instanceof Database) {
            throw $this->unavailable = SessionException::databaseUnavailable($this->table);
        }

        return $this->resolved = $database;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        $this->releaseLock();

        return true;
    }

    /**
     * Decide whether PHP may adopt a client-supplied session id: it must match the pattern and have a live row.
     *
     * Implementing this method is what makes PHP enforce session.use_strict_mode for this handler.
     * Refusing unknown ids only narrows session fixation: rotating the id on each privilege change defeats it.
     */
    public function validateId(string $id): bool
    {
        if (!$this->isValidId($id)) {
            return false;
        }

        return $this->database()->selectOne(
            "SELECT {$this->idColumn} FROM {$this->table} WHERE {$this->idColumn} = ? AND " . self::LIVE_ROW,
            [$id, ...$this->liveParameters()],
        ) !== null;
    }

    /**
     * Refresh the access window of an unchanged session without rewriting its payload.
     *
     * A bare UPDATE, never an upsert: an upsert would re-create a row deleted while the request was in flight,
     * such as by a logout. Returns false when no row was refreshed. A session this request created has no row
     * to refresh and returns true without a statement. An id this handler never read is refused.
     */
    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->writeRow($id, true, function () use ($id): int {
            $access = time();

            return $this->database()->execute(
                "UPDATE {$this->table}
                    SET access = ?, expire = ?
                  WHERE {$this->idColumn} = ?",
                [$access, $access + $this->maxLifetime(), $id],
            );
        });
    }

    /**
     * Load the session payload and take the advisory lock that serializes concurrent writes.
     *
     * An expired row reads as absent and is left in place: gc() owns removal.
     *
     * @throws SessionException when the data column holds something other than text.
     * @throws DatabaseException when the SELECT fails.
     */
    public function read(string $id): string
    {
        if (!$this->isValidId($id)) {
            return '';
        }

        $this->acquireLock($id);

        $select = fn (): ?\stdClass => $this->database()->selectOne(
            "SELECT data FROM {$this->table} WHERE {$this->idColumn} = ? AND " . self::LIVE_ROW,
            [$id, ...$this->liveParameters()],
        );

        try {
            // Under a savepoint in the caller's transaction, so a failure leaves it able to run the unlock.
            $row = $this->database()->inTransaction() ? $this->database()->transaction($select) : $select();
            $payload = $row === null ? null : $this->decodePayload($row->data);
        } catch (Throwable $failure) {
            // PHP does not call close() when read() throws out of session_start().
            $this->idStates[$id] = self::STATE_READ_FAILED;
            $this->releaseLock();

            throw $failure;
        }

        if ($payload === null) {
            // The lock is not kept for a new session, so requests admitted before a logout
            // may race to recreate this id.
            $this->idStates[$id] = self::STATE_CREATED;
            $this->releaseLock();

            return '';
        }

        $this->idStates[$id] = self::STATE_RESUMED;

        return $payload;
    }

    /**
     * Whether an id has the shape of a session id. Checked on every entry point, because the id is a primary key.
     */
    private function isValidId(string $id): bool
    {
        return preg_match($this->idPattern, $id) === 1;
    }

    /**
     * Persist the session payload.
     *
     * The statement depends on what read() saw. A resumed session gets a bare UPDATE, so a row deleted in the
     * meantime stays deleted. A created session gets one INSERT ... ON CONFLICT DO UPDATE, so two concurrent
     * creators cannot collide, unless its payload is empty: then nothing is stored. A session whose read() failed, or an id never read, is not written.
     *
     * Requires the id column to be a PRIMARY KEY or carry a UNIQUE constraint.
     */
    public function write(string $id, string $data): bool
    {
        return $this->writeRow($id, $data === '', function (bool $resumed) use ($id, $data): int {
            $stored = self::encodePayload($data);
            $access = time();
            $expire = $access + $this->maxLifetime();

            if ($resumed) {
                return $this->database()->execute(
                    "UPDATE {$this->table}
                        SET access = ?, expire = ?, data = ?
                      WHERE {$this->idColumn} = ?",
                    [$access, $expire, $stored, $id],
                );
            }

            return $this->database()->execute(
                "INSERT INTO {$this->table} ({$this->idColumn}, access, expire, data)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT ({$this->idColumn}) DO UPDATE
                 SET access = EXCLUDED.access, expire = EXCLUDED.expire, data = EXCLUDED.data",
                [$id, $access, $expire, $stored],
            );
        });
    }

    /**
     * Stored verbatim when a TEXT column keeps it intact, otherwise base64 behind the prefix.
     */
    private static function encodePayload(string $data): string
    {
        $verbatim = !str_contains($data, "\0")
            && mb_check_encoding($data, 'UTF-8')
            && !str_starts_with($data, self::ENCODED_PAYLOAD_PREFIX);

        return $verbatim ? $data : self::ENCODED_PAYLOAD_PREFIX . base64_encode($data);
    }

    /**
     * @throws SessionException when the data column holds something other than text.
     */
    private function decodePayload(mixed $stored): string
    {
        if (!is_string($stored)) {
            throw SessionException::dataColumnNotText($this->table, get_debug_type($stored));
        }

        if (!str_starts_with($stored, self::ENCODED_PAYLOAD_PREFIX)) {
            return $stored;
        }

        $encoded = substr($stored, strlen(self::ENCODED_PAYLOAD_PREFIX));
        $payload = base64_decode($encoded, true);

        // Only canonical base64 is decoded: a raw row written by an older version may start with the prefix.
        return $payload !== false && base64_encode($payload) === $encoded ? $payload : $stored;
    }

    /**
     * The checks shared by write() and updateTimestamp(), then the lock release that ends both.
     *
     * @param bool $nothingToStore A created session is not stored and the call succeeds without a statement.
     * @param callable(bool): int $statement Told whether read() resumed a stored session; returns the rows it touched.
     */
    private function writeRow(string $id, bool $nothingToStore, callable $statement): bool
    {
        if (!$this->isValidId($id)) {
            return false;
        }

        $state = $this->idStates[$id] ?? null;

        try {
            return match ($state) {
                null => self::refuseUnreadId(),
                // Writing would rebuild the row destroy() removed.
                self::STATE_DESTROYED => true,
                self::STATE_READ_FAILED => false,
                self::STATE_CREATED => $nothingToStore || $statement(false) > 0,
                self::STATE_RESUMED => $statement(true) > 0,
            };
        } finally {
            $this->releaseLock();
        }
    }

    private static function refuseUnreadId(): false
    {
        trigger_error(
            'DatabaseSessionHandler refused to write a session it never read, so nothing is stored. '
            . 'A handler wrapping it must delegate read() before write() or updateTimestamp().',
            E_USER_WARNING,
        );

        return false;
    }

    /**
     * Delete the session row. Returns true once no row remains, so session_regenerate_id(true) can proceed.
     * A failing statement throws.
     */
    public function destroy(string $id): bool
    {
        if (!$this->isValidId($id)) {
            // An invalid id cannot have been stored, so the session is already destroyed.
            return true;
        }

        try {
            $this->database()->execute(
                "DELETE FROM {$this->table} WHERE {$this->idColumn} = ?",
                [$id],
            );
            $this->idStates[$id] = self::STATE_DESTROYED;
        } finally {
            if ($id === $this->lockedId) {
                $this->releaseLock();
            }
        }

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $threshold = time() - $max_lifetime;

        return $this->database()->execute(
            "DELETE FROM {$this->table} WHERE access < ?",
            [$threshold],
        );
    }

    /** @return array{int, int} The parameters of LIVE_ROW. */
    private function liveParameters(): array
    {
        $now = time();

        return [$now, $now - $this->maxLifetime()];
    }

    private function maxLifetime(): int
    {
        return (int) ini_get('session.gc_maxlifetime');
    }

    /**
     * Take the advisory lock for the id, PostgreSQL only. Released by write(), updateTimestamp(), destroy() or close().
     *
     * write() sends the whole payload, so a concurrent writer that finished later would restore a stale snapshot.
     * The lock is held by the connection rather than a transaction, so it survives the application's own
     * transactions, which a SELECT ... FOR UPDATE held across the request would not. Taken in read() because
     * open() is not told the id. Best-effort (see bestEffort() and waitForLock()); other drivers take no lock.
     */
    private function acquireLock(string $id): void
    {
        if (!$this->locking || $this->lockedId === $id || !$this->supportsAdvisoryLocks()) {
            return;
        }

        $this->releaseLock();

        // The release may have found the previous lock gone and turned locking off.
        if (!$this->locking) {
            return;
        }

        $key = [self::ADVISORY_LOCK_NAMESPACE, self::advisoryLockKey($id)];

        $granted = $this->bestEffort(
            fn (): bool => $this->database()->selectBool('SELECT pg_try_advisory_lock(?, ?)', $key),
        );

        if ($granted === true || ($granted === false && $this->waitForLock($key))) {
            $this->lockedId = $id;
        }
    }

    /**
     * Block until the lock is granted or LOCK_WAIT_SECONDS pass.
     *
     * The lock_timeout is set inside the transaction or savepoint of the wait and rolled back with it,
     * so a pooler cannot split the two, and the timeout never outlives the wait. The session-level
     * advisory lock survives that rollback and is still returned as granted.
     *
     * @param array{int, int} $key
     */
    private function waitForLock(array $key): bool
    {
        $nested = $this->database()->inTransaction();

        try {
            if ($nested) {
                $this->database()->query('SAVEPOINT ' . self::LOCK_SAVEPOINT);
            } else {
                $this->database()->pdo()->beginTransaction();
            }
        } catch (Throwable) {
            return false;
        }

        try {
            $this->database()->query("SELECT set_config('lock_timeout', ?, true)", [self::LOCK_WAIT_SECONDS . 's']);
            $this->database()->query('SELECT pg_advisory_lock(?, ?)', $key);
            $granted = true;
        } catch (Throwable) {
            $granted = false;
        }

        try {
            if ($nested) {
                $this->database()->query('ROLLBACK TO SAVEPOINT ' . self::LOCK_SAVEPOINT);
                $this->database()->query('RELEASE SAVEPOINT ' . self::LOCK_SAVEPOINT);
            } else {
                $this->database()->pdo()->rollBack();
            }
        } catch (Throwable) {
            // Only a lost connection gets here.
        }

        return $granted;
    }

    private function releaseLock(): void
    {
        if ($this->lockedId === null) {
            return;
        }

        $key = [self::ADVISORY_LOCK_NAMESPACE, self::advisoryLockKey($this->lockedId)];
        $released = $this->bestEffort(
            fn (): bool => $this->database()->selectBool('SELECT pg_advisory_unlock(?, ?)', $key),
        );

        // Kept on failure so close() can retry.
        if ($released === null) {
            return;
        }

        $this->lockedId = null;

        if (!$released) {
            $this->stopLocking();
        }
    }

    /** The unlock reached a connection that does not hold the lock, which is still held elsewhere. */
    private function stopLocking(): void
    {
        $this->locking = false;
        trigger_error(
            'DatabaseSessionHandler released a session lock on a database connection that does not hold it, so the '
            . 'lock stays held on another server connection. Transaction pooling (PgBouncer pool_mode=transaction) '
            . 'causes this: construct the handler with lockSessions: false there. Locking is now off for the rest of '
            . 'this request.',
            E_USER_WARNING,
        );
    }

    /**
     * Run a lock statement without letting its failure escape: locking is never a gate.
     *
     * Inside the caller's transaction the statement runs under its own savepoint, so a failure cannot abort it.
     *
     * @template T
     * @param callable(): T $statement
     * @return T|null Null when the statement failed.
     */
    private function bestEffort(callable $statement): mixed
    {
        try {
            return $this->database()->inTransaction() ? $this->database()->transaction($statement) : $statement();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The objid half of the advisory lock. Derived in PHP, not with hashtext(), which is an undocumented internal.
     *
     * A crc32 collision serializes two unrelated sessions for one short wait, which is an acceptable cost.
     */
    private static function advisoryLockKey(string $id): int
    {
        $hash = crc32($id);

        return $hash >= 0x80000000 ? $hash - 0x100000000 : $hash;
    }

    private function supportsAdvisoryLocks(): bool
    {
        if ($this->driver === null) {
            $database = $this->database();

            try {
                $this->driver = (string) $database->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
            } catch (Throwable) {
                $this->driver = '';
            }
        }

        return $this->driver === 'pgsql';
    }
}
