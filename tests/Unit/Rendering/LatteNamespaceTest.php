<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Rendering;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Rendering\LatteEngine;
use Zephyrus\Rendering\RenderException;

final class LatteNamespaceTest extends TestCase
{
    private string $fixtures;
    private string $workDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->fixtures = __DIR__ . '/fixtures/namespaces';
        $this->workDir = sys_get_temp_dir() . '/zephyrus-latte-ns-' . bin2hex(random_bytes(8));
        $this->cacheDir = $this->workDir . '/cache';
        mkdir($this->cacheDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workDir);
    }

    private function engine(string $views, string $cacheMode = 'always'): LatteEngine
    {
        $engine = new LatteEngine($views, $this->cacheDir, $cacheMode);
        $engine->addNamespace('acme', $this->fixtures . '/package');

        return $engine;
    }

    private function plainViews(): string
    {
        return __DIR__ . '/fixtures/views';
    }

    private function overridingViews(): string
    {
        return $this->fixtures . '/views';
    }

    public function testANamespacedPageRendersFromThePackageDirectory(): void
    {
        $output = $this->engine($this->plainViews())->render('@acme/greeting', ['name' => 'World']);

        self::assertSame('Package greeting World', $output);
    }

    public function testExistsFollowsTheNamespaceResolution(): void
    {
        $engine = $this->engine($this->plainViews());

        self::assertTrue($engine->exists('@acme/greeting'));
        self::assertFalse($engine->exists('@acme/missing'));
        self::assertFalse($engine->exists('@nope/greeting'));
    }

    public function testTheApplicationOverrideWinsOverThePackageTemplate(): void
    {
        $output = $this->engine($this->overridingViews())->render('@acme/greeting', ['name' => 'World']);

        self::assertSame('Overridden greeting World', $output);
    }

    public function testAPackageTemplateWithoutOverrideStillComesFromThePackage(): void
    {
        $output = $this->engine($this->overridingViews())->render('@acme/partial');

        self::assertSame('[partial]', $output);
    }

    public function testAnApplicationViewIncludesANamespacedTemplate(): void
    {
        $output = $this->engine($this->overridingViews())->render('includes-package');

        self::assertSame('App [partial]', $output);
    }

    public function testAPackageTemplateIncludesANamespacedTemplateThroughTheOverride(): void
    {
        $output = $this->engine($this->overridingViews())->render('@acme/nested');

        self::assertSame('Nested Overridden greeting x', $output);
    }

    public function testAPackageTemplateIncludesANamespacedTemplateFromThePackage(): void
    {
        $output = $this->engine($this->plainViews())->render('@acme/nested');

        self::assertSame('Nested Package greeting x', $output);
    }

    public function testAPackageTemplateIncludesASiblingByRelativeName(): void
    {
        $output = $this->engine($this->plainViews())->render('@acme/relative');

        self::assertSame('Wrap [partial]', $output);
    }

    public function testALayoutCanComeFromANamespace(): void
    {
        $output = $this->engine($this->overridingViews())->render('extends-package');

        self::assertSame('<main>child</main>', $output);
    }

    public function testAnEmbedCanComeFromANamespace(): void
    {
        $output = $this->engine($this->overridingViews())->render('embeds-package');

        self::assertSame('<box>inner</box>', $output);
    }

    public function testAnImportCanComeFromANamespace(): void
    {
        $output = $this->engine($this->overridingViews())->render('imports-package');

        self::assertSame('Hi Ana', $output);
    }

    public function testAnOverrideDoesNotSeeItsPackageSiblingsByRelativeName(): void
    {
        $engine = $this->engine($this->overridingViews());

        try {
            $engine->render('@acme/relative');
            self::fail('expected a refusal');
        } catch (RenderException $e) {
            self::assertStringStartsWith('Failed to render template [@acme/relative]: ', $e->getMessage());
            self::assertStringContainsString(
                $this->fixtures . '/views/vendor/acme/partial.latte',
                $e->getMessage(),
            );
        }
    }

    public function testAnAtSignPageWithoutNamespaceSeparatorStaysAPlainPage(): void
    {
        foreach ([null, 'acme'] as $declared) {
            $engine = new LatteEngine($this->overridingViews(), $this->cacheDir);
            if ($declared !== null) {
                $engine->addNamespace($declared, $this->fixtures . '/package');
            }

            self::assertTrue($engine->exists('@layout'));
            self::assertSame('<L></L>', $engine->render('@layout'));
            self::assertSame('<L>child</L>', $engine->render('uses-at-layout'));
        }
    }

    public function testAnOverrideAddedAfterAFirstRenderIsSeenInNeverMode(): void
    {
        $views = $this->workDir . '/views';
        mkdir($views, 0775, true);
        $engine = $this->engine($views, 'never');

        self::assertSame('Package greeting A', $engine->render('@acme/greeting', ['name' => 'A']));

        mkdir($views . '/vendor/acme', 0775, true);
        file_put_contents($views . '/vendor/acme/greeting.latte', 'Local greeting {$name}');

        self::assertSame('Local greeting A', $engine->render('@acme/greeting', ['name' => 'A']));
        self::assertSame([], glob($this->cacheDir . '/*') ?: []);
    }

    public function testAViewWithoutNamespaceRendersAsBefore(): void
    {
        $output = $this->engine($this->overridingViews())->render('plain', ['name' => 'World']);

        self::assertSame('Plain World', $output);
    }

    public function testTheSameRelativeNameInTwoNamespacesRendersEachOwnTemplate(): void
    {
        $engine = $this->engine($this->plainViews());
        $engine->addNamespace('other', $this->fixtures . '/other');

        self::assertSame('Package greeting A', $engine->render('@acme/greeting', ['name' => 'A']));
        self::assertSame('Other greeting', $engine->render('@other/greeting'));
    }

    public function testAnOverrideAddedAfterARenderIsNotShadowedByTheCompiledCache(): void
    {
        $views = $this->workDir . '/views';
        mkdir($views, 0775, true);
        $engine = $this->engine($views);

        self::assertSame('Package greeting A', $engine->render('@acme/greeting', ['name' => 'A']));

        mkdir($views . '/vendor/acme', 0775, true);
        file_put_contents($views . '/vendor/acme/greeting.latte', 'Local greeting {$name}');

        self::assertSame('Local greeting A', $engine->render('@acme/greeting', ['name' => 'A']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedPages(): array
    {
        return [
            'parent segment' => ['@acme/../secret'],
            'null byte' => ["@acme/greeting\0"],
            'empty rest' => ['@acme/'],
            'no separator' => ['@acme'],
        ];
    }

    #[DataProvider('refusedPages')]
    public function testAPageThatEscapesTheNamespaceIsNotFound(string $page): void
    {
        $engine = $this->engine($this->overridingViews());

        self::assertFalse($engine->exists($page));
        try {
            $output = $engine->render($page);
            self::fail('expected a refusal, rendered: ' . $output);
        } catch (RenderException $e) {
            self::assertStringStartsWith('Template [', $e->getMessage());
            self::assertStringEndsWith('] not found.', $e->getMessage());
        }
    }

    public function testASymbolicLinkLeavingThePackageRootIsNotFound(): void
    {
        $package = $this->workDir . '/package';
        mkdir($package, 0775, true);
        file_put_contents($this->workDir . '/secret.latte', 'secret');
        symlink($this->workDir . '/secret.latte', $package . '/link.latte');
        $engine = new LatteEngine($this->plainViews(), $this->cacheDir);
        $engine->addNamespace('acme', $package);

        self::assertFalse($engine->exists('@acme/link'));
        try {
            $engine->render('@acme/link');
            self::fail('expected a refusal');
        } catch (RenderException $e) {
            self::assertSame('Template [@acme/link] not found.', $e->getMessage());
        }
    }

    public function testASymbolicLinkLeavingTheOverrideRootFallsBackToThePackage(): void
    {
        $views = $this->workDir . '/views';
        mkdir($views . '/vendor/acme', 0775, true);
        file_put_contents($this->workDir . '/secret.latte', 'secret');
        symlink($this->workDir . '/secret.latte', $views . '/vendor/acme/greeting.latte');

        $output = $this->engine($views)->render('@acme/greeting', ['name' => 'A']);

        self::assertSame('Package greeting A', $output);
    }

    public function testAnUnknownNamespaceNamesTheRequestedAndTheDeclaredOnes(): void
    {
        $engine = $this->engine($this->plainViews());
        $engine->addNamespace('other', $this->fixtures . '/other');

        try {
            $engine->render('@nope/x');
            self::fail('expected a refusal');
        } catch (RenderException $e) {
            self::assertSame(
                'Template namespace "nope" is not declared (call LatteEngine::addNamespace() first). Declared namespaces: "acme", "other".',
                $e->getMessage(),
            );
        }
    }

    public function testAnUnknownNamespaceWithNoneDeclaredSaysSo(): void
    {
        $engine = new LatteEngine($this->plainViews(), $this->cacheDir);

        try {
            $engine->render('@nope/x');
            self::fail('expected a refusal');
        } catch (RenderException $e) {
            self::assertSame(
                'Template namespace "nope" is not declared (call LatteEngine::addNamespace() first). Declared namespaces: none.',
                $e->getMessage(),
            );
        }
    }

    public function testIncludingAnUnknownNamespaceFailsTheRender(): void
    {
        $engine = $this->engine($this->overridingViews());

        try {
            $engine->render('includes-unknown');
            self::fail('expected a refusal');
        } catch (RenderException $e) {
            self::assertSame(
                'Failed to render template [includes-unknown]: Template namespace "nope" is not declared'
                . ' (call LatteEngine::addNamespace() first). Declared namespaces: "acme".',
                $e->getMessage(),
            );
        }
    }

    public function testAMissingTemplateInAKnownNamespaceIsTheUsualNotFound(): void
    {
        $engine = $this->engine($this->plainViews());

        try {
            $engine->render('@acme/missing');
            self::fail('expected a refusal');
        } catch (RenderException $e) {
            self::assertSame('Template [@acme/missing] not found.', $e->getMessage());
            self::assertSame($this->fixtures . '/package/missing.latte', $e->resolvedPath());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidNames(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['Acme'],
            'leading dash' => ['-acme'],
            'leading underscore' => ['_acme'],
            'slash' => ['acme/sub'],
            'dot' => ['acme.sub'],
            'at sign' => ['@acme'],
            'space' => ['ac me'],
        ];
    }

    #[DataProvider('invalidNames')]
    public function testAnInvalidNamespaceNameIsRefused(string $name): void
    {
        $engine = new LatteEngine($this->plainViews(), $this->cacheDir);

        try {
            $engine->addNamespace($name, $this->fixtures . '/package');
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                sprintf(
                    'Template namespace %s is invalid: use lowercase letters, digits, "_" and "-", '
                    . 'starting with a letter or a digit.',
                    '"' . addcslashes($name, '"\\') . '"',
                ),
                $e->getMessage(),
            );
        }
    }

    public function testAValidNamespaceNameWithDigitsDashesAndUnderscoresIsAccepted(): void
    {
        $engine = new LatteEngine($this->plainViews(), $this->cacheDir);
        $engine->addNamespace('0-a_b', $this->fixtures . '/package');

        self::assertTrue($engine->exists('@0-a_b/greeting'));
    }

    public function testAMissingNamespaceDirectoryIsRefused(): void
    {
        $engine = new LatteEngine($this->plainViews(), $this->cacheDir);
        $directory = $this->workDir . '/absent';

        try {
            $engine->addNamespace('acme', $directory);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                sprintf('Template namespace "acme" needs an existing absolute directory, got "%s".', $directory),
                $e->getMessage(),
            );
        }
    }

    public function testARelativeNamespaceDirectoryIsRefused(): void
    {
        $engine = new LatteEngine($this->plainViews(), $this->cacheDir);

        try {
            $engine->addNamespace('acme', 'tests/Unit/Rendering/fixtures/namespaces/package');
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Template namespace "acme" needs an existing absolute directory, '
                . 'got "tests/Unit/Rendering/fixtures/namespaces/package".',
                $e->getMessage(),
            );
        }
    }

    public function testDeclaringANamespaceTwiceIsRefused(): void
    {
        $engine = $this->engine($this->plainViews());

        try {
            $engine->addNamespace('acme', $this->fixtures . '/other');
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Template namespace "acme" is already declared.', $e->getMessage());
        }
        self::assertSame('Package greeting A', $engine->render('@acme/greeting', ['name' => 'A']));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
