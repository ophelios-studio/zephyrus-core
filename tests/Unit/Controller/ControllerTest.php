<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Zephyrus\Controller\Controller;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

// ---------------------------------------------------------------------------
// Fixture — minimal concrete subclass
// ---------------------------------------------------------------------------

final class SampleController extends Controller
{
    public function index(): Response
    {
        return $this->json(['status' => 'ok']);
    }

    public function create(): Response
    {
        return $this->created(['id' => 1]);
    }

    public function ping(): Response
    {
        return $this->text('pong');
    }

    public function pingWithStatus(): Response
    {
        return $this->text('pong', 202);
    }

    public function empty(): Response
    {
        return $this->noContent();
    }

    public function custom(): Response
    {
        return $this->respond(['error' => 'not found'], 404);
    }
}

// ---------------------------------------------------------------------------

final class ControllerTest extends TestCase
{
    private SampleController $controller;

    protected function setUp(): void
    {
        $this->controller = new SampleController();
    }

    public function testJsonHelperReturns200WithJsonContentType(): void
    {
        $response = $this->controller->index();

        self::assertSame(200, $response->status);
        self::assertStringContainsString('application/json', $response->headers['Content-Type']);
        self::assertStringContainsString('"status":"ok"', $response->body);
    }

    public function testCreatedHelperReturns201(): void
    {
        $response = $this->controller->create();

        self::assertSame(201, $response->status);
        self::assertStringContainsString('"id":1', $response->body);
    }

    public function testTextHelperReturns200PlainText(): void
    {
        $response = $this->controller->ping();

        self::assertSame(200, $response->status);
        self::assertStringContainsString('text/plain', $response->headers['Content-Type']);
        self::assertSame('pong', $response->body);
    }

    public function testTextHelperRespectsCustomStatus(): void
    {
        $response = $this->controller->pingWithStatus();

        self::assertSame(202, $response->status);
        self::assertSame('pong', $response->body);
    }

    public function testNoContentHelperReturns204WithEmptyBody(): void
    {
        $response = $this->controller->empty();

        self::assertSame(204, $response->status);
        self::assertSame('', $response->body);
    }

    public function testRespondHelperReturnsCustomStatusJson(): void
    {
        $response = $this->controller->custom();

        self::assertSame(404, $response->status);
        self::assertStringContainsString('"error":"not found"', $response->body);
        self::assertStringContainsString('application/json', $response->headers['Content-Type']);
    }

    public function testControllerIsAbstract(): void
    {
        $reflection = new \ReflectionClass(Controller::class);

        self::assertTrue($reflection->isAbstract());
    }
}
