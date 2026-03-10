<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Rendering;

use PHPUnit\Framework\TestCase;
use Zephyrus\Controller\Controller;
use Zephyrus\Http\Response;
use Zephyrus\Rendering\PhpEngine;
use Zephyrus\Rendering\RenderEngine;
use Zephyrus\Rendering\RenderException;
use Zephyrus\Rendering\RenderResponses;

final class RenderResponsesTest extends TestCase
{
    private string $viewsDir;

    protected function setUp(): void
    {
        $this->viewsDir = __DIR__ . '/fixtures/views';
    }

    public function testRenderReturnsHtmlResponse(): void
    {
        $controller = $this->createTestController();
        $controller->setRenderEngine(new PhpEngine($this->viewsDir));

        $response = $controller->testRender('hello', ['name' => 'World']);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->status);
        self::assertSame('Hello, World!', $response->body);
        self::assertSame('text/html; charset=utf-8', $response->headers['content-type']);
    }

    public function testRenderWithCustomStatus(): void
    {
        $controller = $this->createTestController();
        $controller->setRenderEngine(new PhpEngine($this->viewsDir));

        $response = $controller->testRender('hello', ['name' => 'Error'], 500);

        self::assertSame(500, $response->status);
    }

    public function testRenderWithEngineReturnsHtmlResponse(): void
    {
        $controller = $this->createTestController();
        $engine = new PhpEngine($this->viewsDir);

        $response = $controller->testRenderWith($engine, 'hello', ['name' => 'Custom']);

        self::assertSame('Hello, Custom!', $response->body);
        self::assertSame('text/html; charset=utf-8', $response->headers['content-type']);
    }

    public function testHtmlReturnsRawHtmlResponse(): void
    {
        $controller = $this->createTestController();

        $response = $controller->testHtml('<h1>Raw HTML</h1>');

        self::assertSame('<h1>Raw HTML</h1>', $response->body);
        self::assertSame(200, $response->status);
        self::assertSame('text/html; charset=utf-8', $response->headers['content-type']);
    }

    public function testHtmlWithCustomStatus(): void
    {
        $controller = $this->createTestController();

        $response = $controller->testHtml('<h1>Not Found</h1>', 404);

        self::assertSame(404, $response->status);
    }

    public function testRenderThrowsWhenNoEngineConfigured(): void
    {
        $controller = $this->createTestController();

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('No render engine has been configured');
        $controller->testRender('hello', ['name' => 'World']);
    }

    /**
     * Create a test controller that exposes the protected trait methods.
     */
    private function createTestController(): TestRenderController
    {
        return new TestRenderController();
    }
}

/**
 * Concrete test controller that uses RenderResponses and exposes protected methods.
 */
final class TestRenderController extends Controller
{
    use RenderResponses;

    public function testRender(string $page, array $args = [], int $status = 200): Response
    {
        return $this->render($page, $args, $status);
    }

    public function testRenderWith(RenderEngine $engine, string $page, array $args = [], int $status = 200): Response
    {
        return $this->renderWith($engine, $page, $args, $status);
    }

    public function testHtml(string $content, int $status = 200): Response
    {
        return $this->html($content, $status);
    }
}
