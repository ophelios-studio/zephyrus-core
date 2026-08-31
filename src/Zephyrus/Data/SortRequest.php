<?php

declare(strict_types=1);

namespace Zephyrus\Data;

/**
 * Immutable column/direction pair for an ORDER BY clause.
 *
 * TWO FACTORIES, TWO CONTRACTS. They read the SAME request parameter names
 * (sort_by / sortBy / order_by / orderBy), so picking the wrong one is silent:
 *
 *   - fromQuery() is the UNTRUSTED-INPUT sibling. An allowlist is REQUIRED and a
 *     column outside it falls back to the default. Hand it $_GET.
 *   - fromArray() takes PRE-VALIDATED input. Without the optional $allowedColumns
 *     it accepts ANY identifier-shaped column, including one that appears in no
 *     SELECT, which turns a listing into a blind ORDER BY oracle: an attacker
 *     infers a hidden column's ranking from the row order, and a non-existent
 *     column turns a 200 into an error. Pass $allowedColumns whenever the array
 *     came from a request.
 *
 * The blast radius of that trap is disclosure, not injection: the constructor
 * regex forbids quotes and quoteIdentifier() double-quotes every dotted part.
 */
final class SortRequest implements \JsonSerializable
{
    public readonly string $direction;

    public function __construct(
        public readonly string $column,
        string $direction = 'ASC',
    ) {
        if ($column === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_\.]*$/', $column)) {
            throw DatabaseException::queryFailed('sorting', 'Invalid sort column');
        }

        $normalized = strtoupper($direction);
        if (!in_array($normalized, ['ASC', 'DESC'], true)) {
            throw DatabaseException::queryFailed('sorting', 'Sort direction must be ASC or DESC');
        }

        $this->direction = $normalized;
    }

    /**
     * Build from an array of ALREADY VALIDATED values.
     *
     * With $allowedColumns left null this validates NOTHING beyond the identifier
     * shape: any column name survives into the ORDER BY. That is intentional (an
     * internal caller sorting by a column it chose itself must not need a list),
     * and it is also the trap, because this method reads the same sort_by / sortBy
     * / order_by / orderBy keys as fromQuery().
     *
     * Pass $allowedColumns, or use fromQuery(), for anything derived from a request.
     *
     * @param array<string, mixed> $data
     * @param array<int, string>|null $allowedColumns optional allowlist; a column
     *        outside it falls back to $defaultColumn, exactly as fromQuery() does.
     */
    public static function fromArray(
        array $data,
        string $defaultColumn,
        string $defaultDirection = 'ASC',
        ?array $allowedColumns = null,
    ): self {
        $candidate = self::resolveColumn($data, $defaultColumn);
        if ($allowedColumns !== null && !in_array($candidate, $allowedColumns, true)) {
            $candidate = $defaultColumn;
        }

        return new self(
            column: $candidate,
            direction: self::resolveDirection($data, $defaultDirection),
        );
    }

    /**
     * Build from an UNTRUSTED request array. The allowlist is mandatory and a
     * column outside it silently falls back to $defaultColumn, so no query
     * parameter can order by a column the caller did not publish. This is the
     * factory to reach for on $_GET.
     *
     * @param array<string, mixed> $query
     * @param array<int, string> $allowedColumns
     */
    public static function fromQuery(array $query, array $allowedColumns, string $defaultColumn, string $defaultDirection = 'ASC'): self
    {
        $compactSort = self::resolveCompactSort($query);
        $candidate = $compactSort['column'] ?? self::resolveColumn($query, $defaultColumn);
        if (!in_array($candidate, $allowedColumns, true)) {
            $candidate = $defaultColumn;
        }

        $direction = self::resolveDirection($query, $defaultDirection);
        if ($compactSort !== null && !self::hasExplicitDirection($query)) {
            $direction = $compactSort['direction'];
        }

        return new self(
            column: $candidate,
            direction: $direction,
        );
    }

    public function toSql(): string
    {
        return sprintf(' ORDER BY %s %s', self::quoteIdentifier($this->column), $this->direction);
    }

    /**
     * Quote a column identifier for safe SQL interpolation (PostgreSQL double-quote style).
     * Supports dot-separated qualified names (e.g. "table"."column").
     */
    private static function quoteIdentifier(string $identifier): string
    {
        return implode('.', array_map(
            static fn (string $part): string => '"' . str_replace('"', '""', $part) . '"',
            explode('.', $identifier),
        ));
    }

    /** @return array{sort_by: string, sort_dir: string} */
    public function toArray(): array
    {
        return [
            'sort_by' => $this->column,
            'sort_dir' => strtoupper($this->direction),
        ];
    }

    /** @return array{sort_by: string, sort_dir: string} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function resolveColumn(array $data, string $defaultColumn): string
    {
        return (string) ($data['sort_by'] ?? $data['sortBy'] ?? $data['order_by'] ?? $data['orderBy'] ?? $defaultColumn);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function resolveDirection(array $data, string $defaultDirection): string
    {
        return (string) ($data['sort_dir'] ?? $data['sortDir'] ?? $data['direction'] ?? $data['order'] ?? $defaultDirection);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{column: string, direction: string}|null
     */
    private static function resolveCompactSort(array $query): ?array
    {
        if (!array_key_exists('sort', $query)) {
            return null;
        }

        $raw = trim((string) $query['sort']);
        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, '-')) {
            $column = substr($raw, 1);
            if ($column === '' || $column === false) {
                return null;
            }

            return [
                'column' => $column,
                'direction' => 'DESC',
            ];
        }

        if (str_starts_with($raw, '+')) {
            $column = substr($raw, 1);
            if ($column === '' || $column === false) {
                return null;
            }

            return [
                'column' => $column,
                'direction' => 'ASC',
            ];
        }

        return [
            'column' => $raw,
            'direction' => 'ASC',
        ];
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function hasExplicitDirection(array $query): bool
    {
        return array_key_exists('sort_dir', $query)
            || array_key_exists('sortDir', $query)
            || array_key_exists('direction', $query)
            || array_key_exists('order', $query);
    }
}
