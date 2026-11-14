<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Fixtures\ConditionalControllers;

use Zephyrus\Routing\Attribute\Get;
use Zephyrus\Routing\Attribute\Post;
use Zephyrus\Routing\Attribute\RequiresEnv;
use Zephyrus\Routing\Attribute\WithoutMiddleware;
use Zephyrus\Session\SessionMiddleware;

#[RequiresEnv('APP_ENV', 'testing')]
#[WithoutMiddleware(SessionMiddleware::class)]
final class TestingController
{
    #[Post('/testing/reset', name: 'testing.reset')]
    public function reset(): void
    {
    }

    #[Get('/testing/status/{part}', constraints: ['part' => '[a-z]+'])]
    #[RequiresEnv('APP_ENV', 'production')]
    public function neverServed(): void
    {
    }
}
