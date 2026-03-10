<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Fixtures\Controllers;

use Zephyrus\Routing\Attribute\Get;

abstract class AbstractBaseController
{
    #[Get('/abstract')]
    public function index(): void
    {
    }
}
