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
 */
abstract class Entity implements JsonSerializable
{
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
            if (!property_exists($instance, $name)) {
                continue;
            }

            $reflectionType = $reflection->getProperty($name)->getType();

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
                        throw new InvalidArgumentException(
                            "Invalid value for enum {$className}: {$value}"
                        );
                    }
                } elseif ($innerReflection->isSubclassOf(self::class)) {
                    if ($value instanceof stdClass) {
                        $instance->$name = $className::build($value);
                    }
                    // Skip non-stdClass values (e.g. flat row columns that
                    // happen to share a name with a nested entity property).
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
            $data[$property->getName()] = $property->getValue($this);
        }

        return $data;
    }
}
