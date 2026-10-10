<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Routing\Exception\RouteNotFoundException;
use Zephyrus\Routing\Exception\RoutePathRefusal;

final class RouteNotFoundExceptionTest extends TestCase
{
    public function testAPlainNotFoundHasNoRefusalData(): void
    {
        foreach ([new RouteNotFoundException(), RouteNotFoundException::noRouteMatched('get', '/nothing')] as $exception) {
            self::assertNull($exception->refusalReason());
            self::assertNull($exception->refusedParameter());
        }
    }

    public function testAPlainNotFoundQuotesTheMethodAndThePath(): void
    {
        self::assertSame(
            'No route matched "GET" "/nothing"',
            RouteNotFoundException::noRouteMatched('get', '/nothing')->getMessage(),
        );
        self::assertSame(
            'No route matched "GE\r\nT" "/a\u2028b\u0085c\u202ed\"\\\\"',
            RouteNotFoundException::noRouteMatched("ge\r\nt", "/a\u{2028}b\u{85}c\u{202E}d\"\\")->getMessage(),
        );
    }

    public function testAPathWithAControlCharacterExposesItsReason(): void
    {
        $exception = RouteNotFoundException::pathIsRefused('post', RoutePathRefusal::ControlCharacter);

        self::assertSame(RoutePathRefusal::ControlCharacter, $exception->refusalReason());
        self::assertNull($exception->refusedParameter());
        self::assertSame('No route matched "POST": the request path contains a control character', $exception->getMessage());
    }

    public function testAPathThatIsNotValidUtf8ExposesItsReason(): void
    {
        $exception = RouteNotFoundException::pathIsRefused('get', RoutePathRefusal::InvalidUtf8);

        self::assertSame(RoutePathRefusal::InvalidUtf8, $exception->refusalReason());
        self::assertNull($exception->refusedParameter());
        self::assertSame('No route matched "GET": the request path is not valid UTF-8', $exception->getMessage());
    }

    public function testAHostileMethodIsQuotedInTheMessage(): void
    {
        $exception = RouteNotFoundException::pathIsRefused("ge\r\nt\x00\"", RoutePathRefusal::ControlCharacter);

        self::assertSame(
            'No route matched "GE\r\nT\u0000\"": the request path contains a control character',
            $exception->getMessage(),
        );
    }

    public function testARefusedParameterExposesItsNameAndReason(): void
    {
        $control = RouteNotFoundException::parameterIsRefused('get', '/users/%0A', 'id', RoutePathRefusal::ControlCharacter);
        $utf8 = RouteNotFoundException::parameterIsRefused('get', '/users/%FF', 'id', RoutePathRefusal::InvalidUtf8);

        self::assertSame(RoutePathRefusal::ControlCharacter, $control->refusalReason());
        self::assertSame('id', $control->refusedParameter());
        self::assertSame('No route matched "GET" "/users/%0A": parameter "id" contains a control character', $control->getMessage());
        self::assertSame(RoutePathRefusal::InvalidUtf8, $utf8->refusalReason());
        self::assertSame('No route matched "GET" "/users/%FF": parameter "id" is not valid UTF-8', $utf8->getMessage());
    }

    public function testALongPathKeepsItsTailUpTo512Bytes(): void
    {
        $path = '/' . str_repeat('a', 300) . '/tail';

        self::assertSame(
            sprintf('No route matched "GET" "%s"', $path),
            RouteNotFoundException::noRouteMatched('get', $path)->getMessage(),
        );
        self::assertSame(
            sprintf('No route matched "GET" "%s": parameter "id" contains a control character', $path),
            RouteNotFoundException::parameterIsRefused('get', $path, 'id', RoutePathRefusal::ControlCharacter)->getMessage(),
        );
    }

    public function testAPathOver512BytesIsCutAndItsLengthShown(): void
    {
        $path = '/' . str_repeat('a', 600);

        self::assertSame(
            sprintf('No route matched "GET" "%s..." (601 bytes)', substr($path, 0, 512)),
            RouteNotFoundException::noRouteMatched('get', $path)->getMessage(),
        );
    }
}
