<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Zephyrus\Controller\Controller;
use Zephyrus\Controller\ControllerLifecycleInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Validation\ErrorBag;
use Zephyrus\Validation\FieldValidator;
use Zephyrus\Validation\FormValidator;
use Zephyrus\Validation\Rules;
use Zephyrus\Validation\ValidationException;

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

// ---------------------------------------------------------------------------
// Lifecycle fixtures
// ---------------------------------------------------------------------------

/** Override before() to guard access */
final class GuardedController extends Controller
{
    public bool $handlerCalled = false;

    public function before(Request $request): ?Response
    {
        if ($request->header('X-Token') !== 'secret') {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        return null;
    }

    public function act(): Response
    {
        $this->handlerCalled = true;

        return $this->json(['ok' => true]);
    }
}

/** Override after() to add a response header */
final class HeaderDecoratingController extends Controller
{
    public function after(Request $request, Response $response): Response
    {
        return $response->withHeader('X-Powered-By', 'Zephyrus');
    }

    public function act(): Response
    {
        return $this->json(['data' => 'value']);
    }
}

/** Exposes validate() via public proxy for testing */
final class ValidatingController extends Controller
{
    public function runValidate(FormValidator $form, array $data): ErrorBag
    {
        return $this->validate($form, $data);
    }
}

/** Override both hooks */
final class BothHooksController extends Controller
{
    public function before(Request $request): ?Response
    {
        if ($request->query('block') === '1') {
            return Response::text('blocked', 403);
        }

        return null;
    }

    public function after(Request $request, Response $response): Response
    {
        return $response->withHeader('X-After', 'yes');
    }

    public function act(): Response
    {
        return $this->text('handled');
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

    // -- ControllerLifecycleInterface -----------------------------------------

    public function testControllerImplementsLifecycleInterface(): void
    {
        self::assertInstanceOf(ControllerLifecycleInterface::class, $this->controller);
    }

    public function testBeforeDefaultReturnsNull(): void
    {
        $request = Request::fromArray('GET', '/');

        self::assertNull($this->controller->before($request));
    }

    public function testAfterDefaultPassesThroughResponse(): void
    {
        $request  = Request::fromArray('GET', '/');
        $response = Response::text('hello');

        $result = $this->controller->after($request, $response);

        self::assertSame($response, $result);
    }

    public function testOverriddenBeforeReturnsResponseOnFailure(): void
    {
        $controller = new GuardedController();
        $request    = Request::fromArray('GET', '/', headers: ['X-Token' => 'wrong']);

        $early = $controller->before($request);

        self::assertNotNull($early);
        self::assertSame(401, $early->status);
        self::assertStringContainsString('"error":"Unauthorized"', $early->body);
    }

    public function testOverriddenBeforeReturnsNullOnSuccess(): void
    {
        $controller = new GuardedController();
        $request    = Request::fromArray('GET', '/', headers: ['X-Token' => 'secret']);

        self::assertNull($controller->before($request));
    }

    public function testOverriddenAfterDecoratesResponse(): void
    {
        $controller = new HeaderDecoratingController();
        $request    = Request::fromArray('GET', '/');
        $response   = Response::text('body');

        $decorated = $controller->after($request, $response);

        self::assertSame('Zephyrus', $decorated->headers['X-Powered-By']);
        self::assertSame('body', $decorated->body);
    }

    public function testBothHooksAppliedTogether(): void
    {
        $controller = new BothHooksController();

        // before() passes → after() adds header.
        $request = Request::fromArray('GET', '/act');
        self::assertNull($controller->before($request));

        $response  = $controller->act();
        $decorated = $controller->after($request, $response);

        self::assertSame('yes', $decorated->headers['X-After']);
        self::assertSame('handled', $decorated->body);
    }

    public function testBothHooksShortCircuitsBeforeHandler(): void
    {
        $controller = new BothHooksController();
        $request    = Request::fromArray('GET', '/act', query: ['block' => '1']);

        $early = $controller->before($request);

        self::assertNotNull($early);
        self::assertSame(403, $early->status);
        self::assertSame('blocked', $early->body);
    }

    // ---- validate() helper --------------------------------------------------

    public function testValidateHelperReturnsEmptyBagOnSuccess(): void
    {
        $controller = new ValidatingController();
        $form = new FormValidator([
            'email' => FieldValidator::withRules(Rules::required(), Rules::email()),
        ]);

        $bag = $controller->runValidate($form, ['email' => 'alice@example.com']);

        self::assertInstanceOf(ErrorBag::class, $bag);
        self::assertFalse($bag->hasErrors());
    }

    public function testValidateHelperThrowsValidationExceptionOnFailure(): void
    {
        $controller = new ValidatingController();
        $form = new FormValidator([
            'email' => FieldValidator::withRules(Rules::required(), Rules::email()),
            'name'  => FieldValidator::withRules(Rules::required()),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Validation failed.');

        $controller->runValidate($form, ['email' => 'not-an-email', 'name' => '']);
    }

    public function testValidateHelperExceptionCarriesErrors(): void
    {
        $controller = new ValidatingController();
        $form = new FormValidator([
            'age' => FieldValidator::withRules(Rules::required(), Rules::integer()),
        ]);

        try {
            $controller->runValidate($form, ['age' => 'abc']);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            self::assertTrue($e->errors()->hasErrorsFor('age'));
        }
    }
}
