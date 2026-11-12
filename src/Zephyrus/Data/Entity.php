<?php

declare(strict_types=1);

namespace Zephyrus\Data;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use JsonSerializable;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use stdClass;
use ValueError;

/**
 * Base class for domain entities hydrated from database rows.
 *
 * A row column named `rawData` is never hydrated (see self::RESERVED_PROPERTY).
 * jsonSerialize() omits the properties the row did not assign.
 */
abstract class Entity implements JsonSerializable
{
    /**
     * Column name reserved for the private $rawData slot below. A subclass declaring a
     * public $rawData would shadow that slot, so the column is skipped.
     */
    private const RESERVED_PROPERTY = 'rawData';

    private ?stdClass $rawData = null;

    /**
     * Hydrate an entity from a database row, coercing values to the declared property types.
     *
     * Private and static properties, and union or intersection types, are not hydrated. A
     * DateTimeImmutable, DateTimeInterface or DateTime property takes a string that starts with a
     * digit and is a real date.
     *
     * @throws InvalidArgumentException When an enum or date value cannot be hydrated.
     *
     * @return static|null Returns null when $row is null.
     */
    public static function build(?stdClass $row): ?static
    {
        return $row === null ? null : self::fromRow($row);
    }

    private static function fromRow(stdClass $row): static
    {
        $instance = new static();
        $instance->rawData = $row;
        $reflection = new ReflectionClass($instance);

        foreach ($row as $name => $value) {
            if ($name === self::RESERVED_PROPERTY) {
                continue;
            }

            // Subclass reflection, not property_exists(): the latter also reports this
            // class's private slots, which cannot be written from here.
            if (!$reflection->hasProperty($name)) {
                continue;
            }

            $property = $reflection->getProperty($name);
            if ($property->isPrivate() || $property->isStatic()) {
                continue;
            }

            $reflectionType = $property->getType();

            // Positive check on ReflectionNamedType: any future composite type is skipped too.
            if ($reflectionType !== null && !$reflectionType instanceof ReflectionNamedType) {
                continue;
            }

            if ($value === null) {
                $instance->$name = null;
                continue;
            }

            if ($reflectionType === null) {
                $instance->$name = $value;
                continue;
            }

            if ($reflectionType->isBuiltin()) {
                settype($value, $reflectionType->getName());
                $instance->$name = $value;
            } else {
                /** @var class-string $className */
                $className = $reflectionType->getName();
                $innerReflection = new ReflectionClass($className);

                if ($className === 'stdClass' || $innerReflection->isSubclassOf(stdClass::class)) {
                    $instance->$name = $value;
                } elseif ($innerReflection->isEnum()) {
                    try {
                        $instance->$name = $className::from($value);
                    } catch (ValueError) {
                        // The database value is not interpolated: the message reaches logs and error pages.
                        throw new InvalidArgumentException(
                            "Invalid value for enum {$className} on property \${$name}"
                        );
                    }
                } elseif ($innerReflection->isSubclassOf(self::class)) {
                    if ($value instanceof stdClass) {
                        $instance->$name = $className::build($value);
                    }
                } elseif ($className === DateTimeImmutable::class || $className === DateTimeInterface::class) {
                    $instance->$name = self::parseDate(DateTimeImmutable::class, $className, $name, $value);
                } elseif ($className === DateTime::class) {
                    $instance->$name = self::parseDate(DateTime::class, $className, $name, $value);
                }
            }
        }

        return $instance;
    }

    /**
     * @param class-string<DateTimeInterface> $concrete
     */
    private static function parseDate(string $concrete, string $declared, string $name, mixed $value): DateTimeInterface
    {
        // Rejects relative formats, '@timestamp' and an empty string (which would mean "now").
        if (is_string($value) && $value !== '' && ctype_digit($value[0])) {
            try {
                $date = new $concrete($value);
                $errors = DateTimeImmutable::getLastErrors();
                if ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) {
                    return $date;
                }
            } catch (Exception) {
            }
        }

        // The database value is not interpolated: the message reaches logs and error pages.
        throw new InvalidArgumentException("Invalid value for date {$declared} on property \${$name}");
    }

    /**
     * Hydrate an array of database rows into entities.
     *
     * @param stdClass[] $rows
     * @return static[]
     */
    public static function buildArray(array $rows): array
    {
        return array_map(self::fromRow(...), $rows);
    }

    /**
     * Return the stdClass row this entity was built from.
     */
    public function getRawData(): ?stdClass
    {
        return $this->rawData;
    }

    /**
     * Return the public properties as an array, excluding those marked #[JsonIgnore].
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $reflection = new ReflectionClass($this);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);
        $data = [];

        foreach ($properties as $property) {
            if (!empty($property->getAttributes(JsonIgnore::class))) {
                continue;
            }

            // Uninitialized properties (absent from a partial SELECT) are omitted, not read.
            if (!$property->isInitialized($this)) {
                continue;
            }

            $data[$property->getName()] = $property->getValue($this);
        }

        return $data;
    }
}
