<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Fixtures\Controllers;

use Zephyrus\Routing\Attribute\Get;
use Zephyrus\Routing\Attribute\Post;

class BetaController
{
    #[Get('/beta')]
    public function list(): void
    {
    }

    #[Post('/beta')]
    public function store(): void
    {
    }
}
