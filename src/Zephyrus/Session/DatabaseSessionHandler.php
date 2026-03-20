<?php

declare(strict_types=1);

namespace Zephyrus\Session;

use Zephyrus\Data\Database;

/**
 * SessionHandlerInterface implementation that stores session data in a
 * database table. Compatible with any PDO-supported backend (PostgreSQL,
 * SQLite, etc.).
 *
 * Expected table schema:
 *
 *   CREATE TABLE <table> (
 *       id    VARCHAR PRIMARY KEY,
 *       access INTEGER NOT NULL,
 *       data   TEXT NOT NULL DEFAULT ''
 *   );
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
            "SELECT data FROM {$this->table} WHERE id = ?",
            [$id],
        );

        return $row !== null ? $row->data : '';
    }

    public function write(string $id, string $data): bool
    {
        $access = time();
        $existing = $this->database->selectOne(
            "SELECT id FROM {$this->table} WHERE id = ?",
            [$id],
        );

        if ($existing !== null) {
            $this->database->execute(
                "UPDATE {$this->table} SET access = ?, data = ? WHERE id = ?",
                [$access, $data, $id],
            );
        } else {
            $this->database->execute(
                "INSERT INTO {$this->table} (id, access, data) VALUES (?, ?, ?)",
                [$id, $access, $data],
            );
        }

        return true;
    }

    public function destroy(string $id): bool
    {
        $this->database->execute(
            "DELETE FROM {$this->table} WHERE id = ?",
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
