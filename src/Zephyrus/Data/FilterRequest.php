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

            $column = $columnMap[$key];
            $baseParamName = $this->normalizeParameterName($key);

            if ($value === null) {
                $parts[] = sprintf('%s IS NULL', $column);
                continue;
            }

            if (is_array($value)) {
                $listValues = [];
                $hasNull = false;

                foreach ($value as $item) {
                    if (is_array($item)) {
                        continue;
                    }

                    if ($item === null) {
                        $hasNull = true;
                        continue;
                    }

                    $listValues[] = $item;
                }

                if ($listValues === [] && !$hasNull) {
                    $parts[] = '1 = 0';
                    continue;
                }

                if ($listValues === [] && $hasNull) {
                    $parts[] = sprintf('%s IS NULL', $column);
                    continue;
                }

                $placeholders = [];
                foreach (array_values($listValues) as $index => $item) {
                    $paramName = sprintf('%s_%d', $baseParamName, $index);
                    $placeholder = ':' . $paramName;
                    $placeholders[] = $placeholder;
                    $params[$placeholder] = $item;
                }

                $inClause = sprintf('%s IN (%s)', $column, implode(', ', $placeholders));
                if ($hasNull) {
                    $parts[] = sprintf('(%s OR %s IS NULL)', $inClause, $column);
                    continue;
                }

                $parts[] = $inClause;
                continue;
            }

            $placeholder = ':' . $baseParamName;
            $parts[] = sprintf('%s = %s', $column, $placeholder);
            $params[$placeholder] = $value;
        }

        if ($parts === []) {
            return ['sql' => '', 'params' => []];
        }

        return [
            'sql' => ' WHERE ' . implode(' AND ', $parts),
            'params' => $params,
        ];
    }

    private function normalizeParameterName(string $key): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9_]/', '_', $key) ?? '';
        $normalized = trim($normalized, '_');

        if ($normalized === '') {
            return 'f_filter';
        }

        return 'f_' . $normalized;
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
