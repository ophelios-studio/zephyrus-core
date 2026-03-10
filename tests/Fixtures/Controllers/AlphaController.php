<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Fixtures\Controllers;

use Zephyrus\Routing\Attribute\Get;

class AlphaController
{
    #[Get('/alpha')]
    public function index(): void
    {
    }
}
