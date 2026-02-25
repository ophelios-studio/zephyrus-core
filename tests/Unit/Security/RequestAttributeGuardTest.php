<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;
use Zephyrus\Security\RequestAttributeGuard;

final class RequestAttributeGuardTest extends TestCase
{
    public function testAuthorizesWhenAttributeValueIsAllowed(): void
    {
        $guard = new RequestAttributeGuard('role', ['admin', 'owner']);
        $request = Request::fromArray('GET', '/admin', attributes: ['role' => 'admin']);

        self::assertTrue($guard->isAuthorized($request));
    }

    public function testRejectsWhenAttributeValueIsNotAllowed(): void
    {
        $guard = new RequestAttributeGuard('role', ['admin']);
        $request = Request::fromArray('GET', '/admin', attributes: ['role' => 'teacher']);

        self::assertFalse($guard->isAuthorized($request));
    }

    public function testRejectsWhenAttributeIsMissing(): void
    {
        $guard = new RequestAttributeGuard('role', ['admin']);
        $request = Request::fromArray('GET', '/admin');

        self::assertFalse($guard->isAuthorized($request));
    }

    public function testRejectsComplexAttributeValues(): void
    {
        $guard = new RequestAttributeGuard('role', ['admin']);
        $request = Request::fromArray('GET', '/admin', attributes: ['role' => ['admin']]);

        self::assertFalse($guard->isAuthorized($request));
    }
}
