<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Routing\Route;
use Zephyrus\Routing\RouteMatch;

final class RouteMatchTest extends TestCase
{
    public function testParameterReturnsDefaultWhenMissing(): void
    {
        $match = new RouteMatch(
            route: Route::define('GET', '/health', 'HealthController@show'),
            parameters: ['id' => '42'],
        );

        self::assertSame('42', $match->parameter('id'));
        self::assertSame('fallback', $match->parameter('missing', 'fallback'));
    }
}
