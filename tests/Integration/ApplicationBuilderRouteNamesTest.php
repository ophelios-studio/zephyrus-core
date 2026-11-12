<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Exception\DuplicateRouteNameException;
use Zephyrus\Routing\Router;

final class ApplicationBuilderRouteNamesTest extends TestCase
{
    public function testBuildRefusesTwoControllersSharingARouteName(): void
    {
        $router = (new Router())
            ->get('/first', RouteNamesFirstController::class . '@show')->name('shared')
            ->get('/second', RouteNamesSecondController::class . '@show')->name('shared');

        try {
            ApplicationBuilder::create()->withRouter($router)->build();
            self::fail('expected a refusal');
        } catch (DuplicateRouteNameException $e) {
            self::assertSame('Duplicate route names detected: shared', $e->getMessage());
        }
    }

    public function testBuildAcceptsDistinctRouteNames(): void
    {
        $router = (new Router())
            ->get('/first', RouteNamesFirstController::class . '@show')->name('first')
            ->get('/second', RouteNamesSecondController::class . '@show')->name('second');

        $application = ApplicationBuilder::create()->withRouter($router)->build();

        self::assertNotNull($application);
    }
}

final class RouteNamesFirstController
{
    public function show(Request $request): Response
    {
        return Response::text('first');
    }
}

final class RouteNamesSecondController
{
    public function show(Request $request): Response
    {
        return Response::text('second');
    }
}
