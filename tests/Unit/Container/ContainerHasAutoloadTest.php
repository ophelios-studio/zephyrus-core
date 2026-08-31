<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Container\Container;

/**
 * Container::has() ended in `class_exists($id)`, which TRIGGERS THE AUTOLOADER.
 *
 * Auto-wiring needs that, so it stays. What did not need to be there is the
 * unfiltered path from an arbitrary string to the autoloader: an id routed here
 * from a request parameter, a header or a path went straight to Composer's
 * resolver, which turns a class name into a filesystem path and includes it,
 * running whatever sits at the top of that file. A shape check costs one
 * preg_match and removes every string that could not have named a class in the
 * first place.
 */
final class ContainerHasAutoloadTest extends TestCase
{
    private Container $container;

    /** @var list<string> */
    private array $probed = [];

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->probed = [];

        spl_autoload_register($this->recordProbe(...), prepend: true);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->recordProbe(...));
    }

    public function recordProbe(string $class): void
    {
        $this->probed[] = $class;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonClassIdentifiers(): array
    {
        return [
            'a traversal attempt' => ['../../etc/passwd'],
            'a dotted service key' => ['app.name'],
            'a path' => ['/var/www/html/index.php'],
            'a url' => ['https://example.test/x'],
            'a doubled separator' => ['Foo\\\\Bar'],
            'a leading digit' => ['9Bad'],
            'an empty string' => [''],
            'a space' => ['Foo Bar'],
            'a NUL byte' => ["Foo\0Bar"],
        ];
    }

    /**
     * Most of these never reached the autoloader even before the guard: PHP's
     * own class lookup drops a name containing '/', '.', a space or a NUL
     * before any spl_autoload handler sees it. The two that DID get through on
     * the previous implementation are the doubled separator and the leading
     * digit, and they are pinned separately below. The whole provider stays
     * because it is the contract, not because every row was exploitable.
     */
    #[DataProvider('nonClassIdentifiers')]
    public function testAnIdentifierThatCannotNameAClassNeverReachesTheAutoloader(string $id): void
    {
        $found = $this->container->has($id);
        $probed = $this->probed;

        self::assertFalse($found);
        self::assertNotContains($id, $probed, 'The autoloader must not be asked about ' . var_export($id, true));
    }

    /**
     * The two strings PHP itself was willing to hand to the autoloader.
     */
    public function testTheTwoIdentifiersThatPreviouslyGotThroughAreStopped(): void
    {
        foreach (['Foo\\\\Bar', '9Bad'] as $id) {
            $found = $this->container->has($id);
            $probed = $this->probed;

            self::assertFalse($found);
            self::assertNotContains($id, $probed, $id . ' must not reach the autoloader');
        }
    }

    public function testAnExplicitBindingIsAnsweredWithoutTouchingTheAutoloader(): void
    {
        $this->container->instance('app.name', 'Zephyrus');

        $found = $this->container->has('app.name');
        // Snapshot before asserting: PHPUnit's own constraint classes autoload
        // on the first assertion and would otherwise show up here.
        $probed = $this->probed;

        self::assertTrue($found);
        self::assertSame([], $probed);
    }

    public function testAnAlreadyLoadedClassIsAnsweredWithoutTouchingTheAutoloader(): void
    {
        $found = $this->container->has(Container::class);
        $probed = $this->probed;

        self::assertTrue($found);
        self::assertSame([], $probed);
    }

    /**
     * The documented behaviour is unchanged for anything shaped like a class:
     * auto-wiring still discovers a class that has not been loaded yet.
     */
    public function testAWellFormedClassNameIsStillResolvedThroughTheAutoloader(): void
    {
        $found = $this->container->has('Zephyrus\\NoSuchClass');
        $probed = $this->probed;

        self::assertFalse($found);
        self::assertContains('Zephyrus\\NoSuchClass', $probed);
    }

    public function testAnAutoWireableClassIsStillDiscovered(): void
    {
        self::assertTrue($this->container->has(\Zephyrus\Event\EventDispatcher::class));
    }
}
