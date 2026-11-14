<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Fixtures\ConfiguredControllers;

use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class GreetingController
{
    #[Get('/greeting', name: 'greeting')]
    public function greet(): Response
    {
        return Response::text('hello');
    }
}
