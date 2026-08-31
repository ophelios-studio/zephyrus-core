<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zephyrus\Core\ExceptionEvent;
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Event\EventDispatcher;
use Zephyrus\Http\Request;
use Zephyrus\Routing\Router;

/**
 * The kernel swallows anything an ExceptionEvent listener throws, so a reporter
 * cannot turn a handled error response into a dead connection. That guarantee
 * is right, and it stays.
 *
 * What was wrong is that the catch block was EMPTY. An application whose error
 * reporter had been broken for weeks had no way to find out, from anywhere.
 * The failure is now written to the error log, which is where an operator can
 * see it and where it cannot reach the client.
 */
final class HttpKernelListenerFailureLoggingTest extends TestCase
{
    public function testAFailingExceptionListenerIsWrittenToTheErrorLog(): void
    {
        $events = new EventDispatcher();
        $events->addListener(ExceptionEvent::class, static function (): void {
            throw new RuntimeException('the reporter is broken');
        });

        // An empty router: the routing failure is enough to fire the seam.
        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->withEventDispatcher($events)
            ->build();

        $logFile = tempnam(sys_get_temp_dir(), 'zephyrus-listener-failure-');
        self::assertIsString($logFile);

        $previousLog = ini_get('error_log');
        ini_set('error_log', $logFile);
        ini_set('log_errors', '1');

        try {
            $response = $kernel->handle(Request::fromArray('GET', '/missing'));
            $written = (string) file_get_contents($logFile);
        } finally {
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
            @unlink($logFile);
        }

        // The response still survives: that is the guarantee the kernel makes.
        self::assertSame(404, $response->status);

        self::assertStringContainsString('an ExceptionEvent listener failed', $written);
        self::assertStringContainsString('the reporter is broken', $written);
        self::assertStringContainsString(RuntimeException::class, $written);
    }

    public function testNothingIsLoggedWhenNoListenerFails(): void
    {
        $events = new EventDispatcher();
        $events->addListener(ExceptionEvent::class, static function (): void {
            // A well-behaved reporter.
        });

        // An empty router: the routing failure is enough to fire the seam.
        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->withEventDispatcher($events)
            ->build();

        $logFile = tempnam(sys_get_temp_dir(), 'zephyrus-listener-failure-');
        self::assertIsString($logFile);

        $previousLog = ini_get('error_log');
        ini_set('error_log', $logFile);
        ini_set('log_errors', '1');

        try {
            $kernel->handle(Request::fromArray('GET', '/missing'));
            $written = (string) file_get_contents($logFile);
        } finally {
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
            @unlink($logFile);
        }

        self::assertStringNotContainsString('an ExceptionEvent listener failed', $written);
    }
}
