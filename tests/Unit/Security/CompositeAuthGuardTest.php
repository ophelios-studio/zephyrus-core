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

    public function testAnyAuthGuardWithNoGuardsThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one auth guard must be provided.');
        new AnyAuthGuard([]);
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

    public function testAllAuthGuardWithNoGuardsThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one auth guard must be provided.');
        new AllAuthGuard([]);
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
