<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Rendering;

use PHPUnit\Framework\TestCase;
use Zephyrus\Rendering\PhpEngine;
use Zephyrus\Rendering\RenderEngine;
use Zephyrus\Rendering\RenderException;

final class PhpEngineTest extends TestCase
{
    private string $viewsDir;

    protected function setUp(): void
    {
        $this->viewsDir = __DIR__ . '/fixtures/views';
    }

    public function testImplementsRenderEngine(): void
    {
        $engine = new PhpEngine($this->viewsDir);
        self::assertInstanceOf(RenderEngine::class, $engine);
    }

    public function testRendersSimpleTemplate(): void
    {
        $engine = new PhpEngine($this->viewsDir);
        $output = $engine->render('hello', ['name' => 'World']);

        self::assertSame('Hello, World!', $output);
    }

    public function testRendersTemplateWithMultipleVariables(): void
    {
        $engine = new PhpEngine($this->viewsDir);
        $output = $engine->render('page', [
            'title' => 'Test Page',
            'content' => 'Hello from PHP',
        ]);

        self::assertStringContainsString('<h1>Test Page</h1>', $output);
        self::assertStringContainsString('<p>Hello from PHP</p>', $output);
    }

    public function testRendersNestedTemplate(): void
    {
        $engine = new PhpEngine($this->viewsDir);
        $output = $engine->render('nested/deep', ['value' => 'test']);

        self::assertSame('Nested: test', $output);
    }

    public function testThrowsForMissingTemplate(): void
    {
        $engine = new PhpEngine($this->viewsDir);

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('Template [nonexistent] not found');
        $engine->render('nonexistent');
    }

    public function testThrowsForRenderError(): void
    {
        $engine = new PhpEngine($this->viewsDir);

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('Failed to render template [error]');
        $engine->render('error');
    }

    public function testExistsReturnsTrueForExistingTemplate(): void
    {
        $engine = new PhpEngine($this->viewsDir);

        self::assertTrue($engine->exists('hello'));
        self::assertTrue($engine->exists('page'));
        self::assertTrue($engine->exists('nested/deep'));
    }

    public function testExistsReturnsFalseForMissingTemplate(): void
    {
        $engine = new PhpEngine($this->viewsDir);

        self::assertFalse($engine->exists('nonexistent'));
        self::assertFalse($engine->exists('hello.latte')); // Wrong extension
    }

    public function testCustomExtension(): void
    {
        $engine = new PhpEngine($this->viewsDir, '.latte');
        // .latte templates can also be rendered as plain PHP includes.
        // (Latte syntax won't be processed, but the file exists.)
        self::assertTrue($engine->exists('hello'));
    }

    public function testEscapesHtmlWhenTemplateUsesHtmlspecialchars(): void
    {
        $engine = new PhpEngine($this->viewsDir);
        $output = $engine->render('hello', ['name' => '<script>xss</script>']);

        // Our PHP template uses htmlspecialchars, so XSS is escaped.
        self::assertStringNotContainsString('<script>', $output);
        self::assertStringContainsString('&lt;script&gt;', $output);
    }

    public function testVariableIsolation(): void
    {
        $engine = new PhpEngine($this->viewsDir);

        // Render with different args should not leak state.
        $first = $engine->render('hello', ['name' => 'Alice']);
        $second = $engine->render('hello', ['name' => 'Bob']);

        self::assertSame('Hello, Alice!', $first);
        self::assertSame('Hello, Bob!', $second);
    }
}
