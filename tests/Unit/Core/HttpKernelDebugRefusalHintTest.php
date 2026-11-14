<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zephyrus\Core\DebugIntegration;
use Zephyrus\Core\ExceptionEvent;
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Event\EventDispatcher;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Router;

/**
 * The 500 log line says why the debugger refused the client, when debug is on.
 *
 * Every case runs in its own process: Debugger::enable() installs global handlers.
 */
final class HttpKernelDebugRefusalHintTest extends TestCase
{
    private const string LOG_PREFIX = 'Zephyrus: unhandled exception, answered with a 500';

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheLogLineNamesTheClientAnAllowlistDidNotAdmit(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        DebugIntegration::initialize(debug: true, allowedClients: ['203.0.113.77'], serverApi: 'fpm-fcgi');

        [$written, $exception] = $this->failingRequest();

        self::assertStringContainsString(
            self::LOG_PREFIX . ' (the debugger did not admit 203.0.113.9; add it, or secret@203.0.113.9, '
            . 'to withDebugClientAllowlist()): ' . $exception,
            $written,
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheLogLineExplainsThatNoAllowlistAdmitsNoClient(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        DebugIntegration::initialize(debug: true, serverApi: 'fpm-fcgi');

        [$written, $exception] = $this->failingRequest();

        self::assertStringContainsString(
            self::LOG_PREFIX . ' (the debugger admits no client without an allowlist, except a direct loopback '
            . 'client under php -S; see withDebugClientAllowlist()): ' . $exception,
            $written,
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidRemoteAddressProvider(): array
    {
        return [
            'line break'          => ["203.0.113.9\n[forged]"],
            'injected suffix'     => ['1.2.3.4);forged:x'],
            'space'               => ['203.0.113.9 extra'],
            'too long'            => [str_repeat('1', 46)],
        ];
    }

    #[DataProvider('invalidRemoteAddressProvider')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testARemoteAddressThatIsNotAnIpIsReportedAsUnknown(string $address): void
    {
        $_SERVER['REMOTE_ADDR'] = $address;
        DebugIntegration::initialize(debug: true, allowedClients: ['203.0.113.77'], serverApi: 'fpm-fcgi');

        [$written, $exception] = $this->failingRequest();

        self::assertStringContainsString(
            self::LOG_PREFIX . ' (the debugger did not admit unknown; add it, or secret@unknown, '
            . 'to withDebugClientAllowlist()): ' . $exception,
            $written,
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function relayedRequestProvider(): array
    {
        $cases = [];
        foreach (DebugIntegrationExposureTest::forwardingHeaderProvider() as $name => [$header, $value]) {
            $cases[$name . ', client not listed'] = ['203.0.113.9', '203.0.113.77', $header, $value];
            $cases[$name . ', loopback listed'] = ['127.0.0.1', '127.0.0.1', $header, $value];
        }

        return $cases;
    }

    #[DataProvider('relayedRequestProvider')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testARelayedRequestGetsTheSecretEntryHint(
        string $address,
        string $listed,
        string $header,
        string $value,
    ): void {
        $_SERVER['REMOTE_ADDR'] = $address;
        $_SERVER[$header] = $value;
        DebugIntegration::initialize(debug: true, allowedClients: [$listed], serverApi: 'fpm-fcgi');

        [$written, $exception] = $this->failingRequest();

        self::assertStringContainsString(
            self::LOG_PREFIX . ' (the debugger did not admit ' . $address . ', which forwarded the request; '
            . 'add secret@' . $address . ' to withDebugClientAllowlist() and send the tracy-debug cookie): '
            . $exception,
            $written,
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInitializingWithoutConfigurationClearsTheHint(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        DebugIntegration::initialize(debug: true, allowedClients: ['203.0.113.77'], serverApi: 'fpm-fcgi');
        self::assertNotNull(DebugIntegration::refusalHint());

        DebugIntegration::initializeWithoutConfiguration();

        self::assertNull(DebugIntegration::refusalHint());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnAdmittedClientLeavesTheLogLineUnchanged(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
        DebugIntegration::initialize(debug: true, allowedClients: ['203.0.113.77'], serverApi: 'fpm-fcgi');

        [$written, $exception] = $this->failingRequest();

        self::assertStringContainsString(self::LOG_PREFIX . ': ' . $exception, $written);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testADisabledDebuggerLeavesTheLogLineUnchanged(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        DebugIntegration::initialize(debug: false, allowedClients: ['203.0.113.77'], serverApi: 'fpm-fcgi');

        [$written, $exception] = $this->failingRequest();

        self::assertStringContainsString(self::LOG_PREFIX . ': ' . $exception, $written);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASecondInitializeThatAdmitsTheClientClearsTheHint(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        DebugIntegration::initialize(debug: true, allowedClients: ['203.0.113.77'], serverApi: 'fpm-fcgi');
        DebugIntegration::initialize(debug: true, allowedClients: ['203.0.113.9'], serverApi: 'fpm-fcgi');

        [$written, $exception] = $this->failingRequest();

        self::assertStringContainsString(self::LOG_PREFIX . ': ' . $exception, $written);
    }

    /**
     * @return array{0: string, 1: RuntimeException} The error log written and the exception the kernel answered.
     */
    private function failingRequest(): array
    {
        $caught = null;
        $events = new EventDispatcher();
        $events->addListener(ExceptionEvent::class, static function (ExceptionEvent $event) use (&$caught): void {
            $caught = $event->getException();
        });
        $kernel = KernelBuilder::create()
            ->withRouter((new Router())->get('/boom', RefusalHintController::class . '@boom'))
            ->withEventDispatcher($events)
            ->build();

        $logFile = tempnam(sys_get_temp_dir(), 'zephyrus-refusal-hint-');
        self::assertIsString($logFile);
        ini_set('error_log', $logFile);
        ini_set('log_errors', '1');

        try {
            $kernel->handle(Request::fromArray('GET', '/boom'));
            $written = (string) file_get_contents($logFile);
        } finally {
            @unlink($logFile);
        }

        self::assertInstanceOf(RuntimeException::class, $caught);

        return [$written, $caught];
    }
}

final class RefusalHintController
{
    public function boom(): Response
    {
        throw new RuntimeException('handler exploded');
    }
}
