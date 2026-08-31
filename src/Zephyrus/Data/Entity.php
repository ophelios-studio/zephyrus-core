<?php

declare(strict_types=1);

namespace Zephyrus\Data;

use InvalidArgumentException;
use JsonSerializable;
use ReflectionClass;
use ReflectionProperty;
use ReflectionUnionType;
use stdClass;
use ValueError;

/**
 * Abstract base for domain entities hydrated from database rows.
 *
 * Provides reflection-based hydration from stdClass rows with automatic
 * type coercion, nested entity support, and enum handling.
 *
 * Example:
 *
 *   class User extends Entity {
 *       public int $id;
 *       public string $name;
 *       public string $email;
 *       public UserRole $role;        // Backed enum
 *       public ?UserProfile $profile; // Nested entity
 *       #[JsonIgnore]
 *       public string $password_hash;
 *   }
 *
 *   $user = User::build($row);           // Single row
 *   $users = User::buildArray($rows);    // Array of rows
 *   $json = json_encode($user);          // Excludes password_hash
 *
 * Two behaviours worth knowing:
 *   - A row column named `rawData` is RESERVED and never hydrated; see
 *     self::RESERVED_PROPERTY.
 *   - jsonSerialize() omits any property the row did not assign, so a partial
 *     SELECT yields a partial payload instead of an error.
 */
abstract class Entity implements JsonSerializable
{
    /**
     * Column name this base class cannot hydrate, because it is the name of its own
     * private slot below. A subclass that declares a public $rawData shadows that
     * slot, so an assignment made from THIS scope lands on the private
     * `?stdClass` and raises a TypeError. The column is skipped instead.
     */
    private const RESERVED_PROPERTY = 'rawData';

    private ?stdClass $rawData = null;

    /**
     * Hydrate an entity instance from a database row (stdClass).
     *
     * Performs reflection-based type coercion:
     *   - Built-in types (int, string, float, bool): settype()
     *   - Backed enums: Enum::from($value)
     *   - Nested entities (subclasses of Entity): recursive build()
     *   - stdClass properties: assigned directly
     *   - Union types: skipped (not resolvable)
     *   - Null values: assigned as-is
     *
     * @return static|null Returns null when $row is null.
     */
    public static function build(?stdClass $row): ?static
    {
        if ($row === null) {
            return null;
        }

        $instance = new static();
        $instance->rawData = $row;
        $reflection = new ReflectionClass($instance);

        foreach ($row as $name => $value) {
            if ($name === self::RESERVED_PROPERTY) {
                continue;
            }

            // hasProperty(), not property_exists(). property_exists() answers from
            // the CALLING scope, which is this class, so it reported true for two
            // kinds of property this method cannot actually write:
            //   - the private $rawData slot declared here, which getProperty() on
            //     the subclass reflection then could not address (ReflectionException);
            //   - a private property declared ON the subclass, whose assignment
            //     raised "Cannot access private property".
            // hasProperty() answers from the subclass reflection, and the isPrivate()
            // guard drops the second case. Protected properties stay hydrated: they
            // ARE writable from this scope, and were before.
            if (!$reflection->hasProperty($name)) {
                continue;
            }

            $property = $reflection->getProperty($name);
            if ($property->isPrivate() || $property->isStatic()) {
                continue;
            }

            $reflectionType = $property->getType();

            // Skip union types — we cannot resolve which type to use.
            if ($reflectionType instanceof ReflectionUnionType) {
                continue;
            }

            // Null values are assigned directly.
            if ($value === null) {
                $instance->$name = null;
                continue;
            }

            // No type hint — assign directly.
            if ($reflectionType === null) {
                $instance->$name = $value;
                continue;
            }

            if ($reflectionType->isBuiltin()) {
                settype($value, $reflectionType->getName());
                $instance->$name = $value;
            } else {
                $className = $reflectionType->getName();
                $innerReflection = new ReflectionClass($className);

                if ($className === 'stdClass' || $innerReflection->isSubclassOf(stdClass::class)) {
                    $instance->$name = $value;
                } elseif ($innerReflection->isEnum()) {
                    try {
                        $instance->$name = $className::from($value);
                    } catch (ValueError) {
                        // The offending value is a DATABASE VALUE and is deliberately
                        // NOT interpolated here: this message reaches logs and debug
                        // error pages, and the column that failed plus the enum that
                        // rejected it are enough to diagnose the mismatch.
                        throw new InvalidArgumentException(
                            "Invalid value for enum {$className} on property \${$name}"
                        );
                    }
                } elseif ($innerReflection->isSubclassOf(self::class)) {
                    if ($value instanceof stdClass) {
                        $instance->$name = $className::build($value);
                    }
                }
            }
        }

        return $instance;
    }

    /**
     * Hydrate an array of database rows into entity instances.
     *
     * @param stdClass[] $rows
     * @return static[]
     */
    public static function buildArray(array $rows): array
    {
        return array_map(static::build(...), $rows);
    }

    /**
     * Return the original stdClass row used to build this entity.
     */
    public function getRawData(): ?stdClass
    {
        return $this->rawData;
    }

    /**
     * Serialize public properties to an associative array, excluding
     * any properties marked with #[JsonIgnore].
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

            // build() only assigns the properties present in the row, so a partial
            // SELECT leaves the rest uninitialized and getValue() would raise
            // "Typed property must not be accessed before initialization". An absent
            // column is omitted from the payload rather than fatalling.
            if (!$property->isInitialized($this)) {
                continue;
            }

            $data[$property->getName()] = $property->getValue($this);
        }

        return $data;
    }
}
