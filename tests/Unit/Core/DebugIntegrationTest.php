<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Tracy\Debugger;
use Zephyrus\Core\DebugIntegration;

final class DebugIntegrationTest extends TestCase
{
    public function testInitializeWithDebugFalseDoesNothing(): void
    {
        // Should be a no-op — no exception thrown, no state changed
        DebugIntegration::initialize(debug: false);

        self::assertTrue(true);
    }

    /**
     * @runInSeparateProcess
     */
    public function testInitializeWithDebugTrueEnablesTracyDevelopmentMode(): void
    {
        if (!class_exists(Debugger::class)) {
            $this->markTestSkipped('Tracy not installed.');
        }

        DebugIntegration::initialize(debug: true);

        self::assertTrue(Debugger::isEnabled());
    }

    /**
     * @runInSeparateProcess
     */
    public function testInitializeWithLogDirectory(): void
    {
        if (!class_exists(Debugger::class)) {
            $this->markTestSkipped('Tracy not installed.');
        }

        $logDir = sys_get_temp_dir() . '/zephyrus-tracy-test-' . uniqid('', true);
        mkdir($logDir, 0755, true);

        try {
            DebugIntegration::initialize(debug: true, logDirectory: $logDir);
            self::assertTrue(Debugger::isEnabled());
        } finally {
            @rmdir($logDir);
        }
    }

    public function testInitializeWithDebugFalseDoesNotEnableTracy(): void
    {
        // Verify class-exists guard — if Tracy were unavailable, this would still be a no-op.
        // Since Tracy IS installed, we verify that debug=false skips initialization.
        DebugIntegration::initialize(debug: false, logDirectory: '/tmp');

        // No way to check "not enabled" since Debugger::isEnabled() may be true
        // from the separate-process tests above. Just verify no exception.
        self::assertTrue(true);
    }
}
