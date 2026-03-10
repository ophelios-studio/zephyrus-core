<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use PHPUnit\Framework\TestCase;
use Zephyrus\Data\FilterRequest;

final class FilterRequestTest extends TestCase
{
    public function testFromQueryKeepsOnlyAllowedNonEmptyKeys(): void
    {
        $filter = FilterRequest::fromQuery([
            'status' => 'active',
            'email' => 'a@example.com',
            'ignored' => 'x',
            'blank' => '',
        ], ['status', 'email', 'blank']);

        self::assertSame([
            'status' => 'active',
            'email' => 'a@example.com',
        ], $filter->toArray());
    }

    public function testFromQueryParsesConfiguredCsvKeys(): void
    {
        $filter = FilterRequest::fromQuery(
            ['status' => 'active, pending ,archived'],
            ['status'],
            ['status'],
        );

        self::assertSame([
            'status' => ['active', 'pending', 'archived'],
        ], $filter->toArray());
    }

    public function testFromQuerySkipsCsvKeyWhenOnlyBlankItems(): void
    {
        $filter = FilterRequest::fromQuery(
            ['status' => ' ,  , '],
            ['status'],
            ['status'],
        );

        self::assertSame([], $filter->toArray());
        self::assertTrue($filter->isEmpty());
    }

    public function testFromQueryDoesNotParseCsvForNonConfiguredKeys(): void
    {
        $filter = FilterRequest::fromQuery(
            ['status' => 'active,pending'],
            ['status'],
        );

        self::assertSame([
            'status' => 'active,pending',
        ], $filter->toArray());
    }

    public function testFromQueryNormalizesArrayValuesAndSkipsBlankEntries(): void
    {
        $filter = FilterRequest::fromQuery([
            'status' => [' active ', '', null, 'pending', '  '],
        ], ['status']);

        self::assertSame([
            'status' => ['active', 'pending'],
        ], $filter->toArray());
    }

    public function testFromQuerySkipsArrayKeyWhenValuesAreAllBlank(): void
    {
        $filter = FilterRequest::fromQuery([
            'status' => ['', ' ', null],
        ], ['status']);

        self::assertSame([], $filter->toArray());
        self::assertTrue($filter->isEmpty());
    }

    public function testToWhereClauseBuildsSqlAndBindings(): void
    {
        $filter = new FilterRequest(['status' => 'active', 'email' => 'a@example.com']);

        $where = $filter->toWhereClause([
            'status' => 'users.status',
            'email' => 'users.email',
        ]);

        self::assertSame(' WHERE "users"."status" = :f_status AND "users"."email" = :f_email', $where['sql']);
        self::assertSame([
            ':f_status' => 'active',
            ':f_email' => 'a@example.com',
        ], $where['params']);
    }

    public function testToWhereClauseIgnoresUnknownMappedKeys(): void
    {
        $filter = new FilterRequest(['status' => 'active', 'role' => 'admin']);

        $where = $filter->toWhereClause([
            'status' => 'users.status',
        ]);

        self::assertSame(' WHERE "users"."status" = :f_status', $where['sql']);
        self::assertSame([':f_status' => 'active'], $where['params']);
    }

    public function testToWhereClauseBuildsInClauseForArrayValues(): void
    {
        $filter = new FilterRequest([
            'status' => ['active', 'pending'],
        ]);

        $where = $filter->toWhereClause([
            'status' => 'users.status',
        ]);

        self::assertSame(' WHERE "users"."status" IN (:f_status_0, :f_status_1)', $where['sql']);
        self::assertSame([
            ':f_status_0' => 'active',
            ':f_status_1' => 'pending',
        ], $where['params']);
    }

    public function testToWhereClauseBuildsIsNullForNullValues(): void
    {
        $filter = new FilterRequest([
            'deleted_at' => null,
        ]);

        $where = $filter->toWhereClause([
            'deleted_at' => 'users.deleted_at',
        ]);

        self::assertSame(' WHERE "users"."deleted_at" IS NULL', $where['sql']);
        self::assertSame([], $where['params']);
    }

    public function testToWhereClauseUsesFalseConditionForEmptyArrayList(): void
    {
        $filter = new FilterRequest([
            'status' => [],
        ]);

        $where = $filter->toWhereClause([
            'status' => 'users.status',
        ]);

        self::assertSame(' WHERE 1 = 0', $where['sql']);
        self::assertSame([], $where['params']);
    }

    public function testToWhereClauseBuildsIsNullForNullOnlyArrayValues(): void
    {
        $filter = new FilterRequest([
            'deleted_at' => [null],
        ]);

        $where = $filter->toWhereClause([
            'deleted_at' => 'users.deleted_at',
        ]);

        self::assertSame(' WHERE "users"."deleted_at" IS NULL', $where['sql']);
        self::assertSame([], $where['params']);
    }

    public function testToWhereClauseBuildsInOrIsNullForMixedArrayValues(): void
    {
        $filter = new FilterRequest([
            'status' => ['active', null, 'pending'],
        ]);

        $where = $filter->toWhereClause([
            'status' => 'users.status',
        ]);

        self::assertSame(' WHERE ("users"."status" IN (:f_status_0, :f_status_1) OR "users"."status" IS NULL)', $where['sql']);
        self::assertSame([
            ':f_status_0' => 'active',
            ':f_status_1' => 'pending',
        ], $where['params']);
    }

    public function testToWhereClauseNormalizesParameterNamesForUnsafeKeys(): void
    {
        $filter = new FilterRequest([
            'user.status' => 'active',
        ]);

        $where = $filter->toWhereClause([
            'user.status' => 'users.status',
        ]);

        self::assertSame(' WHERE "users"."status" = :f_user_status', $where['sql']);
        self::assertSame([':f_user_status' => 'active'], $where['params']);
    }

    public function testToWhereClauseReturnsEmptyForNoConditions(): void
    {
        $filter = new FilterRequest();
        $where = $filter->toWhereClause(['status' => 'users.status']);

        self::assertSame('', $where['sql']);
        self::assertSame([], $where['params']);
        self::assertTrue($filter->isEmpty());
    }

    public function testJsonSerializeMatchesArray(): void
    {
        $filter = new FilterRequest(['status' => 'active']);

        self::assertSame($filter->toArray(), $filter->jsonSerialize());
    }
}
