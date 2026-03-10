<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Fixtures\Controllers\Sub;

use Zephyrus\Routing\Attribute\Get;

class GammaController
{
    #[Get('/gamma')]
    public function show(): void
    {
    }
}
