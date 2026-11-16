<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Rendering;

use PHPUnit\Framework\TestCase;
use Zephyrus\Rendering\NamespacedLoader;
use Zephyrus\Rendering\RenderException;

final class NamespacedLoaderTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/zephyrus-loader-test-' . bin2hex(random_bytes(8));
        mkdir($this->workDir . '/views/vendor/acme', 0775, true);
        mkdir($this->workDir . '/package/sub', 0775, true);
        file_put_contents($this->workDir . '/package/sub/page.latte', 'nested');
        file_put_contents($this->workDir . '/package/home.latte', 'home');
        file_put_contents($this->workDir . '/package/.latte', 'hidden');
        file_put_contents($this->workDir . '/views/vendor/acme/home.latte', 'override');
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->workDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->workDir);
    }

    private function loader(string $views = '', string $package = ''): NamespacedLoader
    {
        $loader = new NamespacedLoader($views === '' ? $this->workDir . '/views' : $views);
        $loader->declare('acme', $package === '' ? $this->workDir . '/package' : $package);

        return $loader;
    }

    public function testANameBelowASubdirectoryResolvesToItsFile(): void
    {
        self::assertSame(
            realpath($this->workDir . '/package/sub/page.latte'),
            $this->loader()->resolve('@acme/sub/page.latte'),
        );
    }

    public function testTheApplicationOverrideWinsOverTheNamespaceDirectory(): void
    {
        self::assertSame(
            realpath($this->workDir . '/views/vendor/acme/home.latte'),
            $this->loader()->resolve('@acme/home.latte'),
        );
    }

    public function testDirectoriesGivenWithATrailingSeparatorStillResolve(): void
    {
        $loader = $this->loader($this->workDir . '/views/', $this->workDir . '/package/');

        self::assertSame(
            realpath($this->workDir . '/views/vendor/acme/home.latte'),
            $loader->resolve('@acme/home.latte'),
        );
        self::assertSame(
            realpath($this->workDir . '/package/sub/page.latte'),
            $loader->resolve('@acme/sub/page.latte'),
        );
    }

    public function testANamespaceWithoutATemplateNameResolvesToNothingEvenWhenTheSuffixNamesAFile(): void
    {
        self::assertNull($this->loader()->resolve('@acme/', '.latte'));
    }

    public function testACandidatePathDropsLeadingSeparatorsOfTheTemplateName(): void
    {
        self::assertSame(
            $this->workDir . '/package/page.latte',
            $this->loader($this->workDir . '/views', $this->workDir . '/package/')->candidatePath('@acme//page', '.latte'),
        );
    }

    public function testLoadingAMissingNamespacedTemplateRefusesWithItsCandidatePath(): void
    {
        try {
            $this->loader()->getContent('@acme/missing');
            self::fail('expected a refusal');
        } catch (RenderException $e) {
            self::assertSame('Template [@acme/missing] not found.', $e->getMessage());
            self::assertSame($this->workDir . '/package/missing', $e->resolvedPath());
        }
    }
}
