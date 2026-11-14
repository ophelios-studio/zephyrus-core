<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Fixtures\ConditionalControllers;

use Zephyrus\Routing\Attribute\Get;
use Zephyrus\Routing\Attribute\RequiresEnv;

final class StatusController
{
    #[Get('/status/{part}', constraints: ['part' => '[a-z]+'], name: 'status')]
    #[RequiresEnv('APP_ENV', 'production')]
    public function show(): void
    {
    }
}
