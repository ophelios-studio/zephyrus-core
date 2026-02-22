<?php

declare(strict_types=1);

namespace Zephyrus\Data;

use PDOException;
use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Raised when a PDO-level database operation fails.
 *
 * Use the named factory methods to create descriptive instances without
 * catching raw PDOExceptions throughout application code.
 */
final class DatabaseException extends ZephyrusRuntimeException
{
    public static function connectionFailed(string $dsn, string $reason): self
    {
        return new self("Database connection failed for DSN [{$dsn}]: {$reason}");
    }

    public static function queryFailed(string $sql, string $reason): self
    {
        return new self("Query failed [{$sql}]: {$reason}");
    }

    public static function transactionFailed(string $reason): self
    {
        return new self("Transaction failed: {$reason}");
    }

    public static function fromPdoException(PDOException $e, string $context = ''): self
    {
        $message = $context !== ''
            ? "{$context}: {$e->getMessage()}"
            : $e->getMessage();

        return new self($message, (int) $e->getCode(), $e);
    }
}
