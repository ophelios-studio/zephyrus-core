<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Fixtures\ConditionalControllers\Tenant;

use Zephyrus\Routing\Attribute\Get;
use Zephyrus\Routing\Attribute\RequiresEnv;
use Zephyrus\Tests\Fixtures\ConditionalParents\TenantController;

final class DashboardController extends TenantController
{
    #[Get('/tenant')]
    public function index(): void
    {
    }

    #[Get('/tenant/{id}', constraints: ['id' => '\d+'], name: 'tenant.dashboard')]
    #[RequiresEnv('MODE', 'web')]
    public function show(): void
    {
    }
}
