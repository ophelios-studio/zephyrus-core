<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Fixtures\Controllers;

use Zephyrus\Http\Response;

class StaticPageController
{
    public function index(): Response
    {
        return Response::html('<html><body>OK</body></html>');
    }
}
