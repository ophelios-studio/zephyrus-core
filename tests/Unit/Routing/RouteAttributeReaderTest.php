<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Routing\Attribute\Delete as DeleteAttribute;
use Zephyrus\Routing\Attribute\Get as GetAttribute;
use Zephyrus\Routing\Attribute\Head as HeadAttribute;
use Zephyrus\Routing\Attribute\Middleware as MiddlewareAttribute;
use Zephyrus\Routing\Attribute\MiddlewareGroup as MiddlewareGroupAttribute;
use Zephyrus\Routing\Attribute\Options as OptionsAttribute;
use Zephyrus\Routing\Attribute\Patch as PatchAttribute;
use Zephyrus\Routing\Attribute\Post as PostAttribute;
use Zephyrus\Routing\Attribute\Put as PutAttribute;
use Zephyrus\Routing\Attribute\Root as RootAttribute;
use Zephyrus\Routing\Attribute\Route as RouteAttribute;
use Zephyrus\Routing\Attribute\WithoutMiddleware as WithoutMiddlewareAttribute;
use Zephyrus\Routing\Exception\RouteAttributeException;
use Zephyrus\Routing\Exception\RouteMiddlewareException;
use Zephyrus\Routing\Exception\RouteSignatureException;
use Zephyrus\Routing\RouteAttributeReader;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Security\AuthGuardMiddleware;
use Zephyrus\Security\CsrfMiddleware;
use Zephyrus\Session\SessionMiddleware;

// ---------------------------------------------------------------------------
// Fixture controllers used only in this test file
// ---------------------------------------------------------------------------

class EmptyController
{
    public function noAttribute(): void {}
}

class SimpleController
{
    #[RouteAttribute('/users', 'GET')]
    public function index(): void {}

    #[RouteAttribute('/users/{id}', 'GET', constraints: ['id' => '\d+'], name: 'users.show')]
    public function show(): void {}

    #[RouteAttribute('/users', 'POST', middlewares: ['auth'])]
    public function store(): void {}

    protected function notPublic(): void {}
}

class RepeatableController
{
    #[RouteAttribute('/health', 'GET')]
    #[RouteAttribute('/status', 'GET')]
    public function health(): void {}
}

class ParentAttributedController
{
    #[RouteAttribute('/parent', 'GET', name: 'parent.route')]
    public function parentRoute(): void {}
}

class ChildAttributedController extends ParentAttributedController
{
    #[RouteAttribute('/child', 'GET', name: 'child.route')]
    public function childRoute(): void {}
}

class DuplicateRouteNameController
{
    #[RouteAttribute('/users', 'GET', name: 'users.index')]
    public function index(): void {}

    #[RouteAttribute('/people', 'GET', name: 'users.index')]
    public function people(): void {}
}

class ControlCharacterNameController
{
    #[RouteAttribute('/a', 'GET', name: "dup\r\nFAKE\e")]
    public function first(): void {}

    #[RouteAttribute('/a', 'GET', name: "dup\r\nFAKE\e")]
    public function second(): void {}
}

class VerbAttributesController
{
    #[GetAttribute('/articles', name: 'articles.index')]
    public function index(): void {}

    #[HeadAttribute('/articles')]
    public function indexHead(): void {}

    #[OptionsAttribute('/articles')]
    public function options(): void {}

    #[PostAttribute('/articles', middlewares: ['auth'])]
    public function store(): void {}

    #[PutAttribute('/articles/{id}', constraints: ['id' => '\\d+'])]
    public function replace(): void {}

    #[PatchAttribute('/articles/{id}')]
    public function update(): void {}

    #[DeleteAttribute('/articles/{id}')]
    public function destroy(): void {}
}

#[MiddlewareAttribute('api')]
#[MiddlewareAttribute('auth')]
class MiddlewareAttributedController
{
    #[GetAttribute('/feed')]
    public function feed(): void {}

    #[MiddlewareAttribute('rate-limit')]
    #[PostAttribute('/feed')]
    public function publish(): void {}

    #[PostAttribute('/feed/admin', middlewares: ['admin'])]
    public function publishAsAdmin(): void {}
}

#[MiddlewareGroupAttribute('web')]
class MiddlewareGroupAttributedController
{
    #[GetAttribute('/profile')]
    public function profile(): void {}

    #[MiddlewareGroupAttribute('audit-group')]
    #[PostAttribute('/profile', middlewares: ['auth'])]
    public function updateProfile(): void {}
}

#[WithoutMiddlewareAttribute(SessionMiddleware::class)]
class ExclusionParentController
{
}

#[WithoutMiddlewareAttribute(AuthGuardMiddleware::class)]
class ExclusionChildController extends ExclusionParentController
{
    #[GetAttribute('/landing')]
    public function landing(): void {}

    #[WithoutMiddlewareAttribute(ContentFilterTestMiddleware::class)]
    #[WithoutMiddlewareAttribute(SessionMiddleware::class)]
    #[GetAttribute('/feed')]
    public function feed(): void {}
}

class SecurityExclusionController
{
    #[WithoutMiddlewareAttribute(CsrfMiddleware::class)]
    #[PostAttribute('/webhook')]
    public function receive(): void {}
}

final class ContentFilterTestMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        return $next($request);
    }
}

// Root attribute fixtures
#[RootAttribute('/admin')]
class RootedController
{
    #[GetAttribute('/users')]
    public function index(): void {}

    #[PostAttribute('/users')]
    public function store(): void {}
}

#[RootAttribute('/admin')]
class RootParentController
{
    #[GetAttribute('/base')]
    public function base(): void {}
}

#[RootAttribute('/users')]
class RootChildController extends RootParentController
{
    #[GetAttribute('/add')]
    public function add(): void {}
}

class RootInheritedOnlyChildController extends RootParentController
{
    #[GetAttribute('/detail')]
    public function detail(): void {}
}

#[RootAttribute('/admin')]
class RootGrandparentController
{
    #[GetAttribute('/home')]
    public function home(): void {}
}

#[RootAttribute('/panel')]
class RootMidController extends RootGrandparentController
{
    #[GetAttribute('/dashboard')]
    public function dashboard(): void {}
}

class RootDeepChildController extends RootMidController
{
    #[GetAttribute('/settings')]
    public function settings(): void {}
}

#[RootAttribute('/')]
class RootSlashOnlyController
{
    #[GetAttribute('/ping')]
    public function ping(): void {}
}

#[RootAttribute('/trailing/')]
class RootTrailingSlashController
{
    #[GetAttribute('/test')]
    public function test(): void {}
}

class InvalidMethodController
{
    #[RouteAttribute('/x', 'GE T')]
    public function index(): void {}
}

// ---------------------------------------------------------------------------

final class RouteAttributeReaderTest extends TestCase
{
    private RouteAttributeReader $reader;

    protected function setUp(): void
    {
        $this->reader = new RouteAttributeReader();
    }

    public function testEmptyControllerYieldsNoRoutes(): void
    {
        $routes = $this->reader->read(EmptyController::class);

        self::assertSame([], $routes);
    }

    public function testSimpleControllerYieldsThreeRoutes(): void
    {
        $routes = $this->reader->read(SimpleController::class);

        self::assertCount(3, $routes);
    }

    public function testHandlerStringIsClassAtMethod(): void
    {
        $routes = $this->reader->read(SimpleController::class);

        $handlers = array_map(fn ($r) => $r->handler, $routes);

        self::assertContains(SimpleController::class . '@index', $handlers);
        self::assertContains(SimpleController::class . '@show', $handlers);
        self::assertContains(SimpleController::class . '@store', $handlers);
    }

    public function testPathAndMethodAreNormalized(): void
    {
        $routes = $this->reader->read(SimpleController::class);

        $index = $this->findByHandler($routes, SimpleController::class . '@index');

        self::assertNotNull($index);
        self::assertSame('/users', $index->path);
        self::assertSame('GET', $index->method);
    }

    public function testConstraintsArePreserved(): void
    {
        $routes = $this->reader->read(SimpleController::class);

        $show = $this->findByHandler($routes, SimpleController::class . '@show');

        self::assertNotNull($show);
        self::assertSame(['id' => '\d+'], $show->constraints);
    }

    public function testNameIsPreserved(): void
    {
        $routes = $this->reader->read(SimpleController::class);

        $show = $this->findByHandler($routes, SimpleController::class . '@show');

        self::assertNotNull($show);
        self::assertSame('users.show', $show->name);
    }

    public function testMiddlewaresArePreserved(): void
    {
        $routes = $this->reader->read(SimpleController::class);

        $store = $this->findByHandler($routes, SimpleController::class . '@store');

        self::assertNotNull($store);
        self::assertSame(['auth'], $store->middlewares);
    }

    public function testUnnamedAttributeHasNullName(): void
    {
        $routes = $this->reader->read(SimpleController::class);

        $index = $this->findByHandler($routes, SimpleController::class . '@index');

        self::assertNotNull($index);
        self::assertNull($index->name);
    }

    public function testRepeatableAttributeYieldsOneRoutePerDeclaration(): void
    {
        $routes = $this->reader->read(RepeatableController::class);

        self::assertCount(2, $routes);

        $paths = array_map(fn ($r) => $r->path, $routes);

        self::assertContains('/health', $paths);
        self::assertContains('/status', $paths);
    }

    public function testProtectedMethodsAreIgnored(): void
    {
        $routes = $this->reader->read(SimpleController::class);

        $handlers = array_map(fn ($r) => $r->handler, $routes);

        self::assertNotContains(SimpleController::class . '@notPublic', $handlers);
    }

    public function testOnlyMethodsDeclaredOnGivenClassAreRead(): void
    {
        $routes = $this->reader->read(ChildAttributedController::class);

        self::assertCount(1, $routes);
        self::assertSame('/child', $routes[0]->path);
        self::assertSame(ChildAttributedController::class . '@childRoute', $routes[0]->handler);
    }

    public function testDuplicateRouteNamesThrowException(): void
    {
        $this->expectException(RouteAttributeException::class);
        $this->expectExceptionMessage('Duplicate route name "users.index" discovered while reading attributes on class');

        $this->reader->read(DuplicateRouteNameController::class);
    }

    public function testDuplicateRouteNameRefusalEscapesControlCharactersOfTheName(): void
    {
        try {
            $this->reader->read(ControlCharacterNameController::class);
            self::fail('expected a refusal');
        } catch (RouteAttributeException $e) {
            self::assertSame(
                'Duplicate route name "dup\r\nFAKE\u001b" discovered while reading attributes on class "'
                . ControlCharacterNameController::class . '".',
                $e->getMessage(),
            );
        }
    }

    public function testDuplicateRouteNameRefusalEscapesControlCharactersOfTheClassName(): void
    {
        $refusal = RouteAttributeException::duplicateRouteName("No\r\nCtrl", "dup\r\nFAKE\e");

        self::assertSame(
            'Duplicate route name "dup\r\nFAKE\u001b" discovered while reading attributes on class "No\r\nCtrl".',
            $refusal->getMessage(),
        );
    }

    public function testUnresolvableClassRefusalEscapesControlCharactersOfTheClassNameAndThePreviousMessage(): void
    {
        $refusal = RouteAttributeException::unresolvableClass("No\r\nCtrl", new \ReflectionException("bad\r\nFAKE"));

        self::assertSame(
            'Cannot read route attributes from class "No\r\nCtrl": bad\r\nFAKE',
            $refusal->getMessage(),
        );
    }

    public function testUnreadableRequirementRefusalEscapesControlCharactersOfTheClassNameAndThePreviousMessage(): void
    {
        $refusal = RouteAttributeException::unreadableRequirement(
            "No\r\nCtrl\u{202E}",
            new \InvalidArgumentException("bad\r\nFAKE\u{202E}"),
        );

        self::assertSame(
            'Cannot read the #[RequiresEnv] of class "No\r\nCtrl\u202e": bad\r\nFAKE\u202e',
            $refusal->getMessage(),
        );
    }

    public function testCoexistingRouteNamesRefusalEscapesControlCharactersOfTheNameAndBothHandlers(): void
    {
        $refusal = RouteAttributeException::coexistingRouteNames("dup\r\nFAKE", "First\r\n@one", "Second\r\n@two");

        self::assertSame(
            'Route name "dup\r\nFAKE" is declared by First\r\n@one and by Second\r\n@two, which can be registered '
            . 'together: rename one, or require one environment variable at a different value on each with '
            . '#[RequiresEnv].',
            $refusal->getMessage(),
        );
    }

    public function testVerbAttributesMapToExpectedHttpMethods(): void
    {
        $routes = $this->reader->read(VerbAttributesController::class);

        self::assertCount(7, $routes);

        self::assertSame('GET', $this->findByHandler($routes, VerbAttributesController::class . '@index')?->method);
        self::assertSame('HEAD', $this->findByHandler($routes, VerbAttributesController::class . '@indexHead')?->method);
        self::assertSame('OPTIONS', $this->findByHandler($routes, VerbAttributesController::class . '@options')?->method);
        self::assertSame('POST', $this->findByHandler($routes, VerbAttributesController::class . '@store')?->method);
        self::assertSame('PUT', $this->findByHandler($routes, VerbAttributesController::class . '@replace')?->method);
        self::assertSame('PATCH', $this->findByHandler($routes, VerbAttributesController::class . '@update')?->method);
        self::assertSame('DELETE', $this->findByHandler($routes, VerbAttributesController::class . '@destroy')?->method);
    }

    public function testVerbAttributesPreserveRouteMetadata(): void
    {
        $routes = $this->reader->read(VerbAttributesController::class);

        $index = $this->findByHandler($routes, VerbAttributesController::class . '@index');
        $store = $this->findByHandler($routes, VerbAttributesController::class . '@store');
        $replace = $this->findByHandler($routes, VerbAttributesController::class . '@replace');

        self::assertNotNull($index);
        self::assertSame('articles.index', $index->name);

        self::assertNotNull($store);
        self::assertSame(['auth'], $store->middlewares);

        self::assertNotNull($replace);
        self::assertSame(['id' => '\\d+'], $replace->constraints);
    }

    public function testClassMiddlewareAttributesApplyToAllRoutes(): void
    {
        $routes = $this->reader->read(MiddlewareAttributedController::class);

        self::assertSame(
            ['api', 'auth'],
            $this->findByHandler($routes, MiddlewareAttributedController::class . '@feed')?->middlewares,
        );
    }

    public function testMethodMiddlewareAttributesApplyAfterClassMiddlewares(): void
    {
        $routes = $this->reader->read(MiddlewareAttributedController::class);

        self::assertSame(
            ['api', 'auth', 'rate-limit'],
            $this->findByHandler($routes, MiddlewareAttributedController::class . '@publish')?->middlewares,
        );
    }

    public function testRouteMiddlewaresApplyAfterClassAndMethodMiddlewares(): void
    {
        $routes = $this->reader->read(MiddlewareAttributedController::class);

        self::assertSame(
            ['api', 'auth', 'admin'],
            $this->findByHandler($routes, MiddlewareAttributedController::class . '@publishAsAdmin')?->middlewares,
        );
    }

    public function testClassMiddlewareGroupAttributesApplyToAllRoutes(): void
    {
        $routes = $this->reader->read(MiddlewareGroupAttributedController::class);

        self::assertSame(
            ['web'],
            $this->findByHandler($routes, MiddlewareGroupAttributedController::class . '@profile')?->middlewares,
        );
    }

    public function testMethodMiddlewareGroupAttributesApplyAfterClassMiddlewares(): void
    {
        $routes = $this->reader->read(MiddlewareGroupAttributedController::class);

        self::assertSame(
            ['web', 'audit-group', 'auth'],
            $this->findByHandler($routes, MiddlewareGroupAttributedController::class . '@updateProfile')?->middlewares,
        );
    }

    public function testAnAttributeMethodThatIsNotATokenIsRefused(): void
    {
        try {
            $this->reader->read(InvalidMethodController::class);
            self::fail('A method that is not a token must be refused.');
        } catch (RouteSignatureException $exception) {
            self::assertSame(
                'Invalid route method "GE T" on route "/x": an HTTP method is a token of letters, digits and '
                . '!#$%&\'*+-.^_`|~ (RFC 9110), such as GET or PROPFIND',
                $exception->getMessage(),
            );
        }
    }

    public function testUnresolvableClassThrowsException(): void
    {
        $this->expectException(RouteAttributeException::class);

        /** @var class-string $nonExistent */
        $nonExistent = 'DoesNotExist\\Controller';
        $this->reader->read($nonExistent);
    }

    // Root attribute tests ---------------------------------------------------

    public function testRootPrefixPrependedToAllRoutes(): void
    {
        $routes = $this->reader->read(RootedController::class);

        self::assertCount(2, $routes);
        $paths = array_map(fn ($r) => $r->path, $routes);
        self::assertContains('/admin/users', $paths);
    }

    public function testRootPrefixOnChildCombinesWithParentPrefix(): void
    {
        $routes = $this->reader->read(RootChildController::class);

        self::assertCount(1, $routes);
        self::assertSame('/admin/users/add', $routes[0]->path);
    }

    public function testChildInheritsParentRootPrefixWithoutOwnRoot(): void
    {
        $routes = $this->reader->read(RootInheritedOnlyChildController::class);

        self::assertCount(1, $routes);
        self::assertSame('/admin/detail', $routes[0]->path);
    }

    public function testMultiLevelInheritanceCombinesPrefixes(): void
    {
        $routes = $this->reader->read(RootDeepChildController::class);

        self::assertCount(1, $routes);
        self::assertSame('/admin/panel/settings', $routes[0]->path);
    }

    public function testNoRootAttributeLeavesPathUnchanged(): void
    {
        $routes = $this->reader->read(SimpleController::class);

        $index = $this->findByHandler($routes, SimpleController::class . '@index');
        self::assertNotNull($index);
        self::assertSame('/users', $index->path);
    }

    public function testRootSlashOnlyTreatedAsEmpty(): void
    {
        $routes = $this->reader->read(RootSlashOnlyController::class);

        self::assertCount(1, $routes);
        self::assertSame('/ping', $routes[0]->path);
    }

    public function testRootTrailingSlashTrimmed(): void
    {
        $routes = $this->reader->read(RootTrailingSlashController::class);

        self::assertCount(1, $routes);
        self::assertSame('/trailing/test', $routes[0]->path);
    }

    public function testClassLevelExclusionsAreInheritedParentFirst(): void
    {
        $routes = (new RouteAttributeReader())->read(ExclusionChildController::class);
        $landing = $this->findByHandler($routes, ExclusionChildController::class . '@landing');

        self::assertNotNull($landing);
        self::assertSame([SessionMiddleware::class, AuthGuardMiddleware::class], $landing->excludedMiddlewares);
    }

    public function testMethodLevelExclusionsAreMergedAfterTheClassLevelOnes(): void
    {
        $routes = (new RouteAttributeReader())->read(ExclusionChildController::class);
        $feed = $this->findByHandler($routes, ExclusionChildController::class . '@feed');

        self::assertNotNull($feed);
        self::assertSame(
            [SessionMiddleware::class, AuthGuardMiddleware::class, ContentFilterTestMiddleware::class],
            $feed->excludedMiddlewares,
        );
    }

    public function testRouteWithoutExclusionAttributeExcludesNothing(): void
    {
        $routes = (new RouteAttributeReader())->read(SimpleController::class);

        self::assertSame([], $routes[0]->excludedMiddlewares);
    }

    public function testExcludingASecurityMiddlewareIsRefusedWhenAttributesAreRead(): void
    {
        $this->expectException(RouteMiddlewareException::class);
        $this->expectExceptionMessage('security.csrf.exceptions');

        (new RouteAttributeReader())->read(SecurityExclusionController::class);
    }

    // -----------------------------------------------------------------------

    /**
     * @param list<\Zephyrus\Routing\Route> $routes
     */
    private function findByHandler(array $routes, string $handler): ?\Zephyrus\Routing\Route
    {
        foreach ($routes as $route) {
            if ($route->handler === $handler) {
                return $route;
            }
        }

        return null;
    }
}
