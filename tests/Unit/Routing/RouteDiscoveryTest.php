<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\RouterConfig;
use Zephyrus\Routing\RouteDiscovery;

final class RouteDiscoveryTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/zephyrus-discovery-' . bin2hex(random_bytes(8));
        mkdir($this->basePath);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->basePath . "/ctl\r\nFAKE")) {
            rmdir($this->basePath . "/ctl\r\nFAKE");
        }

        if (is_dir($this->basePath)) {
            rmdir($this->basePath);
        }
    }

    public function testAControllerDirectoryHoldingNoClassIsRefusedWithItsDirectoryEscaped(): void
    {
        mkdir($this->basePath . "/ctl\r\nFAKE");
        $config = RouterConfig::fromArray(['controllers' => ['App\Ctl' => "ctl\r\nFAKE"]]);

        try {
            (new RouteDiscovery($config, $this->basePath))->routes();
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                'Configuration section \'router\' field \'controllers\' has invalid value "ctl\r\nFAKE": '
                . '"' . $this->basePath . '/ctl\r\nFAKE" holds no class of the namespace App\Ctl.',
                $exception->getMessage(),
            );
        }
    }
}
