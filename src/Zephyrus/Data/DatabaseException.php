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
 * WHY THE MESSAGE IS TERSE BY DEFAULT
 *
 * A driver error message is not a safe string, and native prepares do not make
 * it one. Zephyrus no longer interpolates parameters client-side, so the old
 * worst case is gone (the whole statement echoed back with every value inlined:
 * `LINE 1: ... VALUES ('1','Jane Roe','a private note', ...`). What remains is
 * still enough to leak, and both halves were measured against PostgreSQL 16 on
 * native prepares:
 *
 *   DETAIL:  Key (email)=(jane@example.com) already exists.
 *   CONTEXT: unnamed portal parameter $1 = 'jane@example.com'
 *
 * The first is emitted for any unique or foreign-key violation, the second for
 * any parameter PostgreSQL fails to coerce. That message then travels wherever
 * exceptions travel: logs, alert emails, debug error pages.
 * queryExecutionFailed() therefore keeps the SQLSTATE (a condition code, never
 * a value) in the message and holds the statement and the driver text in sql()
 * and driverMessage(), where a caller must ask for them and can scrub them
 * first.
 *
 * enableVerboseMessages() restores the previous, fully detailed message shape
 * verbatim. It is a development and diagnosis switch: turning it on in a
 * production process puts row values back into every sink that prints an
 * exception message.
 *
 * The hand-called factories (queryFailed, connectionFailed, transactionFailed)
 * are unchanged. Their arguments are chosen by the caller rather than lifted off
 * a driver, so they carry nothing the caller did not put there.
 */
final class DatabaseException extends ZephyrusRuntimeException
{
    /**
     * Process-wide, and deliberately so: the decision is "is this process running
     * in a diagnosis mode", which is a property of the process and not of any one
     * connection or query. Off by default, so an application that upgrades without
     * reading this file gets the safe behaviour.
     */
    private static bool $verboseMessages = false;

    /**
     * The statement that failed, and the driver's own error text. Held as fields
     * rather than folded into the message so that reaching them is an explicit act.
     */
    private ?string $sql = null;
    private ?string $driverMessage = null;

    public static function enableVerboseMessages(bool $enabled = true): void
    {
        self::$verboseMessages = $enabled;
    }

    public static function verboseMessagesEnabled(): bool
    {
        return self::$verboseMessages;
    }

    public static function connectionFailed(string $dsn, string $reason, ?\Throwable $previous = null): self
    {
        return new self("Database connection failed for DSN [{$dsn}]: {$reason}", previous: $previous);
    }

    public static function queryFailed(string $sql, string $reason, ?\Throwable $previous = null): self
    {
        return new self("Query failed [{$sql}]: {$reason}", previous: $previous);
    }

    public static function transactionFailed(string $reason, ?\Throwable $previous = null): self
    {
        return new self("Transaction failed: {$reason}", previous: $previous);
    }

    /**
     * Wrap a failure raised by the driver while executing a statement.
     *
     * This is the factory for anything whose text came OUT of PDO. The statement
     * and the driver message are kept off the message unless verbose messages are
     * enabled; both stay reachable through sql() and driverMessage().
     */
    public static function queryExecutionFailed(string $sql, PDOException $previous): self
    {
        $driverMessage = $previous->getMessage();

        if (self::$verboseMessages) {
            $message = "Query failed [{$sql}]: {$driverMessage}";
        } else {
            $sqlState = self::extractSqlState($previous);
            $message = "Query failed [SQLSTATE {$sqlState}]: statement and driver detail withheld "
                . '(read DatabaseException::sql() / driverMessage(), or call '
                . 'DatabaseException::enableVerboseMessages() to inline them)';
        }

        // The PDOException is deliberately NOT attached as the previous exception,
        // which matches what this class did before: a handler that walks the chain
        // (Tracy, a JSON error renderer) prints every previous message, and that
        // would re-open the exact channel the terse message closes.
        $exception = new self($message);
        $exception->sql = $sql;
        $exception->driverMessage = $driverMessage;

        return $exception;
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
     * queryExecutionFailed(). It can contain real column values even on native
     * prepares (see the class docblock); scrub it before writing it to any sink.
     */
    public function driverMessage(): ?string
    {
        return $this->driverMessage;
    }

    public static function fromPdoException(PDOException $e, string $context = ''): self
    {
        $message = $context !== ''
            ? "{$context}: {$e->getMessage()}"
            : $e->getMessage();

        return new self($message, (int) $e->getCode(), $e);
    }

    /**
     * PDO reports the SQLSTATE in errorInfo[0] and, for exceptions it raises
     * itself, in the exception code. Neither is guaranteed present (a connection
     * that died mid-statement reports HY000 or nothing at all).
     */
    private static function extractSqlState(PDOException $e): string
    {
        $fromErrorInfo = $e->errorInfo[0] ?? null;
        if (is_string($fromErrorInfo) && $fromErrorInfo !== '') {
            return $fromErrorInfo;
        }

        $code = (string) $e->getCode();

        return $code !== '' && $code !== '0' ? $code : 'HY000';
    }
}
