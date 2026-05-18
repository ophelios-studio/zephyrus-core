<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Inertia;

use PHPUnit\Framework\TestCase;
use Zephyrus\Inertia\InertiaView;

final class InertiaViewTest extends TestCase
{
    protected function setUp(): void
    {
        InertiaView::clearPage();
    }

    protected function tearDown(): void
    {
        InertiaView::clearPage();
    }

    public function testConstructorIsPrivate(): void
    {
        $reflection = new \ReflectionClass(InertiaView::class);
        $constructor = $reflection->getConstructor();
        $instance = $reflection->newInstanceWithoutConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());

        $constructor->invoke($instance);

        self::assertInstanceOf(InertiaView::class, $instance);
    }

    public function testAppWithoutPageRendersEmptyRootAndEscapesId(): void
    {
        self::assertSame(
            '<div id="app&quot; data-test=&quot;&lt;bad&gt;"></div>',
            InertiaView::app(null, 'app" data-test="<bad>'),
        );
    }

    public function testAppRendersExplicitPagePayload(): void
    {
        $html = InertiaView::app([
            'component' => 'Users/Show',
            'props' => [
                'title' => 'Tom & "Jerry"',
            ],
            'url' => '/users/1?tab=a&b=c',
            'version' => 'build-1',
        ], 'root');

        self::assertStringStartsWith('<div id="root" data-page="', $html);
        self::assertStringContainsString('&quot;component&quot;:&quot;Users/Show&quot;', $html);
        self::assertStringContainsString('&quot;title&quot;:&quot;Tom &amp; \&quot;Jerry\&quot;&quot;', $html);
        self::assertStringContainsString('&quot;url&quot;:&quot;/users/1?tab=a&amp;b=c&quot;', $html);
        self::assertStringEndsWith('"></div>', $html);
    }

    public function testStaticPageIsUsedAndCleared(): void
    {
        InertiaView::setPage([
            'component' => 'Dashboard',
            'props' => [
                'title' => 'Dashboard',
            ],
            'url' => '/dashboard',
            'version' => null,
        ]);

        self::assertStringContainsString('&quot;component&quot;:&quot;Dashboard&quot;', InertiaView::app());
        self::assertSame('<title>Dashboard</title>', InertiaView::head());

        InertiaView::clearPage();

        self::assertSame('<div id="app"></div>', InertiaView::app());
        self::assertSame('', InertiaView::head());
    }

    public function testHeadReturnsEmptyForMissingOrInvalidTitle(): void
    {
        self::assertSame('', InertiaView::head());
        self::assertSame('', InertiaView::head(['props' => 'not-an-array']));
        self::assertSame('', InertiaView::head(['props' => ['title' => 123]]));
        self::assertSame('', InertiaView::head(['props' => ['title' => '']]));
    }

    public function testHeadEscapesTitle(): void
    {
        self::assertSame(
            '<title>Tom &amp; &quot;Jerry&quot;</title>',
            InertiaView::head(['props' => ['title' => 'Tom & "Jerry"']]),
        );
    }
}
