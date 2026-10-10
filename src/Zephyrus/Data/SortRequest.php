<?php

declare(strict_types=1);

namespace Zephyrus\Data;

/**
 * Immutable column and direction pair for an ORDER BY clause.
 *
 * fromQuery() takes untrusted input such as $_GET and requires an allowlist. fromArray() takes
 * pre-validated input and accepts any identifier-shaped column unless $allowedColumns is passed:
 * without that allowlist a request can order by a column that is not published, which discloses
 * its ranking. Both read the same request keys.
 */
final class SortRequest implements \JsonSerializable
{
    public readonly string $direction;

    public function __construct(
        public readonly string $column,
        string $direction = 'ASC',
    ) {
        // The pattern forbids quotes; quoteIdentifier() also quotes every dotted part.
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
     * Build from pre-validated values. Without $allowedColumns any identifier-shaped column is accepted.
     *
     * @param array<string, mixed> $data
     * @param array<int, string>|null $allowedColumns optional allowlist; a column outside it falls back to $defaultColumn.
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
     * Build from an untrusted request array (e.g. $_GET). A column outside the mandatory
     * allowlist falls back to $defaultColumn.
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
     * Quote each dot-separated part of a column identifier (PostgreSQL double-quote style).
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
            if ($column === '') {
                return null;
            }

            return [
                'column' => $column,
                'direction' => 'DESC',
            ];
        }

        if (str_starts_with($raw, '+')) {
            $column = substr($raw, 1);
            if ($column === '') {
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
