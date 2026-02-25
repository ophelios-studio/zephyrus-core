<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;
use Zephyrus\Security\AllAuthGuard;
use Zephyrus\Security\AnyAuthGuard;
use Zephyrus\Security\AuthGuardInterface;

final class CompositeAuthGuardTest extends TestCase
{
    public function testAnyAuthGuardAllowsWhenAtLeastOneGuardPasses(): void
    {
        $guard = new AnyAuthGuard([
            $this->guard(false),
            $this->guard(true),
        ]);

        self::assertTrue($guard->isAuthorized(Request::fromArray('GET', '/secure')));
    }

    public function testAnyAuthGuardRejectsWhenAllGuardsFail(): void
    {
        $guard = new AnyAuthGuard([
            $this->guard(false),
            $this->guard(false),
        ]);

        self::assertFalse($guard->isAuthorized(Request::fromArray('GET', '/secure')));
    }

    public function testAnyAuthGuardWithNoGuardsRejectsByDefault(): void
    {
        $guard = new AnyAuthGuard([]);

        self::assertFalse($guard->isAuthorized(Request::fromArray('GET', '/secure')));
    }

    public function testAllAuthGuardAllowsWhenAllGuardsPass(): void
    {
        $guard = new AllAuthGuard([
            $this->guard(true),
            $this->guard(true),
        ]);

        self::assertTrue($guard->isAuthorized(Request::fromArray('GET', '/secure')));
    }

    public function testAllAuthGuardRejectsWhenOneGuardFails(): void
    {
        $guard = new AllAuthGuard([
            $this->guard(true),
            $this->guard(false),
        ]);

        self::assertFalse($guard->isAuthorized(Request::fromArray('GET', '/secure')));
    }

    public function testAllAuthGuardWithNoGuardsAllowsByDefault(): void
    {
        $guard = new AllAuthGuard([]);

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
