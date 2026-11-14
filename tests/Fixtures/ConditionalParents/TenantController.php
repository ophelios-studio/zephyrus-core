<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Fixtures\ConditionalParents;

use Zephyrus\Routing\Attribute\Middleware;
use Zephyrus\Routing\Attribute\RequiresEnv;

#[RequiresEnv('TENANT_APP', '1')]
#[Middleware('tenant')]
abstract class TenantController
{
}
