<?php

declare(strict_types=1);

use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\HttpKernel;
use Zephyrus\Http\Error\HttpExceptionResponder;
use Zephyrus\Http\MiddlewarePipeline;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Route;
use Zephyrus\Routing\RouteCollection;
use Zephyrus\Routing\RouteDispatcher;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

$request = Request::fromGlobals(maxBodySize: (int) ($_GET['limit'] ?? 0));

$routes = new RouteCollection();
$routes->add(Route::define('POST', '/submit', 'Form@submit'));
$routes->add(Route::define('PUT', '/submit', 'Form@replace'));
$kernel = new HttpKernel(
    new RouteDispatcher($routes, new MiddlewarePipeline(), static fn (): Response => Response::text('saved')),
    new HttpExceptionResponder(),
    rethrowUnhandled: true,
    configuredMaxBodySize: isset($_GET['configured']) ? (int) $_GET['configured'] : null,
);

try {
    $status = $kernel->handle($request)->status;
    $report = null;
} catch (ConfigurationException $e) {
    $status = null;
    $report = $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode([
    'truncated' => $request->body()->isTruncated(),
    'readLimit' => $request->body()->readLimit(),
    'fields' => array_keys($request->body()->all()),
    'status' => $status,
    'report' => $report,
]);
