<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\DebugIntegration;

final class DebugIntegrationClientEntryTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;

        parent::tearDown();
    }

    public function testAnAddressRangeRightAfterTheSecretIsRefusedAsARange(): void
    {
        try {
            DebugIntegration::allowedClientList('abcdefghijklmnop@/8');
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Debug client allowlist entry 1 is a range: Tracy matches exact addresses only, so list each address.',
                $e->getMessage(),
            );
        }
    }

    public function testASecretEndingWithASlashIsNotMistakenForARange(): void
    {
        self::assertSame(
            ['abcdefghijklmno/@172.17.0.1'],
            DebugIntegration::allowedClientList('abcdefghijklmno/@172.17.0.1'),
        );
    }

    public function testABareEntryThatIsNotAnIpAddressIsAdmittedBehindAForwardingHeader(): void
    {
        $_SERVER['REMOTE_ADDR'] = 'peer.example.test';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20';

        self::assertTrue(DebugIntegration::admitsClient(['peer.example.test']));
    }
}
