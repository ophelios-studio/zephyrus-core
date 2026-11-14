<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use ArrayAccess;
use ArrayObject;
use Countable;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
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

enum PureSuit
{
    case Hearts;
    case Spades;
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

class IntersectionTypeEntity extends Entity
{
    public int $id;
    public Countable&ArrayAccess $bag;
}

class DateEntity extends Entity
{
    public int $id;
    public DateTimeImmutable $immutable;
    public DateTimeInterface $interface;
    public DateTime $mutable;
    public ?DateTimeImmutable $deletedAt = null;
}

class ForeignClassEntity extends Entity
{
    public int $id;
    public ArrayObject $bag;
}

class NullableUnionEntity extends Entity
{
    public int $id;
    public int|string|null $mixed = null;
}

class MixedEntity extends Entity
{
    public int $id;
    public mixed $payload;
}

class IterableEntity extends Entity
{
    public int $id;
    public iterable $items;
}

class SelfReferencingEntity extends Entity
{
    public int $id;
    public ?self $parent = null;
}

class ParentTypedEntity extends AddressEntity
{
    public ?parent $origin = null;
}

class PureEnumEntity extends Entity
{
    public PureSuit $suit;
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

class ConstructorReadonlyEntity extends Entity
{
    public function __construct(public readonly int $id = 0)
    {
    }
}

class PrivateSetEntity extends Entity
{
    public private(set) int $id = 0;
}

class UninitializedReadonlyEntity extends Entity
{
    public readonly int $id;
}

class ProtectedSetEntity extends Entity
{
    public protected(set) int $id = 0;
}

class VirtualGetOnlyEntity extends Entity
{
    public int $id {
        get => 42;
    }
}

class VirtualSetOnlyEntity extends Entity
{
    public int $id {
        set {
            $this->log[] = $value;
        }
    }

    public array $log = [];
}

class HookBackedSetEntity extends Entity
{
    public int $id {
        set => $value * 2;
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

    public function testAValueForAUnionTypedPropertyIsRefusedAtBuild(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->mixed = 'hello';

        try {
            UnionTypeEntity::build($row);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Cannot hydrate property $mixed of ' . UnionTypeEntity::class . ': type string|int is not supported',
                $e->getMessage()
            );
        }
    }

    public function testAReadonlyPropertyTheConstructorAlreadySetIsRefusedAtBuild(): void
    {
        try {
            ConstructorReadonlyEntity::build((object) ['id' => 5]);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Cannot hydrate property $id of ' . ConstructorReadonlyEntity::class
                . ': it is readonly and its constructor already set it. '
                . 'Leave it unset in the constructor, or drop readonly.',
                $e->getMessage()
            );
        }
    }

    public function testAPrivateSetPropertyIsRefusedAtBuild(): void
    {
        try {
            PrivateSetEntity::build((object) ['id' => 5]);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Cannot hydrate property $id of ' . PrivateSetEntity::class
                . ': its private(set) visibility keeps Entity from writing it. Use protected(set) instead.',
                $e->getMessage()
            );
        }
    }

    public function testAVirtualPropertyWithOnlyAGetHookIsRefusedAtBuild(): void
    {
        try {
            VirtualGetOnlyEntity::build((object) ['id' => '7']);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Cannot hydrate property $id of ' . VirtualGetOnlyEntity::class
                . ': it is virtual with no set hook. Alias the column in the query, or give the property a set hook.',
                $e->getMessage()
            );
        }
    }

    public function testAVirtualPropertyWithOnlyASetHookIsHydratedThroughItsSetHook(): void
    {
        $entity = VirtualSetOnlyEntity::build((object) ['id' => '7']);

        self::assertSame([7], $entity->log);
    }

    public function testAVirtualPropertyWithOnlyASetHookIsOmittedFromJsonSerialize(): void
    {
        self::assertSame(['log' => []], (new VirtualSetOnlyEntity())->jsonSerialize());
    }

    public function testAVirtualPropertyWithOnlyAGetHookIsIncludedInJsonSerialize(): void
    {
        self::assertSame(['id' => 42], (new VirtualGetOnlyEntity())->jsonSerialize());
    }

    public function testAHookBackedSetPropertyIsHydratedThroughItsSetHook(): void
    {
        self::assertSame(14, HookBackedSetEntity::build((object) ['id' => '7'])->id);
    }

    public function testAnUninitializedReadonlyAndAProtectedSetPropertyAreHydrated(): void
    {
        self::assertSame(5, UninitializedReadonlyEntity::build((object) ['id' => 5])->id);
        self::assertSame(5, ProtectedSetEntity::build((object) ['id' => 5])->id);
    }

    public function testAValueForAnIntersectionTypedPropertyIsRefusedAtBuild(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->bag = 'hello';

        try {
            IntersectionTypeEntity::build($row);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Cannot hydrate property $bag of ' . IntersectionTypeEntity::class
                . ': type Countable&ArrayAccess is not supported',
                $e->getMessage()
            );
        }
    }

    public function testAValueForAPropertyTypedByAForeignClassIsRefusedAtBuild(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->bag = 'hello';

        try {
            ForeignClassEntity::build($row);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Cannot hydrate property $bag of ' . ForeignClassEntity::class . ': type ArrayObject is not supported',
                $e->getMessage()
            );
        }
    }

    public function testANullForAUnionTypedPropertyIsStillAccepted(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->mixed = null;

        self::assertNull(NullableUnionEntity::build($row)->mixed);
    }

    public function testDateStringsHydrateDateTimeProperties(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->immutable = '2030-02-01 10:30:00';
        $row->interface = '2030-02-01 10:30:00';
        $row->mutable = '2030-02-01 10:30:00';
        $row->deletedAt = null;

        $entity = DateEntity::build($row);

        self::assertInstanceOf(DateTimeImmutable::class, $entity->immutable);
        self::assertInstanceOf(DateTimeImmutable::class, $entity->interface);
        self::assertInstanceOf(DateTime::class, $entity->mutable);
        self::assertSame('2030-02-01 10:30:00', $entity->immutable->format('Y-m-d H:i:s'));
        self::assertSame('2030-02-01 10:30:00', $entity->mutable->format('Y-m-d H:i:s'));
        self::assertNull($entity->deletedAt);
    }

    public function testAnInvalidDateStringIsRefusedWithoutEchoingTheValue(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->immutable = 'jane.roe@example.com';

        try {
            DateEntity::build($row);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Invalid value for date DateTimeImmutable on property $immutable',
                $e->getMessage()
            );
        }
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
        $row->name = 42;       // int coerced to string
        $row->active = '';     // empty string coerced to false
        $row->score = '3';    // string coerced to float

        $entity = SimpleEntity::build($row);

        $this->assertSame(99, $entity->id);
        $this->assertSame('42', $entity->name);
        $this->assertFalse($entity->active);
        $this->assertSame(3.0, $entity->score);
    }

    // ── partial rows and reserved names ──────────────────────────────────────

    /**
     * A partial SELECT leaves unselected typed properties uninitialized, so serialization must skip them.
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
     * A column named rawData must not throw: the base class's private slot is visible from Entity's
     * scope but not from the subclass reflection.
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
     * rawData is reserved: a subclass declaring a public $rawData shadows the private slot, so the
     * column is skipped rather than raising a TypeError.
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
     * A private property declared on the subclass stays skipped: it is invisible from Entity's scope.
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

    public function testAPureEnumPropertyIsRefusedAtBuild(): void
    {
        $row = new stdClass();
        $row->suit = 'Hearts';

        try {
            PureEnumEntity::build($row);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Cannot hydrate property $suit of ' . PureEnumEntity::class . ': type ' . PureSuit::class
                . ' is not supported',
                $e->getMessage()
            );
        }
    }

    public function testAnIntBackedEnumGivenAStringIsRefusedLikeAnUnknownCase(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->role = 'admin';
        $row->priority = '1';

        try {
            EnumEntity::build($row);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Invalid value for enum ' . Priority::class . ' on property $priority', $e->getMessage());
        }
    }

    /**
     * The enum coercion error must not echo the raw database value, since messages reach logs and error pages.
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

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidDates(): array
    {
        return [
            'empty' => [''],
            'blank' => ['   '],
            'zero date' => ['0000-00-00'],
            'impossible day' => ['2030-02-30'],
            'relative word' => ['tomorrow'],
            'relative offset' => ['+10 years'],
            'unix timestamp' => ['@0'],
            'integer' => [1900000000],
            'infinity' => ['infinity'],
            'relative interval' => ['1 day'],
            'zero interval' => ['0 seconds'],
            'time only' => ['12:00'],
            'year only' => ['2024'],
            'date with a relative suffix' => ['2024-01-01 +1 year'],
            'five digit year' => ['20245-03-15'],
            'trailing NUL' => ["2024-01-01\0"],
            'named timezone' => ['2024-01-01 Europe/Paris'],
            'trailing newline' => ["2024-01-01\n"],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validDates(): array
    {
        return [
            'fraction and offset with T' => ['2024-01-01T10:00:00.123456+00:00', '2024-01-01T10:00:00+00:00'],
            'second-precision offset' => ['1850-01-01 00:00:00-05:17:32', '1850-01-01T05:17:32+00:00'],
            'hour-only offset' => ['2030-02-01 10:30:00-05', '2030-02-01T15:30:00+00:00'],
            'offset without colon' => ['2030-02-01 10:30:00+0530', '2030-02-01T05:00:00+00:00'],
            'zulu' => ['2030-02-01T10:30:00Z', '2030-02-01T10:30:00+00:00'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validDatesWithoutOffset(): array
    {
        return [
            'date only' => ['2030-02-01', '2030-02-01 00:00:00.000000'],
            'leading zero year' => ['0999-01-01', '0999-01-01 00:00:00.000000'],
            'fraction without offset' => ['2024-01-01 10:00:00.5', '2024-01-01 10:00:00.500000'],
        ];
    }

    #[DataProvider('validDates')]
    public function testAnIsoDateStringIsHydrated(string $value, string $expectedUtc): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->immutable = $value;

        $date = DateEntity::build($row)->immutable->setTimezone(new DateTimeZone('UTC'));

        self::assertSame($expectedUtc, $date->format('c'));
    }

    #[DataProvider('validDatesWithoutOffset')]
    public function testAnIsoDateStringWithoutOffsetIsReadInTheDefaultTimezone(string $value, string $expectedWallClock): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->immutable = $value;

        $date = DateEntity::build($row)->immutable;

        self::assertSame($expectedWallClock, $date->format('Y-m-d H:i:s.u'));
        self::assertSame(date_default_timezone_get(), $date->getTimezone()->getName());
    }

    #[DataProvider('invalidDates')]
    public function testAnInvalidDateValueIsRefused(mixed $value): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->immutable = $value;

        try {
            DateEntity::build($row);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Invalid value for date DateTimeImmutable on property $immutable', $e->getMessage());
        }
    }

    public function testADateWithAnOffsetKeepsItsOffset(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->immutable = '2030-02-01 10:30:00+02:00';

        $entity = DateEntity::build($row);

        self::assertSame('+02:00', $entity->immutable->format('P'));
        self::assertSame('2030-02-01T10:30:00+02:00', $entity->immutable->format('c'));
    }

    public function testAMixedPropertyReceivesTheValueUnchanged(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->payload = '42';

        self::assertSame('42', MixedEntity::build($row)->payload);
    }

    public function testAnIterablePropertyIsRefusedAtBuild(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->items = [1, 2];

        try {
            IterableEntity::build($row);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Cannot hydrate property $items of ' . IterableEntity::class . ': type iterable is not supported',
                $e->getMessage()
            );
        }
    }

    public function testASelfTypedPropertyIsBuiltFromAStdClass(): void
    {
        $parent = new stdClass();
        $parent->id = '1';
        $row = new stdClass();
        $row->id = '2';
        $row->parent = $parent;

        $entity = SelfReferencingEntity::build($row);

        self::assertInstanceOf(SelfReferencingEntity::class, $entity->parent);
        self::assertSame(1, $entity->parent->id);
    }

    public function testAParentTypedPropertyIsBuiltFromAStdClass(): void
    {
        $origin = new stdClass();
        $origin->city = 'Springfield';
        $row = new stdClass();
        $row->city = 'Shelbyville';
        $row->origin = $origin;

        $entity = ParentTypedEntity::build($row);

        self::assertSame(AddressEntity::class, $entity->origin::class);
        self::assertSame('Springfield', $entity->origin->city);
    }

    public function testAnEntityPropertyGivenAScalarIsRefusedAtBuild(): void
    {
        $row = new stdClass();
        $row->id = '1';
        $row->name = 'Alice';
        $row->address = '{"city":"Montreal"}';

        try {
            NestedEntity::build($row);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Invalid value for entity ' . AddressEntity::class . ' on property $address',
                $e->getMessage()
            );
        }
    }

    public function testTheRefusalNamesAnAnonymousEntityWithoutItsFilePath(): void
    {
        $entity = new class extends Entity {
            public int|string $mixed;
        };
        $row = new stdClass();
        $row->mixed = 'x';

        try {
            $entity::build($row);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Cannot hydrate property $mixed of ' . get_debug_type($entity) . ': type string|int is not supported',
                $e->getMessage()
            );
            self::assertStringNotContainsString("\0", $e->getMessage());
            self::assertStringNotContainsString('.php', $e->getMessage());
        }
    }
}
