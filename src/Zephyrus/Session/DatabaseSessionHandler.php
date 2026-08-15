<?php

declare(strict_types=1);

namespace Zephyrus\Session;

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
 * ## Columns this handler does not write
 *
 * The INSERT lists exactly session_id, access, expire and data. If your table
 * carries anything else, typically ip_address or user_agent for auditing, this
 * handler never populates it and the column stays NULL forever. Either give
 * those columns a default, populate them from your own code, or drop them.
 * Do not build a feature on one expecting this handler to fill it.
 *
 * Register before calling session_start():
 *
 *   session_set_save_handler(new DatabaseSessionHandler($db), true);
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
     */
    public const DEFAULT_ID_PATTERN = '/^[A-Za-z0-9,-]{22,256}$/';

    /**
     * @param string $idPattern Overridable for an application that generates
     *   session ids itself in some other shape. The default only accepts ids
     *   PHP could have produced.
     */
    public function __construct(
        private readonly Database $database,
        private readonly string $table = 'public.session',
        private readonly string $idColumn = 'session_id',
        private readonly string $idPattern = self::DEFAULT_ID_PATTERN,
    ) {}

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
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
     * Adopting an attacker-chosen id is the enabling condition for session
     * fixation: plant a known id, get a victim to use it, and the id survives
     * their login. Returning false for an id that does not already exist is
     * exactly what makes PHP discard it and generate a fresh one.
     */
    public function validateId(string $id): bool
    {
        if (!$this->isValidId($id)) {
            return false;
        }

        return $this->database->selectOne(
            "SELECT {$this->idColumn} FROM {$this->table} WHERE {$this->idColumn} = ?",
            [$id],
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
     * The conflict clause updates the timestamps ONLY. The row is inserted when
     * it is missing (a session garbage-collected mid-flight) so a live session
     * is not silently lost, and that insert is the one case where the payload is
     * written.
     */
    public function updateTimestamp(string $id, string $data): bool
    {
        if (!$this->isValidId($id)) {
            return false;
        }

        $access = time();
        $expire = $access + (int) ini_get('session.gc_maxlifetime');

        $this->database->execute(
            "INSERT INTO {$this->table} ({$this->idColumn}, access, expire, data)
             VALUES (?, ?, ?, ?)
             ON CONFLICT ({$this->idColumn}) DO UPDATE
             SET access = EXCLUDED.access, expire = EXCLUDED.expire",
            [$id, $access, $expire, $data],
        );

        return true;
    }

    public function read(string $id): string|false
    {
        if (!$this->isValidId($id)) {
            return '';
        }

        $row = $this->database->selectOne(
            "SELECT data FROM {$this->table} WHERE {$this->idColumn} = ?",
            [$id],
        );

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
     * A single atomic upsert, deliberately. This used to SELECT for an existing
     * row and then INSERT or UPDATE, which is a check-then-act race: two
     * concurrent requests carrying the same NEW session id both saw no row,
     * both INSERTed, and the loser died on a duplicate-key violation. The
     * symptom was silent, because handlers are commonly wrapped to swallow
     * write failures so a session problem cannot break a page render, so the
     * session write simply did not happen and nothing was logged.
     *
     * Doing it in one statement also removes a round trip.
     *
     * Requires the id column to be a PRIMARY KEY or carry a UNIQUE constraint,
     * which it needs anyway to be a session table.
     */
    public function write(string $id, string $data): bool
    {
        if (!$this->isValidId($id)) {
            return false;
        }

        $access = time();
        $expire = $access + (int) ini_get('session.gc_maxlifetime');

        $this->database->execute(
            "INSERT INTO {$this->table} ({$this->idColumn}, access, expire, data)
             VALUES (?, ?, ?, ?)
             ON CONFLICT ({$this->idColumn}) DO UPDATE
             SET access = EXCLUDED.access, expire = EXCLUDED.expire, data = EXCLUDED.data",
            [$id, $access, $expire, $data],
        );

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
}
