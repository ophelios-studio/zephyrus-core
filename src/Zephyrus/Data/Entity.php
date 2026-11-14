<?php

declare(strict_types=1);

namespace Zephyrus\Data;

use BackedEnum;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use JsonSerializable;
use PropertyHookType;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use stdClass;
use TypeError;
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

    /** Native types settype() converts to; `mixed` is assigned as is, any other native type is refused. */
    private const COERCIBLE_BUILTINS = ['bool', 'int', 'float', 'string', 'array', 'object'];

    /** A date as PostgreSQL renders it: optional time, fraction and UTC offset; no named zone, no relative format. */
    private const ISO_DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?)?'
        . '(?:Z|[+-]\d{2}(?::?\d{2}(?::?\d{2})?)?)?$/D';

    private ?stdClass $rawData = null;

    /**
     * Hydrate an entity from a database row, coercing values to the declared property types.
     *
     * Private and static properties are skipped; a readonly property its constructor already set, a private(set)
     * property and a virtual property with no set hook are refused. Hydrates bool, int, float, string, array, object
     * and mixed, stdClass, backed enums, nested entities (from a stdClass) and date strings (DateTimeImmutable,
     * DateTimeInterface, DateTime; an ISO `YYYY-MM-DD` string with optional time, fraction and UTC offset). A date
     * that does not fit, a value that is not a case of its backed enum (wrong type included), a pure enum, a nested
     * entity that is not a stdClass or any other type is refused; native types are converted by settype(),
     * not validated.
     *
     * @throws InvalidArgumentException When a value cannot be hydrated into its property.
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

            if ($property->isReadOnly() && $property->isInitialized($instance)) {
                throw self::unwritableProperty(
                    $instance,
                    $name,
                    'it is readonly and its constructor already set it. '
                    . 'Leave it unset in the constructor, or drop readonly.'
                );
            }
            if ($property->isPrivateSet()) {
                throw self::unwritableProperty(
                    $instance,
                    $name,
                    'its private(set) visibility keeps Entity from writing it. Use protected(set) instead.'
                );
            }
            if ($property->isVirtual() && !$property->hasHook(PropertyHookType::Set)) {
                throw self::unwritableProperty(
                    $instance,
                    $name,
                    'it is virtual with no set hook. Alias the column in the query, or give the property a set hook.'
                );
            }

            $reflectionType = $property->getType();

            if ($value === null) {
                if ($reflectionType === null || $reflectionType instanceof ReflectionNamedType
                    || $reflectionType->allowsNull()) {
                    $instance->$name = null;
                }
                continue;
            }

            if ($reflectionType !== null && !$reflectionType instanceof ReflectionNamedType) {
                throw self::unsupportedType($instance, $name, (string) $reflectionType);
            }

            if ($reflectionType === null) {
                $instance->$name = $value;
                continue;
            }

            $typeName = $reflectionType->getName();

            if ($reflectionType->isBuiltin()) {
                if ($typeName === 'mixed') {
                    $instance->$name = $value;
                } elseif (in_array($typeName, self::COERCIBLE_BUILTINS, true)) {
                    settype($value, $typeName);
                    $instance->$name = $value;
                } else {
                    throw self::unsupportedType($instance, $name, $typeName);
                }
                continue;
            }

            /** @var class-string $className */
            $className = match ($typeName) {
                'self' => $property->getDeclaringClass()->getName(),
                'parent' => ($property->getDeclaringClass()->getParentClass()
                    ?: throw self::unsupportedType($instance, $name, $typeName))->getName(),
                default => $typeName,
            };
            $innerReflection = new ReflectionClass($className);

            if ($className === 'stdClass' || $innerReflection->isSubclassOf(stdClass::class)) {
                $instance->$name = $value;
            } elseif ($innerReflection->isEnum()) {
                if (!$innerReflection->isSubclassOf(BackedEnum::class)) {
                    throw self::unsupportedType($instance, $name, $className);
                }
                try {
                    $instance->$name = $className::from($value);
                } catch (ValueError | TypeError) {
                    // The database value is not interpolated: the message reaches logs and error pages.
                    throw new InvalidArgumentException(
                        "Invalid value for enum {$className} on property \${$name}"
                    );
                }
            } elseif ($innerReflection->isSubclassOf(self::class)) {
                if (!$value instanceof stdClass) {
                    throw new InvalidArgumentException(
                        "Invalid value for entity {$className} on property \${$name}"
                    );
                }
                $instance->$name = $className::build($value);
            } elseif ($className === DateTimeImmutable::class || $className === DateTimeInterface::class) {
                $instance->$name = self::parseDate(DateTimeImmutable::class, $className, $name, $value);
            } elseif ($className === DateTime::class) {
                $instance->$name = self::parseDate(DateTime::class, $className, $name, $value);
            } else {
                throw self::unsupportedType($instance, $name, $className);
            }
        }

        return $instance;
    }

    /**
     * @param class-string<DateTimeInterface> $concrete
     */
    private static function parseDate(string $concrete, string $declared, string $name, mixed $value): DateTimeInterface
    {
        if (is_string($value) && preg_match(self::ISO_DATE_PATTERN, $value) === 1) {
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

    private static function unwritableProperty(self $instance, string $name, string $reason): InvalidArgumentException
    {
        return new InvalidArgumentException(
            "Cannot hydrate property \${$name} of " . get_debug_type($instance) . ": {$reason}"
        );
    }

    private static function unsupportedType(self $instance, string $name, string $type): InvalidArgumentException
    {
        return new InvalidArgumentException(
            "Cannot hydrate property \${$name} of " . get_debug_type($instance) . ": type {$type} is not supported"
        );
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
     * Return the public properties as an array, excluding those marked #[JsonIgnore] and
     * virtual properties with no get hook.
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

            if ($property->isVirtual() && !$property->hasHook(PropertyHookType::Get)) {
                continue;
            }

            $data[$property->getName()] = $property->getValue($this);
        }

        return $data;
    }
}
