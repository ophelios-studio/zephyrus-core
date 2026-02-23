<?php

declare(strict_types=1);

namespace Zephyrus\Data;

/**
 * Structured SQL filter payload for safe WHERE-clause composition.
 */
final class FilterRequest implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $conditions
     */
    public function __construct(
        public readonly array $conditions = [],
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @param array<int, string> $allowedKeys
     */
    public static function fromQuery(array $query, array $allowedKeys): self
    {
        $conditions = [];

        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $query)) {
                continue;
            }

            $value = $query[$key];

            if ($value === null || $value === '') {
                continue;
            }

            $conditions[$key] = $value;
        }

        return new self($conditions);
    }

    public function isEmpty(): bool
    {
        return $this->conditions === [];
    }

    /**
     * Build SQL fragment + bound parameters for exact-match filtering.
     *
     * @param array<string, string> $columnMap
     * @return array{sql: string, params: array<string, mixed>}
     */
    public function toWhereClause(array $columnMap): array
    {
        if ($this->isEmpty()) {
            return ['sql' => '', 'params' => []];
        }

        $parts = [];
        $params = [];

        foreach ($this->conditions as $key => $value) {
            if (!isset($columnMap[$key])) {
                continue;
            }

            $paramName = 'f_' . $key;
            $parts[] = sprintf('%s = :%s', $columnMap[$key], $paramName);
            $params[':' . $paramName] = $value;
        }

        if ($parts === []) {
            return ['sql' => '', 'params' => []];
        }

        return [
            'sql' => ' WHERE ' . implode(' AND ', $parts),
            'params' => $params,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->conditions;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
