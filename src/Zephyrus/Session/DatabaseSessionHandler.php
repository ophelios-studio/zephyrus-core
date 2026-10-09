<?php

declare(strict_types=1);

namespace Zephyrus\Session;

use PDO;
use Throwable;
use Zephyrus\Data\Database;

/**
 * SessionHandlerInterface implementation that stores session data in a
 * database table.
 *
 * Requires a backend supporting INSERT ... ON CONFLICT DO UPDATE, which covers
 * PostgreSQL 9.5+ (the only driver DatabaseConfig accepts) and SQLite 3.24+
 * (used by this project's tests).
 *
 * Expected table schema:
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
 * ## The index has to be on `access`, not on `expire`
 *
 * gc() deletes rows by comparing **access**, not expire, so `access` is the
 * column that needs the index. Indexing `expire` instead looks reasonable and
 * does nothing: the sweep degrades to a sequential scan and the index is never
 * used. That mistake has already been made in a project using this handler, and
 * it is invisible until the table is large, so it is spelled out here. Check it
 * with pg_stat_user_indexes: an index with zero scans is the symptom.
 *
 * ## `expire` is ENFORCED, not decoration
 *
 * validateId() and read() both carry an `expire > now` predicate, so a row past
 * its expiry is neither adopted nor readable even when garbage collection never
 * ran. It previously was not: both asked only whether the row existed, `expire`
 * was written and read by nothing, and expiry rested entirely on PHP's GC
 * lottery, which Debian and Ubuntu disable outright (session.gc_probability=0).
 * A 30-day-old row was resumed with its payload intact. Both also refuse a row
 * idle longer than the current session.gc_maxlifetime, so lowering it applies
 * at once.
 *
 * DEPLOYMENT NOTE, because this is a cliff and not a ramp: the first request
 * after this change goes live invalidates EVERY row already past `expire`, in
 * one step. On a table that has never been swept that is a mass logout. Prune
 * the expired rows BEFORE deploying so the cliff is empty:
 *
 *   DELETE FROM <table> WHERE expire < EXTRACT(EPOCH FROM now())::bigint;
 *
 * ## Columns this handler does not write
 *
 * The INSERT lists exactly session_id, access, expire and data. If your table
 * carries anything else, typically ip_address or user_agent for auditing, this
 * handler never populates it and the column stays NULL forever. Either give
 * those columns a default, populate them from your own code, or drop them.
 * Do not build a feature on one expecting this handler to fill it.
 *
 * ## How `data` is stored
 *
 * A payload that is valid UTF-8 without a NUL byte is stored verbatim. Any
 * other (an object with a private or protected property, raw bytes) is stored
 * as "base64:" followed by its base64 encoding, which a TEXT column keeps
 * intact. A query that searches `data` itself matches only verbatim rows.
 *
 * ## Registration
 *
 * Register through SessionManager, which owns the session ini settings:
 *
 *   $session = new SessionManager();
 *   $session->setHandler(new DatabaseSessionHandler($db));
 *   $session->start($config->session);
 *
 * Registering straight through session_set_save_handler() also works, and the
 * constructor turns session.use_strict_mode on itself so that path is not a
 * silent downgrade, but SessionManager is the supported route because it
 * configures the cookie flags in the same call.
 *
 * ## A wrapper MUST delegate close()
 *
 * A consumer that wraps this handler rather than extending it (to resolve the
 * Database lazily, say) has to forward open() and close() as well as the data
 * callbacks. close() is where the advisory lock taken by read() is released on
 * the paths that never write, and a wrapper answering `true` without delegating
 * holds that lock until the connection closes.
 *
 * ## Connection pooling
 *
 * The advisory lock belongs to the server connection. Behind transaction
 * pooling (PgBouncer pool_mode=transaction) construct the handler with
 * lockSessions: false. An unlock that finds no lock on its connection raises
 * a warning and turns locking off for the rest of the request.
 */
final class DatabaseSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    /**
     * PHP's own session-id alphabet across every sid_bits_per_character setting
     * (4 gives 0-9a-f, 5 gives 0-9a-v, 6 gives 0-9a-zA-Z,-), and the full length
     * range session.sid_length accepts, which is 22 to 256.
     *
     * Deliberately matched to PHP's documented range rather than to the default
     * 32, so an application that tunes sid_length cannot have every one of its
     * legitimate ids rejected.
     *
     * Anchored with \A and \z, never ^ and $. PCRE's `$` also matches before a
     * final newline, so the previous pattern accepted 32 'a' characters plus a
     * trailing "\n" and stored it as a second, distinct primary key. Nothing
     * reaches it through PHP today (PHP validates the cookie character set
     * before the handler sees the id), so it was latent rather than live, but
     * the id column is a primary key and the pattern is the only thing bounding
     * what can land in it.
     */
    public const DEFAULT_ID_PATTERN = '/\A[A-Za-z0-9,-]{22,256}\z/';

    /**
     * The classid half of every advisory lock this handler takes: the ASCII
     * bytes of "ZEPH" read as a 32-bit integer.
     *
     * WHY A COLLISION WITH AN APPLICATION'S OWN LOCKS IS NOT POSSIBLE BY
     * ACCIDENT. PostgreSQL advisory locks come in two forms, one bigint key or
     * two int4 keys, and the documentation is explicit that the two key spaces
     * DO NOT OVERLAP. This handler only ever uses the two-key form, so a lock
     * taken as pg_advisory_lock(<bigint>) can never contend with it whatever
     * the number is. An application using the two-key form contends only if it
     * picks this exact classid.
     *
     * A consumer checks its own exposure with one query, and an empty result
     * means there is nothing to reconcile:
     *
     *   SELECT * FROM pg_locks
     *    WHERE locktype = 'advisory' AND classid = 1514491976 AND objsubid = 2;
     */
    public const ADVISORY_LOCK_NAMESPACE = 0x5A455048;

    /**
     * How long read() waits for a contended session before continuing WITHOUT
     * the lock, in seconds, applied as lock_timeout to a blocking
     * pg_advisory_lock().
     *
     * Bounded on purpose: a lock that is never released (a request that dies
     * between read() and close() on a connection the pool keeps alive) would
     * otherwise hang every later request for that session. Giving up costs a
     * lost update at worst; hanging is an outage.
     */
    private const LOCK_WAIT_SECONDS = 5;

    /**
     * A row is live before its stored expiry AND while idle for less than the
     * CURRENT lifetime: the expiry was stamped under the lifetime of the last
     * write, so a lowered timeout must not wait for it.
     */
    private const LIVE_ROW = 'expire > ? AND access > ?';

    /** Marks a payload stored as base64 because the data column cannot hold it verbatim. */
    private const ENCODED_PAYLOAD_PREFIX = 'base64:';

    /** Isolates a lock statement issued inside the caller's transaction. */
    private const LOCK_SAVEPOINT = 'zephyrus_session_lock';

    /** read() found a live row: this request RESUMED an existing session. */
    private const STATE_RESUMED = 'resumed';

    /** read() found no live row: this request is CREATING the session. */
    private const STATE_CREATED = 'created';

    /** destroy() ran: the row is deliberately gone and must stay gone. */
    private const STATE_DESTROYED = 'destroyed';

    /** read() failed: the stored payload is unknown, so nothing may replace it. */
    private const STATE_READ_FAILED = 'read_failed';

    /**
     * What THIS request knows about each session id it has handled.
     *
     * The values are self::STATE_* and the map is per-request, because the
     * handler instance is. An id absent from it was never read.
     *
     * @var array<string, self::STATE_*>
     */
    private array $idStates = [];

    /** The id whose advisory lock this handler currently holds, if any. */
    private ?string $lockedId = null;

    /** Whether read() takes the advisory lock; off for the request once an unlock lands on another connection. */
    private bool $locking;

    /** Memoized PDO driver name; '' once a lookup has failed. */
    private ?string $driver = null;

    /**
     * @param string $idPattern Overridable for an application that generates
     *   session ids itself in some other shape. The default only accepts ids
     *   PHP could have produced.
     * @param bool $lockSessions Serialize the concurrent requests of one
     *   session (PostgreSQL only); without it the later of two writers
     *   overwrites the other's changes. The lock belongs to the server
     *   connection, so it needs a direct connection or session-mode pooling
     *   (PgBouncer pool_mode=session). Pass false whenever connections go
     *   through transaction or statement pooling (PgBouncer
     *   pool_mode=transaction or statement): there an unlock can reach another
     *   server connection, the lock stays until that connection closes, and
     *   the server's shared lock table fills until PostgreSQL refuses every
     *   client with "out of shared memory". An unlock that finds no lock warns
     *   and turns locking off for the rest of the request: that detects the
     *   leak without preventing it, since the next request locks again, so
     *   false is the only fix there.
     */
    public function __construct(
        private readonly Database $database,
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
     * SessionManager::start() already forces it, but this class documents a
     * registration that never goes through SessionManager:
     *
     *   session_set_save_handler(new DatabaseSessionHandler($db), true);
     *
     * Followed verbatim, that left the flag at PHP's default of 0 and an
     * attacker-chosen id was adopted. validateId() below cannot catch it,
     * because PHP only calls validateId() when strict mode is ON, so the one
     * place the gap can be closed is before session_start() runs.
     *
     * Skipped when the session is already active or when output has begun,
     * which are exactly the two conditions under which PHP refuses a
     * session.* ini change and emits a warning.
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
     * Decide whether PHP may ADOPT a client-supplied session id.
     *
     * THIS METHOD IS WHY THIS CLASS IMPLEMENTS SessionUpdateTimestampHandlerInterface.
     * PHP skips its session.use_strict_mode check entirely for a save handler
     * that does not supply validateId(), so setting the ini flag was inert here:
     * a plain handler adopted whatever id the client sent, verbatim, and stored
     * a row under it. Verified both ways:
     *
     *   strict mode on, plain handler        -> session_id() = attackerchosenid123
     *   strict mode on, validateId handler   -> session_id() = 43e880c2447c...
     *
     * ## What this does and does not buy
     *
     * It removes ONE step from session fixation, not the attack. An attacker
     * does not need to invent an id: SessionMiddleware starts the session
     * eagerly, so any request, including one that matches no route, mints and
     * persists a server-blessed id the attacker can simply fetch and plant.
     * The control that actually defeats fixation is ROTATING the id at every
     * privilege change (login, second factor, logout, password change), because
     * a planted id then never survives into an authenticated session. Refusing
     * an unknown id is defence in depth layered on that, and it is also what
     * stops an unauthenticated caller from seeding rows of its own choosing.
     *
     * The liveness predicate makes an expired row answer the same as a missing
     * one, so a stale id is discarded rather than resumed.
     */
    public function validateId(string $id): bool
    {
        if (!$this->isValidId($id)) {
            return false;
        }

        return $this->database->selectOne(
            "SELECT {$this->idColumn} FROM {$this->table} WHERE {$this->idColumn} = ? AND " . self::LIVE_ROW,
            [$id, ...$this->liveParameters()],
        ) !== null;
    }

    /**
     * Refresh the access window of an UNCHANGED session without rewriting the
     * payload.
     *
     * PHP calls this instead of write() whenever the session data did not
     * change, which is most requests, so it is also the cheaper path: it never
     * ships the serialized payload back to the database.
     *
     * ## A bare UPDATE, with no insert branch
     *
     * An upsert would re-create a row DELETED while the request was in flight,
     * undoing a logout, a sign-out-everywhere or a password-reset eviction. PHP
     * calls write(), not this method, for a session with no stored row yet.
     *
     * An UPDATE that matches nothing returns false: the row was removed while
     * the request was in flight (a logout elsewhere, an eviction, garbage
     * collection) and nothing was refreshed. PHP reports it as a warning.
     *
     * An id this handler never read is refused, as in write().
     */
    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->writeRow($id, function () use ($id): int {
            $access = time();

            return $this->database->execute(
                "UPDATE {$this->table}
                    SET access = ?, expire = ?
                  WHERE {$this->idColumn} = ?",
                [$access, $access + $this->maxLifetime(), $id],
            );
        });
    }

    /**
     * Load the session payload, taking the advisory lock that makes a
     * concurrent write safe.
     *
     * An expired row reads as absent. The row is deliberately left in place
     * rather than deleted here: gc() owns removal, and a read path that writes
     * turns every page load into a write transaction.
     */
    public function read(string $id): string
    {
        if (!$this->isValidId($id)) {
            return '';
        }

        $this->acquireLock($id);

        $select = fn (): ?\stdClass => $this->database->selectOne(
            "SELECT data FROM {$this->table} WHERE {$this->idColumn} = ? AND " . self::LIVE_ROW,
            [$id, ...$this->liveParameters()],
        );

        try {
            // Under a savepoint in the caller's transaction, so a failure leaves it able to run the unlock.
            $row = $this->database->inTransaction() ? $this->database->transaction($select) : $select();
        } catch (Throwable $failure) {
            // PHP does not call close() when read() throws out of session_start().
            $this->idStates[$id] = self::STATE_READ_FAILED;
            $this->releaseLock();

            throw $failure;
        }

        if ($row === null) {
            // Not held for a new session. Requests admitted before a logout may still race to recreate this id.
            $this->idStates[$id] = self::STATE_CREATED;
            $this->releaseLock();

            return '';
        }

        $this->idStates[$id] = self::STATE_RESUMED;

        return self::decodePayload((string) $row->data);
    }

    /**
     * Whether an id is shaped like a session id at all.
     *
     * Checked on every entry point, not only in validateId(), because the id
     * lands in a PRIMARY KEY column. Without it, probing produced real rows
     * keyed on values such as "secX/../-marker-18537", containing a slash and
     * dot segments, and another at 250 characters. validateId() gates adoption;
     * this bounds what can ever be stored.
     */
    private function isValidId(string $id): bool
    {
        return preg_match($this->idPattern, $id) === 1;
    }

    /**
     * Persist the session payload.
     *
     * ## Which statement runs depends on what read() saw
     *
     * A session this request RESUMED is written with a bare UPDATE. If the row
     * has been deleted in the meantime, the UPDATE matches nothing, write()
     * returns false and the session stays gone: an upsert would re-create it
     * and undo a logout, a sign-out-everywhere or a password-reset eviction.
     *
     * A session whose read() failed is not written at all and write() returns
     * false: the stored payload is unknown, and replacing it with the empty one
     * PHP was handed would sign the user out under the same cookie.
     *
     * A session this request CREATED (read() found nothing) is written with an
     * INSERT ... ON CONFLICT DO UPDATE: one atomic statement, so two concurrent
     * requests creating the same id cannot collide on the primary key.
     *
     * An id this handler never read is not written: write() raises a warning
     * and returns false. PHP always reads first, so only a wrapper that skipped
     * read() gets here, with a payload built without the stored one.
     *
     * Requires the id column to be a PRIMARY KEY or carry a UNIQUE constraint,
     * which it needs anyway to be a session table.
     */
    public function write(string $id, string $data): bool
    {
        return $this->writeRow($id, function (bool $resumed) use ($id, $data): int {
            $stored = self::encodePayload($data);
            $access = time();
            $expire = $access + $this->maxLifetime();

            if ($resumed) {
                return $this->database->execute(
                    "UPDATE {$this->table}
                        SET access = ?, expire = ?, data = ?
                      WHERE {$this->idColumn} = ?",
                    [$access, $expire, $stored, $id],
                );
            }

            return $this->database->execute(
                "INSERT INTO {$this->table} ({$this->idColumn}, access, expire, data)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT ({$this->idColumn}) DO UPDATE
                 SET access = EXCLUDED.access, expire = EXCLUDED.expire, data = EXCLUDED.data",
                [$id, $access, $expire, $stored],
            );
        });
    }

    /**
     * The payload as stored: verbatim when a TEXT column keeps it intact, base64
     * behind the prefix otherwise or when it already starts with the prefix.
     */
    private static function encodePayload(string $data): string
    {
        $verbatim = !str_contains($data, "\0")
            && mb_check_encoding($data, 'UTF-8')
            && !str_starts_with($data, self::ENCODED_PAYLOAD_PREFIX);

        return $verbatim ? $data : self::ENCODED_PAYLOAD_PREFIX . base64_encode($data);
    }

    private static function decodePayload(string $stored): string
    {
        if (!str_starts_with($stored, self::ENCODED_PAYLOAD_PREFIX)) {
            return $stored;
        }

        $encoded = substr($stored, strlen(self::ENCODED_PAYLOAD_PREFIX));
        $payload = base64_decode($encoded, true);

        // A row written raw by an earlier version can start with the prefix too: decode canonical base64 only.
        return $payload !== false && base64_encode($payload) === $encoded ? $payload : $stored;
    }

    /**
     * The checks write() and updateTimestamp() share, then the lock release
     * that ends both. A statement that throws inside a caller's transaction
     * aborts it, and the release then fails until that transaction ends.
     *
     * @param callable(bool): int $statement Told whether read() resumed a
     *   stored session (false when it found none); returns the rows it touched.
     */
    private function writeRow(string $id, callable $statement): bool
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
                self::STATE_RESUMED, self::STATE_CREATED => $statement($state === self::STATE_RESUMED) > 0,
            };
        } finally {
            $this->releaseLock();
        }
    }

    private static function refuseUnreadId(): false
    {
        trigger_error(
            'DatabaseSessionHandler refused to write a session it never read, so the changes are lost. '
            . 'A handler wrapping it must delegate read() before write() or updateTimestamp().',
            E_USER_WARNING,
        );

        return false;
    }

    /**
     * Returns true once no row is stored under the id, whether this call
     * deleted it or it was already gone: session_regenerate_id(true) fails when
     * this returns false. A statement that fails throws.
     */
    public function destroy(string $id): bool
    {
        if (!$this->isValidId($id)) {
            // Nothing could have been stored under it, so the session is
            // already in the state destroy() promises. Reporting failure here
            // would break a logout flow for no gain.
            return true;
        }

        try {
            $this->database->execute(
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

        return $this->database->execute(
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
     * Serialize the requests that share a session id, PostgreSQL only.
     *
     * ## Why a lock is needed at all
     *
     * write() hands the database the WHOLE payload, because that is what PHP
     * gives a save handler, so the later of two concurrent writers restores its
     * own stale snapshot over everything the earlier one committed.
     *
     * It is not a theoretical race for this framework: CsrfMiddleware mints the
     * token lazily on the RESPONSE, so a page load racing any parallel XHR
     * embeds a token that a second writer can drop, and the user gets a 403 on
     * submit.
     *
     * ## Why an advisory lock and not SELECT ... FOR UPDATE
     *
     * FOR UPDATE would mean holding a transaction open from read() to write(),
     * i.e. for the whole request, on a connection the application shares. Every
     * query the application runs would join that transaction, its own
     * Database::transaction() call would fail to begin a nested one, and its
     * COMMIT would release the session lock early. A PostgreSQL advisory lock is
     * held by the CONNECTION rather than by a transaction, so it survives the
     * application's own transactions.
     *
     * Inside the caller's transaction each lock statement runs under its own
     * savepoint (see bestEffort()), so its failure cannot abort that
     * transaction.
     *
     * Taken in read() rather than open(), because open() is not told the
     * session id. Released by write(), updateTimestamp(), destroy() and
     * close(), so the paths a wrapper commonly forwards all release it.
     *
     * Best-effort by design (see LOCK_WAIT_SECONDS). A driver other than pgsql
     * takes no lock at all.
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
            fn (): bool => $this->database->selectBool('SELECT pg_try_advisory_lock(?, ?)', $key),
        );

        if ($granted === true || ($granted === false && $this->waitForLock($key))) {
            $this->lockedId = $id;
        }
    }

    /**
     * Block until the lock is granted or LOCK_WAIT_SECONDS pass.
     *
     * The bound is a SET LOCAL in the same transaction (or savepoint) as the
     * wait, so a transaction-pooling proxy cannot split them across backends.
     * That scope is always rolled back: the bound cannot outlive the wait,
     * and a session-level advisory lock survives the rollback.
     *
     * @param array{int, int} $key
     */
    private function waitForLock(array $key): bool
    {
        $nested = $this->database->inTransaction();

        try {
            if ($nested) {
                $this->database->query('SAVEPOINT ' . self::LOCK_SAVEPOINT);
            } else {
                $this->database->pdo()->beginTransaction();
            }
        } catch (Throwable) {
            return false;
        }

        try {
            $this->database->query("SELECT set_config('lock_timeout', ?, true)", [self::LOCK_WAIT_SECONDS . 's']);
            $this->database->query('SELECT pg_advisory_lock(?, ?)', $key);
            $granted = true;
        } catch (Throwable) {
            $granted = false;
        }

        try {
            if ($nested) {
                $this->database->query('ROLLBACK TO SAVEPOINT ' . self::LOCK_SAVEPOINT);
                $this->database->query('RELEASE SAVEPOINT ' . self::LOCK_SAVEPOINT);
            } else {
                $this->database->pdo()->rollBack();
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
            fn (): bool => $this->database->selectBool('SELECT pg_advisory_unlock(?, ?)', $key),
        );

        // Kept on failure so close() can retry; inside an aborted caller transaction that retry fails too.
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
     * Run a lock statement without letting its failure escape: locking is
     * never a gate. Inside the caller's transaction it runs under its own
     * savepoint, rolled back on failure, so the caller's work survives.
     *
     * @template T
     * @param callable(): T $statement
     * @return T|null Null when the statement failed.
     */
    private function bestEffort(callable $statement): mixed
    {
        $isolated = $this->database->inTransaction();

        try {
            if ($isolated) {
                $this->database->query('SAVEPOINT ' . self::LOCK_SAVEPOINT);
            }
        } catch (Throwable) {
            // The caller's transaction is already aborted.
            return null;
        }

        try {
            $result = $statement();
        } catch (Throwable) {
            $result = null;
        }

        if ($isolated) {
            try {
                if ($result === null) {
                    $this->database->query('ROLLBACK TO SAVEPOINT ' . self::LOCK_SAVEPOINT);
                }

                $this->database->query('RELEASE SAVEPOINT ' . self::LOCK_SAVEPOINT);
            } catch (Throwable) {
                // Only a lost connection gets here.
            }
        }

        return $result;
    }

    /**
     * The objid half of the advisory lock, derived in PHP rather than with
     * PostgreSQL's hashtext() so the value does not depend on an undocumented
     * internal whose hash has changed across major versions.
     *
     * crc32 is a 32-bit space, so two unrelated session ids can collide and
     * serialize against each other. That costs one request a short wait and
     * nothing else, which is why a cheap hash is the right trade here.
     */
    private static function advisoryLockKey(string $id): int
    {
        $hash = crc32($id);

        return $hash >= 0x80000000 ? $hash - 0x100000000 : $hash;
    }

    private function supportsAdvisoryLocks(): bool
    {
        if ($this->driver === null) {
            try {
                $this->driver = (string) $this->database->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
            } catch (Throwable) {
                $this->driver = '';
            }
        }

        return $this->driver === 'pgsql';
    }
}
