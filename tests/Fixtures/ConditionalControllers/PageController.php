<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Fixtures\ConditionalControllers;

use Zephyrus\Routing\Attribute\Get;
use Zephyrus\Routing\Attribute\MiddlewareGroup;
use Zephyrus\Routing\Attribute\Post;
use Zephyrus\Routing\Attribute\RequiresEnv;

#[MiddlewareGroup('web')]
final class PageController
{
    #[Get('/', name: 'home')]
    public function home(): void
    {
    }

    #[Get('/mode', name: 'mode')]
    #[RequiresEnv('MODE', 'web')]
    public function webMode(): void
    {
    }

    #[Get('/mode', name: 'mode')]
    #[RequiresEnv('MODE', 'api')]
    public function apiMode(): void
    {
    }

    #[Get('/shouting')]
    #[RequiresEnv('MODE', 'WEB')]
    public function upperCaseMode(): void
    {
    }

    #[Get('/flag/empty')]
    #[RequiresEnv('FLAG', '')]
    public function emptyFlag(): void
    {
    }

    #[Post('/flag/both', middlewares: ['throttle'])]
    #[RequiresEnv('MODE', 'web')]
    #[RequiresEnv('FLAG', '1')]
    public function bothFlags(): void
    {
    }
}
