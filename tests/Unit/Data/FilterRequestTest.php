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

    public function testFromQueryKeepsReadingKeysAfterEverySkippedOrNormalizedOne(): void
    {
        $filter = FilterRequest::fromQuery([
            'null' => null,
            'blank' => '',
            'csv_blank' => ' , ',
            'csv' => ',a, b',
            'list_blank' => ['', null],
            'list' => [' x ', ''],
            'scalar' => 'v',
        ], ['absent', 'null', 'blank', 'csv_blank', 'csv', 'list_blank', 'list', 'scalar'], ['csv_blank', 'csv']);

        self::assertSame([
            'csv' => ['a', 'b'],
            'list' => ['x'],
            'scalar' => 'v',
        ], $filter->toArray());
    }

    public function testToWhereClauseKeepsBuildingAfterEveryKindOfCondition(): void
    {
        $filter = new FilterRequest([
            'unmapped' => 'x',
            'is_null' => null,
            'empty_list' => [],
            'null_list' => [null],
            'mixed' => ['a', null],
            'in_list' => ['b', 'c'],
            'last' => 'z',
        ]);

        $where = $filter->toWhereClause([
            'is_null' => 'is_null',
            'empty_list' => 'empty_list',
            'null_list' => 'null_list',
            'mixed' => 'mixed',
            'in_list' => 'in_list',
            'last' => 'last',
        ]);

        self::assertSame(
            ' WHERE "is_null" IS NULL AND 1 = 0 AND "null_list" IS NULL'
            . ' AND ("mixed" IN (:f_mixed_0) OR "mixed" IS NULL)'
            . ' AND "in_list" IN (:f_in_list_0, :f_in_list_1) AND "last" = :f_last',
            $where['sql'],
        );
        self::assertSame([
            ':f_mixed_0' => 'a',
            ':f_in_list_0' => 'b',
            ':f_in_list_1' => 'c',
            ':f_last' => 'z',
        ], $where['params']);
    }

    public function testToWhereClauseSkipsArrayItemsAndKeepsTheScalarOnes(): void
    {
        $filter = new FilterRequest(['status' => [['nested'], null, 'a', 'b']]);

        $where = $filter->toWhereClause(['status' => 'status']);

        self::assertSame(' WHERE ("status" IN (:f_status_0, :f_status_1) OR "status" IS NULL)', $where['sql']);
        self::assertSame([':f_status_0' => 'a', ':f_status_1' => 'b'], $where['params']);
    }

    public function testToWhereClauseDoublesAQuoteInTheMappedColumn(): void
    {
        $filter = new FilterRequest(['status' => 'a']);

        $where = $filter->toWhereClause(['status' => 'x"y.z']);

        self::assertSame(' WHERE "x""y"."z" = :f_status', $where['sql']);
    }

    public function testToWhereClauseTrimsUnderscoresFromTheParameterName(): void
    {
        $filter = new FilterRequest(['_a.' => 'x', '..' => 'y']);

        $where = $filter->toWhereClause(['_a.' => 'a', '..' => 'b']);

        self::assertSame(' WHERE "a" = :f_a AND "b" = :f_filter', $where['sql']);
        self::assertSame([':f_a' => 'x', ':f_filter' => 'y'], $where['params']);
    }
}
