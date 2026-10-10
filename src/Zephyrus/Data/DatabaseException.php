<?php

declare(strict_types=1);

namespace Zephyrus\Data;

use PDOException;
use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Raised when a PDO-level database operation fails.
 *
 * Driver messages can echo row values (PostgreSQL's DETAIL line, for example,
 * prints `Key (email)=(jane@example.com) already exists.`), so queryExecutionFailed() and
 * transactionExecutionFailed() keep them out of the message: read them through sql() and driverMessage().
 * queryFailed() and connectionFailed() print what their caller passes; fromConfig() passes the driver's
 * connect error, which names the server and user but holds no row data.
 * No flag restores them in the message, so every sink that prints exceptions stays safe.
 */
final class DatabaseException extends ZephyrusRuntimeException
{
    private ?string $sql = null;
    private ?string $driverMessage = null;
    private ?string $sqlState = null;

    private const SQLSTATE_PATTERN = '/\A[0-9A-Z]{5}\z/';

    private const ROLLBACK_HINT = 'Inside a transaction, let this exception propagate so the row is rolled back.';

    public static function connectionFailed(string $dsn, string $reason, ?\Throwable $previous = null): self
    {
        return new self("Database connection failed for DSN [{$dsn}]: {$reason}", previous: $previous);
    }

    public static function queryFailed(string $sql, string $reason, ?\Throwable $previous = null): self
    {
        return new self("Query failed [{$sql}]: {$reason}", previous: $previous);
    }

    public static function lastInsertIdRefused(): self
    {
        return new self(
            'lastInsertId() is refused on PostgreSQL: use insertGetId() (insertRowGetId() in a Broker) with INSERT ... RETURNING id.',
        );
    }

    public static function returningYieldedNoColumn(): self
    {
        return new self(
            'The statement was executed, but it returned no column: RETURNING occurs only in a literal, comment or identifier. '
            . self::ROLLBACK_HINT,
        );
    }

    public static function returningNotSingleColumn(int $columns): self
    {
        return new self(
            "RETURNING must name exactly one column, found {$columns}. The statement was executed. " . self::ROLLBACK_HINT,
        );
    }

    public static function returningRequired(): self
    {
        return new self(
            'insertGetId() needs INSERT ... RETURNING id on PostgreSQL and SQLite: add RETURNING id to the statement.',
        );
    }

    /**
     * Wraps a driver failure raised while executing $sql; the SQL and driver text stay out of the message.
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
     * Wraps a driver failure at a transaction stage (begin, commit, savepoint, release savepoint).
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
     * Maps a failed transaction probe to transactionAborted() on SQLSTATE 25P02, else to transactionExecutionFailed().
     */
    public static function transactionProbeFailed(string $stage, #[\SensitiveParameter] PDOException $previous): self
    {
        return self::extractSqlState($previous) === '25P02'
            ? self::transactionAborted($stage)
            : self::transactionExecutionFailed($stage, $previous);
    }

    /**
     * A statement failed inside a transaction level whose exception was caught, so the level was rolled back.
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
     * The five-character SQLSTATE reported by the driver. Set by queryExecutionFailed(), transactionExecutionFailed(),
     * transactionProbeFailed() and transactionAborted(); null from the other factories.
     * PDO reports a connection lost mid-session as HY000, not 08006.
     */
    public function sqlState(): ?string
    {
        return $this->sqlState;
    }

    /**
     * The failed statement, set by queryExecutionFailed(). It may contain values if the caller inlined literals.
     */
    public function sql(): ?string
    {
        return $this->sql;
    }

    /**
     * The driver's error text. It can contain row values: scrub it before writing it to any sink.
     */
    public function driverMessage(): ?string
    {
        return $this->driverMessage;
    }

    /**
     * The PDOException is not chained: handlers that walk the chain would print the driver text.
     */
    private static function fromDriverError(string $message, string $sqlState, #[\SensitiveParameter] PDOException $previous): self
    {
        $exception = new self($message);
        $exception->driverMessage = $previous->getMessage();
        $exception->sqlState = $sqlState;

        return $exception;
    }

    /**
     * Reads the SQLSTATE from errorInfo, then from the exception code; HY000 when neither holds one.
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
