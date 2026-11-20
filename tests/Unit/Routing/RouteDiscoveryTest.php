<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\RouterConfig;
use Zephyrus\Routing\Exception\RouteSignatureException;
use Zephyrus\Routing\RouteDiscovery;

final class RouteDiscoveryTest extends TestCase
{
    private string $basePath;
    private ?ControllerTree $tree = null;
    private string $deepest = '';

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/zephyrus-discovery-' . bin2hex(random_bytes(8));
        mkdir($this->basePath);
    }

    protected function tearDown(): void
    {
        $this->tree?->remove();
        for ($directory = $this->deepest; $directory !== '' && $directory !== $this->basePath; $directory = dirname($directory)) {
            rmdir($directory);
        }

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

    public function testAControllerDeclaringARouteMethodThatIsNotATokenIsRefused(): void
    {
        $tree = new ControllerTree();

        try {
            $config = $tree->configure();
            $tree->controller(
                'SpacedMethodController',
                "final class SpacedMethodController\n{\n"
                . "    #[\\Zephyrus\\Routing\\Attribute\\Route('/x', 'GE T')]\n"
                . "    public function index(): void\n    {\n    }\n}",
            );

            (new RouteDiscovery($config, $tree->root->path))->routes();
            self::fail('expected a refusal');
        } catch (RouteSignatureException $exception) {
            self::assertSame(
                'Invalid route method "GE T" on route "/x": an HTTP method is a token of letters, digits and '
                . '!#$%&\'*+-.^_`|~ (RFC 9110), such as GET or PROPFIND',
                $exception->getMessage(),
            );
        } finally {
            $tree->remove();
        }
    }

    #[DataProvider('directoryLengths')]
    public function testTheDirectoryOfARefusalIsQuotedWholeUpTo512BytesThenCutFromItsStart(int $length, bool $whole): void
    {
        $directory = $this->emptyDirectoryOfLength($length);
        $config = RouterConfig::fromArray(['controllers' => ['App\Ctl' => $directory]]);

        try {
            (new RouteDiscovery($config, $this->basePath))->routes();
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            $resolved = $this->basePath . '/' . $directory;
            $quoted = $whole ? '"' . $resolved . '"' : '"...' . substr($resolved, -512) . '" (' . $length . ' bytes)';
            self::assertSame(
                sprintf(
                    'Configuration section \'router\' field \'controllers\' has invalid value "%s..." (%d bytes): '
                    . '%s holds no class of the namespace App\Ctl.',
                    substr($directory, 0, 64),
                    strlen($directory),
                    $quoted,
                ),
                $exception->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function directoryLengths(): iterable
    {
        yield 'exactly 512 bytes' => [512, true];
        yield '513 bytes' => [513, false];
    }

    public function testEveryConcreteClassIsListedWhateverAbstractClassesTheDirectoryHolds(): void
    {
        $this->tree = new ControllerTree();
        $this->tree->configure();
        $expected = [];
        foreach (range(1, 6) as $number) {
            $this->tree->controller('Base' . $number, 'abstract class Base' . $number . "\n{\n}");
            $this->tree->controller('Page' . $number, 'final class Page' . $number . "\n{\n}");
            $expected[] = $this->tree->namespace . '\\Page' . $number;
        }

        self::assertSame(
            $expected,
            RouteDiscovery::classesIn($this->tree->namespace, $this->tree->root->path . '/app/Controllers'),
        );
    }

    /**
     * Creates nested empty directories under the base path and returns the relative path, so that the
     * resolved directory is $length bytes long.
     */
    private function emptyDirectoryOfLength(int $length): string
    {
        $relativeLength = $length - strlen($this->basePath) - 1;
        $count = intdiv($relativeLength, 101) + 1;
        $letters = $relativeLength - ($count - 1);
        $segments = [];
        for ($index = 0; $index < $count; $index++) {
            $segments[] = str_repeat('d', intdiv($letters, $count) + ($index < $letters % $count ? 1 : 0));
        }
        $path = $this->basePath;
        foreach ($segments as $segment) {
            $path .= '/' . $segment;
            mkdir($path);
        }
        $this->deepest = $path;

        return implode('/', $segments);
    }
}
