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
 * A 30-day-old row was resumed with its payload intact.
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
 * callbacks. close() is where the row lock taken by read() is released on the
 * paths that never write, and a wrapper answering `true` without delegating
 * holds that lock until the connection closes.
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
     * the lock, in seconds.
     *
     * Bounded on purpose. A blocking pg_advisory_lock() would be the stricter
     * guarantee, but a lock that is never released (a request that dies between
     * read() and close() on a connection the pool keeps alive) would then hang
     * every later request for that session forever. Giving up returns the
     * session to the unlocked behaviour it had before, which is a lost update at
     * worst; hanging is an outage. Measured against a deliberately leaked lock
     * on PostgreSQL 16: the next request waited 5.01s, then read the session
     * normally.
     */
    private const LOCK_WAIT_SECONDS = 5;

    /** Delay between two attempts at a contended session lock, in microseconds. */
    private const LOCK_POLL_INTERVAL = 20000;

    /** read() found no usable row: this request is creating the session. */
    private const STATE_NEW = 'new';

    /** read() found a live row: this request RESUMED an existing session. */
    private const STATE_RESUMED = 'resumed';

    /** destroy() ran: the row is deliberately gone and must stay gone. */
    private const STATE_DESTROYED = 'destroyed';

    /**
     * What THIS request knows about each session id it has handled.
     *
     * The values are self::STATE_* and the map is per-request, because the
     * handler instance is. It exists so that write() can tell "the row I read
     * has since been deleted" from "I am creating this session", which is the
     * whole of the resurrection fix below.
     *
     * @var array<string, string>
     */
    private array $idStates = [];

    /** The id whose advisory lock this handler currently holds, if any. */
    private ?string $lockedId = null;

    /** Memoized PDO driver name; '' once a lookup has failed. */
    private ?string $driver = null;

    /**
     * @param string $idPattern Overridable for an application that generates
     *   session ids itself in some other shape. The default only accepts ids
     *   PHP could have produced.
     * @param bool $lockSessions Serialize concurrent requests that share a
     *   session id (PostgreSQL only). Turn it off only if whole-request
     *   serialization is unacceptable and losing a concurrent write is not.
     */
    public function __construct(
        private readonly Database $database,
        private readonly string $table = 'public.session',
        private readonly string $idColumn = 'session_id',
        private readonly string $idPattern = self::DEFAULT_ID_PATTERN,
        private readonly bool $lockSessions = true,
    ) {
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
     * The expiry predicate makes an expired row answer the same as a missing
     * one, so a stale id is discarded rather than resumed.
     */
    public function validateId(string $id): bool
    {
        if (!$this->isValidId($id)) {
            return false;
        }

        return $this->database->selectOne(
            "SELECT {$this->idColumn} FROM {$this->table} WHERE {$this->idColumn} = ? AND expire > ?",
            [$id, time()],
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
     * It used to be an INSERT ... ON CONFLICT DO UPDATE, which re-created a row
     * that had been DELETED while the request was in flight. That undid logout,
     * sign-out-everywhere and the session eviction a password reset performs:
     * a request already open when the delete landed wrote the whole
     * authenticated payload straight back under the same id. Reproduced against
     * the previous code on both SQLite and PostgreSQL 16: read the row, delete
     * it from another connection, call this method, and the row is back.
     *
     * An UPDATE that matches nothing is the correct outcome here, so this
     * returns true either way: the session is gone because something removed
     * it on purpose, and reporting a write failure would make PHP warn on every
     * in-flight request during an ordinary logout.
     *
     * Nothing is lost by dropping the insert branch. PHP calls write(), not
     * this method, for a session that has no stored row yet: measured on 8.5,
     * a brand-new session with an empty payload produces `write('')`, and this
     * method is reached only for a session that was read back from storage.
     */
    public function updateTimestamp(string $id, string $data): bool
    {
        if (!$this->isValidId($id)) {
            return false;
        }

        if (($this->idStates[$id] ?? null) === self::STATE_DESTROYED) {
            $this->releaseLock();

            return true;
        }

        $access = time();

        $this->database->execute(
            "UPDATE {$this->table}
                SET access = ?, expire = ?
              WHERE {$this->idColumn} = ?",
            [$access, $access + $this->maxLifetime(), $id],
        );

        $this->releaseLock();

        return true;
    }

    /**
     * Load the session payload, taking the row lock that makes a concurrent
     * write safe.
     *
     * An expired row reads as absent. The row is deliberately left in place
     * rather than deleted here: gc() owns removal, and a read path that writes
     * turns every page load into a write transaction.
     */
    public function read(string $id): string|false
    {
        if (!$this->isValidId($id)) {
            return '';
        }

        $this->acquireLock($id);

        $row = $this->database->selectOne(
            "SELECT data FROM {$this->table} WHERE {$this->idColumn} = ? AND expire > ?",
            [$id, time()],
        );

        $this->idStates[$id] = $row !== null ? self::STATE_RESUMED : self::STATE_NEW;

        return $row !== null ? $row->data : '';
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
     * has been deleted in the meantime, the UPDATE matches nothing and the
     * session stays gone, which is the point: the previous unconditional upsert
     * re-created it, payload and all, so any in-flight request undid a logout,
     * a sign-out-everywhere, or the session eviction a password reset performs.
     *
     * A session this request CREATED (read() found nothing) is written with an
     * INSERT ... ON CONFLICT DO UPDATE, and so is an id this handler has never
     * seen. That upsert is deliberate and predates this: write() used to SELECT
     * for an existing row and then INSERT or UPDATE, a check-then-act race in
     * which two concurrent requests carrying the same NEW session id both saw
     * no row, both INSERTed, and the loser died on a duplicate-key violation.
     * The symptom was silent, because handlers are commonly wrapped to swallow
     * write failures so a session problem cannot break a page render.
     *
     * Requires the id column to be a PRIMARY KEY or carry a UNIQUE constraint,
     * which it needs anyway to be a session table.
     */
    public function write(string $id, string $data): bool
    {
        if (!$this->isValidId($id)) {
            return false;
        }

        $state = $this->idStates[$id] ?? null;

        if ($state === self::STATE_DESTROYED) {
            // destroy() ran in this same request. Writing here would rebuild
            // exactly the row the caller asked to remove.
            $this->releaseLock();

            return true;
        }

        $access = time();
        $expire = $access + $this->maxLifetime();

        if ($state === self::STATE_RESUMED) {
            $this->database->execute(
                "UPDATE {$this->table}
                    SET access = ?, expire = ?, data = ?
                  WHERE {$this->idColumn} = ?",
                [$access, $expire, $data, $id],
            );
        } else {
            $this->database->execute(
                "INSERT INTO {$this->table} ({$this->idColumn}, access, expire, data)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT ({$this->idColumn}) DO UPDATE
                 SET access = EXCLUDED.access, expire = EXCLUDED.expire, data = EXCLUDED.data",
                [$id, $access, $expire, $data],
            );
        }

        $this->releaseLock();

        return true;
    }

    public function destroy(string $id): bool
    {
        if (!$this->isValidId($id)) {
            // Nothing could have been stored under it, so the session is
            // already in the state destroy() promises. Reporting failure here
            // would break a logout flow for no gain.
            return true;
        }

        $this->database->execute(
            "DELETE FROM {$this->table} WHERE {$this->idColumn} = ?",
            [$id],
        );

        $this->idStates[$id] = self::STATE_DESTROYED;
        $this->releaseLock();

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
     * own stale snapshot over everything the earlier one committed. Reproduced
     * on PostgreSQL 16 with two concurrent processes on separate connections:
     * request A read the session, spent 2s minting a CSRF token, and request B
     * read the same session in between and wrote a locale; A's write then
     * landed last and B's locale was gone from the row. With this lock, B's
     * read() blocked for 1.71s until A released, and both changes survived.
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
     * application's own transactions and interferes with none of them.
     *
     * Taken in read() rather than open(), because open() is not told the
     * session id. Released by write(), updateTimestamp(), destroy() and
     * close(), so the paths a wrapper commonly forwards all release it.
     *
     * Best-effort by design: see LOCK_WAIT_SECONDS, and note that a driver
     * other than pgsql (the SQLite this project tests on, for one) takes no
     * lock at all and behaves exactly as before.
     */
    private function acquireLock(string $id): void
    {
        if (!$this->lockSessions || $this->lockedId === $id || !$this->supportsAdvisoryLocks()) {
            return;
        }

        $this->releaseLock();

        $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;

        do {
            try {
                $granted = $this->database->selectBool(
                    'SELECT pg_try_advisory_lock(?, ?)',
                    [self::ADVISORY_LOCK_NAMESPACE, self::advisoryLockKey($id)],
                );
            } catch (Throwable) {
                // Locking is an optimization over correctness-under-contention,
                // never a gate: a failure here must not break the request.
                return;
            }

            if ($granted) {
                $this->lockedId = $id;

                return;
            }

            usleep(self::LOCK_POLL_INTERVAL);
        } while (microtime(true) < $deadline);
    }

    private function releaseLock(): void
    {
        if ($this->lockedId === null) {
            return;
        }

        $id = $this->lockedId;
        $this->lockedId = null;

        try {
            $this->database->selectBool(
                'SELECT pg_advisory_unlock(?, ?)',
                [self::ADVISORY_LOCK_NAMESPACE, self::advisoryLockKey($id)],
            );
        } catch (Throwable) {
            // The connection is already gone, which releases the lock anyway.
        }
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
