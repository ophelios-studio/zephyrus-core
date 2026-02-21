<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;

final class RequestTest extends TestCase
{
    public function testFactoryNormalizesMethodAndHeaders(): void
    {
        $request = Request::fromArray(
            method: 'post',
            uri: '/users',
            headers: ['Content-Type' => 'application/json'],
        );

        self::assertSame('POST', $request->method);
        self::assertSame('application/json', $request->header('content-type'));
    }

    public function testQueryAndInputHelpersReturnDefaultWhenMissing(): void
    {
        $request = Request::fromArray(
            method: 'GET',
            uri: '/search',
            query: ['q' => 'zephyrus'],
            parsedBody: ['name' => 'molt'],
        );

        self::assertSame('zephyrus', $request->query('q'));
        self::assertSame('fallback', $request->query('missing', 'fallback'));

        self::assertSame('molt', $request->input('name'));
        self::assertNull($request->input('missing'));
    }
}
