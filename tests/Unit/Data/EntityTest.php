<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;
use Zephyrus\Data\Entity;
use Zephyrus\Data\JsonIgnore;

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

enum UserRole: string
{
    case Admin = 'admin';
    case Member = 'member';
}

enum Priority: int
{
    case Low = 1;
    case High = 2;
}

class SimpleEntity extends Entity
{
    public int $id;
    public string $name;
    public bool $active;
    public float $score;
}

class NullableEntity extends Entity
{
    public int $id;
    public ?string $name = null;
    public ?int $age = null;
}

class EnumEntity extends Entity
{
    public int $id;
    public UserRole $role;
    public ?Priority $priority = null;
}

class AddressEntity extends Entity
{
    public string $city;
    public string $country;
}

class NestedEntity extends Entity
{
    public int $id;
    public string $name;
    public ?AddressEntity $address = null;
}

class JsonIgnoreEntity extends Entity
{
    public int $id;
    public string $name;
    #[JsonIgnore]
    public string $secret;
}

class UntypedEntity extends Entity
{
    public $value;
    public int $id;
}

class UnionTypeEntity extends Entity
{
    public int $id;
    public int|string $mixed;
}

class StdClassEntity extends Entity
{
    public int $id;
    public stdClass $meta;
}

class DeclaredRawDataEntity extends Entity
{
    public int $id;
    public string $rawData;
}

class PrivatePropertyEntity extends Entity
{
    public int $id;
    private string $internal = 'untouched';

    public function internal(): string
    {
        return $this->internal;
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

final class EntityTest extends TestCase
{
    public function testBuildNull(): void
    {
        $this->assertNull(SimpleEntity::build(null));
    }

    public function testBuildBasicTypes(): void
    {
        $row = new stdClass();
        $row->id = '42';
        $row->name = 'Alice';
        $row->active = '1';
        $row->score = '9.5';

        $entity = SimpleEntity::build($row);

        $this->assertInstanceOf(SimpleEntity::class, $entity);
        $this->assertSame(42, $entity->id);
        $this->assertSame('Alice', $entity->name);
        $this->assertTrue($entity->active);
        $this->assertSame(9.5, $entity->score);
    }

    public function testBuildNullValues(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->name = null;
        $row->age = null;

        $entity = NullableEntity::build($row);

        $this->assertSame(1, $entity->id);
        $this->assertNull($entity->name);
        $this->assertNull($entity->age);
    }

    public function testBuildIgnoresUnknownColumns(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->name = 'Bob';
        $row->active = '1';
        $row->score = '0.0';
        $row->nonexistent = 'should be ignored';

        $entity = SimpleEntity::build($row);
        $this->assertSame(1, $entity->id);
    }

    public function testBuildEnum(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->role = 'admin';
        $row->priority = 2;

        $entity = EnumEntity::build($row);

        $this->assertSame(UserRole::Admin, $entity->role);
        $this->assertSame(Priority::High, $entity->priority);
    }

    public function testBuildEnumNull(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->role = 'member';
        $row->priority = null;

        $entity = EnumEntity::build($row);

        $this->assertSame(UserRole::Member, $entity->role);
        $this->assertNull($entity->priority);
    }

    public function testBuildEnumInvalidValue(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->role = 'nonexistent';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for enum');

        EnumEntity::build($row);
    }

    public function testBuildNestedEntity(): void
    {
        $address = new stdClass();
        $address->city = 'Montreal';
        $address->country = 'Canada';

        $row = new stdClass();
        $row->id = '1';
        $row->name = 'Alice';
        $row->address = $address;

        $entity = NestedEntity::build($row);

        $this->assertInstanceOf(AddressEntity::class, $entity->address);
        $this->assertSame('Montreal', $entity->address->city);
        $this->assertSame('Canada', $entity->address->country);
    }

    public function testBuildNestedEntityNull(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->name = 'Bob';
        $row->address = null;

        $entity = NestedEntity::build($row);

        $this->assertNull($entity->address);
    }

    public function testBuildStdClassProperty(): void
    {
        $meta = new stdClass();
        $meta->key = 'value';

        $row = new stdClass();
        $row->id = '1';
        $row->meta = $meta;

        $entity = StdClassEntity::build($row);

        $this->assertInstanceOf(stdClass::class, $entity->meta);
        $this->assertSame('value', $entity->meta->key);
    }

    public function testBuildSkipsUnionTypes(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->mixed = 'hello';

        $entity = UnionTypeEntity::build($row);

        $this->assertSame(1, $entity->id);
        // Union type property should remain at its default (uninitialized),
        // so we just verify the entity was built without error.
    }

    public function testBuildUntypedProperty(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->value = 'anything';

        $entity = UntypedEntity::build($row);

        $this->assertSame(1, $entity->id);
        $this->assertSame('anything', $entity->value);
    }

    public function testBuildArray(): void
    {
        $row1 = new stdClass();
        $row1->id = '1';
        $row1->name = 'Alice';
        $row1->active = '1';
        $row1->score = '9.5';

        $row2 = new stdClass();
        $row2->id = '2';
        $row2->name = 'Bob';
        $row2->active = '0';
        $row2->score = '7.3';

        $entities = SimpleEntity::buildArray([$row1, $row2]);

        $this->assertCount(2, $entities);
        $this->assertSame(1, $entities[0]->id);
        $this->assertSame('Alice', $entities[0]->name);
        $this->assertSame(2, $entities[1]->id);
        $this->assertSame('Bob', $entities[1]->name);
    }

    public function testBuildArrayEmpty(): void
    {
        $this->assertSame([], SimpleEntity::buildArray([]));
    }

    public function testGetRawData(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->name = 'Alice';
        $row->active = '1';
        $row->score = '0.0';

        $entity = SimpleEntity::build($row);

        $this->assertSame($row, $entity->getRawData());
    }

    public function testGetRawDataNullForNew(): void
    {
        // An entity created via new (not build) has null rawData.
        $entity = new class extends Entity {
            public int $id = 1;
        };

        $this->assertNull($entity->getRawData());
    }

    public function testJsonSerialize(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->name = 'Alice';
        $row->active = '1';
        $row->score = '9.5';

        $entity = SimpleEntity::build($row);
        $json = $entity->jsonSerialize();

        $this->assertSame(['id' => 1, 'name' => 'Alice', 'active' => true, 'score' => 9.5], $json);
    }

    public function testJsonSerializeExcludesJsonIgnore(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->name = 'Alice';
        $row->secret = 'password123';

        $entity = JsonIgnoreEntity::build($row);
        $json = $entity->jsonSerialize();

        $this->assertArrayHasKey('id', $json);
        $this->assertArrayHasKey('name', $json);
        $this->assertArrayNotHasKey('secret', $json);
    }

    public function testJsonEncodeRoundTrip(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->name = 'Alice';
        $row->active = '1';
        $row->score = '9.5';

        $entity = SimpleEntity::build($row);
        $encoded = json_encode($entity);

        $this->assertJson($encoded);
        $decoded = json_decode($encoded, true);
        $this->assertSame(1, $decoded['id']);
        $this->assertSame('Alice', $decoded['name']);
    }

    public function testTypeCoercionFromStrings(): void
    {
        $row = new stdClass();
        $row->id = '99';
        $row->name = 42;       // int → string
        $row->active = '';     // empty string → false
        $row->score = '3';    // string → float

        $entity = SimpleEntity::build($row);

        $this->assertSame(99, $entity->id);
        $this->assertSame('42', $entity->name);
        $this->assertFalse($entity->active);
        $this->assertSame(3.0, $entity->score);
    }

    // ── partial rows and reserved names (findings 3 and 6) ───────────────────

    /**
     * REGRESSION. jsonSerialize() called getValue() on EVERY declared public
     * property, but build() only assigns the ones present in the row, so a partial
     * SELECT left a typed property uninitialized and json_encode() died with
     * "Typed property must not be accessed before initialization". Selecting a
     * subset of columns and then serializing is an ordinary, innocent pattern.
     */
    public function testJsonSerializeSkipsPropertiesAPartialRowNeverAssigned(): void
    {
        $row = new stdClass();
        $row->id = '7';

        $entity = SimpleEntity::build($row);

        $this->assertSame(['id' => 7], $entity->jsonSerialize());
        $this->assertSame('{"id":7}', json_encode($entity));
    }

    public function testJsonSerializeStillEmitsEveryAssignedProperty(): void
    {
        $row = new stdClass();
        $row->id = '7';
        $row->name = 'Alice';
        $row->active = '1';
        $row->score = '1.5';

        $entity = SimpleEntity::build($row);

        $this->assertSame(
            ['id' => 7, 'name' => 'Alice', 'active' => true, 'score' => 1.5],
            $entity->jsonSerialize(),
        );
    }

    /**
     * REGRESSION. A row carrying a column literally named rawData threw a
     * ReflectionException: property_exists() answered from Entity's own scope, where
     * the base class's private $rawData slot IS visible, while getProperty() on the
     * subclass reflection could not address it.
     */
    public function testBuildIgnoresARowColumnNamedRawDataInsteadOfThrowing(): void
    {
        $row = new stdClass();
        $row->id = '3';
        $row->name = 'Alice';
        $row->rawData = 'a column that happens to be named rawData';

        $entity = SimpleEntity::build($row);

        $this->assertSame(3, $entity->id);
        $this->assertSame('Alice', $entity->name);
        // The row itself is still reachable, unchanged.
        $this->assertSame($row, $entity->getRawData());
    }

    /**
     * REGRESSION. A subclass that DECLARES a public $rawData shadows the base
     * class's private slot, so the assignment made from Entity's scope landed on the
     * private ?stdClass and raised a TypeError. rawData is reserved: the column is
     * skipped rather than fatalling.
     */
    public function testBuildSkipsAReservedRawDataPropertyInsteadOfFatalling(): void
    {
        $row = new stdClass();
        $row->id = '4';
        $row->rawData = 'boom';

        $entity = DeclaredRawDataEntity::build($row);

        $this->assertSame(4, $entity->id);
        $this->assertSame($row, $entity->getRawData());
    }

    /**
     * Guard for the property_exists() to hasProperty() swap: a private property
     * declared on the SUBCLASS is invisible from Entity's scope and must stay
     * skipped, not become an "Cannot access private property" Error.
     */
    public function testBuildSkipsAPrivatePropertyDeclaredOnTheSubclass(): void
    {
        $row = new stdClass();
        $row->id = '5';
        $row->internal = 'injected';

        $entity = PrivatePropertyEntity::build($row);

        $this->assertSame(5, $entity->id);
        $this->assertSame('untouched', $entity->internal());
    }

    /**
     * The enum coercion failure used to interpolate the RAW DATABASE VALUE into the
     * exception message, which then travelled into logs and error pages.
     */
    public function testEnumCoercionFailureDoesNotEchoTheDatabaseValue(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->role = 'jane.roe@example.com';

        try {
            EnumEntity::build($row);
            $this->fail('expected an InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid value for enum', $e->getMessage());
            $this->assertStringContainsString('UserRole', $e->getMessage());
            $this->assertStringContainsString('role', $e->getMessage());
            $this->assertStringNotContainsString('jane.roe@example.com', $e->getMessage());
        }
    }
}
