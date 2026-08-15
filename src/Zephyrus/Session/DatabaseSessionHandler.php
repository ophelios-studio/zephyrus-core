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
final class DatabaseSessionHandler implements \SessionHandlerInterface
{
    public function __construct(
        private readonly Database $database,
        private readonly string $table = 'public.session',
        private readonly string $idColumn = 'session_id',
    ) {}

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $row = $this->database->selectOne(
            "SELECT data FROM {$this->table} WHERE {$this->idColumn} = ?",
            [$id],
        );

        return $row !== null ? $row->data : '';
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
