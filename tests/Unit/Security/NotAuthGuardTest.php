<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;
use Zephyrus\Security\AuthGuardInterface;
use Zephyrus\Security\NotAuthGuard;

final class NotAuthGuardTest extends TestCase
{
    public function testNegatesInnerGuardWhenInnerAllows(): void
    {
        $guard = new NotAuthGuard($this->guard(true));

        self::assertFalse($guard->isAuthorized(Request::fromArray('GET', '/secure')));
    }

    public function testNegatesInnerGuardWhenInnerDenies(): void
    {
        $guard = new NotAuthGuard($this->guard(false));

        self::assertTrue($guard->isAuthorized(Request::fromArray('GET', '/secure')));
    }

    private function guard(bool $allowed): AuthGuardInterface
    {
        return new class($allowed) implements AuthGuardInterface {
            public function __construct(private readonly bool $allowed)
            {
            }

            public function isAuthorized(Request $request): bool
            {
                return $this->allowed;
            }
        };
    }
}
