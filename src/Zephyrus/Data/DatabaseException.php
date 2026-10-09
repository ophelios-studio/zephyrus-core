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
 *
 * WHY THE MESSAGE IS TERSE
 *
 * A driver error message is not a safe string, and native prepares do not make
 * it one. Measured against PostgreSQL 16:
 *
 *   DETAIL:  Key (email)=(jane@example.com) already exists.
 *   CONTEXT: unnamed portal parameter $1 = 'jane@example.com'
 *
 * The first is emitted for any unique or foreign-key violation, including one
 * deferred to COMMIT, the second for any parameter PostgreSQL fails to coerce.
 * That message then travels wherever exceptions travel: logs, alert emails,
 * debug error pages. queryExecutionFailed() and transactionExecutionFailed()
 * therefore keep the SQLSTATE (a condition code, never a value) in the message
 * and in sqlState(), and hold the driver text in driverMessage() (and the
 * statement in sql()), where a caller must ask for them and can scrub them first.
 *
 * There is deliberately no switch that restores the detailed message: a
 * process-wide flag would put row values back into every sink that prints an
 * exception, production included. Diagnosis reads driverMessage() on purpose.
 *
 * queryFailed() and connectionFailed() print what their caller passes.
 * Database::fromConfig() passes the driver's connect error, which names the
 * server and user but holds no row data.
 */
final class DatabaseException extends ZephyrusRuntimeException
{
    /**
     * The statement that failed, and the driver's own error text. Held as fields
     * rather than folded into the message so that reaching them is an explicit act.
     */
    private ?string $sql = null;
    private ?string $driverMessage = null;
    private ?string $sqlState = null;

    private const SQLSTATE_PATTERN = '/\A[0-9A-Z]{5}\z/';

    public static function connectionFailed(string $dsn, string $reason, ?\Throwable $previous = null): self
    {
        return new self("Database connection failed for DSN [{$dsn}]: {$reason}", previous: $previous);
    }

    public static function queryFailed(string $sql, string $reason, ?\Throwable $previous = null): self
    {
        return new self("Query failed [{$sql}]: {$reason}", previous: $previous);
    }

    /**
     * Thrown before any statement runs. The message holds no SQL text.
     */
    public static function lastInsertIdRefused(): self
    {
        return new self('lastInsertId() is refused on PostgreSQL: use insertGetId() with INSERT ... RETURNING id.');
    }

    /**
     * The message holds no SQL text.
     */
    public static function returningRequired(): self
    {
        return new self('The generated id must be read with INSERT ... RETURNING id on this driver.');
    }

    /**
     * Wrap a failure raised by the driver while executing a statement. The
     * statement and the driver text stay off the message; sql() and
     * driverMessage() return them.
     */
    public static function queryExecutionFailed(string $sql, #[\SensitiveParameter] PDOException $previous): self
    {
        $sqlState = self::extractSqlState($previous);
        $exception = self::fromDriverError(
            "Query failed [SQLSTATE {$sqlState}]: statement and driver detail withheld, "
            . 'read sql() and driverMessage() to inspect them',
            $sqlState,
            $previous,
        );
        $exception->sql = $sql;

        return $exception;
    }

    /**
     * Wrap a failure raised by the driver at one stage of a transaction (begin,
     * commit, savepoint, release savepoint). The driver text stays off the
     * message; driverMessage() returns it.
     */
    public static function transactionExecutionFailed(string $stage, #[\SensitiveParameter] PDOException $previous): self
    {
        $sqlState = self::extractSqlState($previous);

        return self::fromDriverError(
            "Transaction failed at {$stage} [SQLSTATE {$sqlState}]: driver detail withheld, "
            . 'read driverMessage() to inspect it',
            $sqlState,
            $previous,
        );
    }

    /**
     * Map a failed transaction probe to transactionAborted() for 25P02, else to transactionExecutionFailed().
     */
    public static function transactionProbeFailed(string $stage, #[\SensitiveParameter] PDOException $previous): self
    {
        return self::extractSqlState($previous) === '25P02'
            ? self::transactionAborted($stage)
            : self::transactionExecutionFailed($stage, $previous);
    }

    /**
     * A statement failed inside a transaction level and its exception was caught,
     * so the level was rolled back instead of committed or released. 25P02 is
     * the SQLSTATE PostgreSQL itself reports for the aborted transaction.
     */
    public static function transactionAborted(string $stage): self
    {
        $exception = new self(
            "Transaction failed at {$stage} [SQLSTATE 25P02]: a statement inside it failed and its exception "
            . 'was caught, so its writes were rolled back; let the exception propagate, or catch it around '
            . 'a nested transaction()',
        );
        $exception->sqlState = '25P02';

        return $exception;
    }

    /**
     * The five-character SQLSTATE the driver reported, for branching on the failure
     * kind (for example 23505 for a unique violation on PostgreSQL) without parsing
     * the message. Null unless the instance came from queryExecutionFailed(),
     * transactionExecutionFailed() or transactionAborted(). Note that PDO reports a connection lost
     * mid-session as HY000, not 08006.
     */
    public function sqlState(): ?string
    {
        return $this->sqlState;
    }

    /**
     * The statement that failed, when this instance came from queryExecutionFailed().
     * It may contain values if the caller built the SQL with inlined literals.
     */
    public function sql(): ?string
    {
        return $this->sql;
    }

    /**
     * The driver's own error text, when this instance came from
     * queryExecutionFailed() or transactionExecutionFailed(). It can contain real
     * column values (see the class docblock); scrub it before writing it to any sink.
     */
    public function driverMessage(): ?string
    {
        return $this->driverMessage;
    }

    /**
     * The PDOException is deliberately not chained: a handler that walks the
     * chain (Tracy, a JSON error renderer) would print the driver text.
     */
    private static function fromDriverError(string $message, string $sqlState, #[\SensitiveParameter] PDOException $previous): self
    {
        $exception = new self($message);
        $exception->driverMessage = $previous->getMessage();
        $exception->sqlState = $sqlState;

        return $exception;
    }

    /**
     * PDO reports the SQLSTATE in errorInfo[0] and, for exceptions it raises
     * itself, in the exception code. Neither is guaranteed present (a connection
     * that died mid-statement reports HY000 or nothing at all).
     */
    private static function extractSqlState(#[\SensitiveParameter] PDOException $e): string
    {
        $fromErrorInfo = $e->errorInfo[0] ?? null;
        if (is_string($fromErrorInfo) && preg_match(self::SQLSTATE_PATTERN, $fromErrorInfo) === 1) {
            return $fromErrorInfo;
        }

        $code = (string) $e->getCode();

        return preg_match(self::SQLSTATE_PATTERN, $code) === 1 ? $code : 'HY000';
    }
}
