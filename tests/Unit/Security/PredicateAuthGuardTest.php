<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;
use Zephyrus\Security\PredicateAuthGuard;

final class PredicateAuthGuardTest extends TestCase
{
    public function testAuthorizesWhenPredicateReturnsTrue(): void
    {
        $guard = new PredicateAuthGuard(static fn (Request $request): bool => true);

        self::assertTrue($guard->isAuthorized(Request::fromArray('GET', '/secure')));
    }

    public function testRejectsWhenPredicateReturnsFalse(): void
    {
        $guard = new PredicateAuthGuard(static fn (Request $request): bool => false);

        self::assertFalse($guard->isAuthorized(Request::fromArray('GET', '/secure')));
    }

    public function testPredicateCanUseRequestData(): void
    {
        $guard = new PredicateAuthGuard(static fn (Request $request): bool => $request->header('X-Role') === 'admin');

        $allowed = Request::fromArray('GET', '/secure', headers: ['X-Role' => 'admin']);
        $denied = Request::fromArray('GET', '/secure', headers: ['X-Role' => 'teacher']);

        self::assertTrue($guard->isAuthorized($allowed));
        self::assertFalse($guard->isAuthorized($denied));
    }
}
