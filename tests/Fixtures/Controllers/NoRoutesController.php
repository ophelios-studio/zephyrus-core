<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Fixtures\Controllers;

class NoRoutesController
{
    public function helper(): string
    {
        return 'not a route';
    }
}
