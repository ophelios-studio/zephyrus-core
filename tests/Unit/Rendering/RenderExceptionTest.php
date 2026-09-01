<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Rendering;

use PHPUnit\Framework\TestCase;
use Zephyrus\Exceptions\ZephyrusRuntimeException;
use Zephyrus\Rendering\RenderException;

final class RenderExceptionTest extends TestCase
{
    public function testExtendsZephyrusRuntimeException(): void
    {
        $exception = new RenderException('test');
        self::assertInstanceOf(ZephyrusRuntimeException::class, $exception);
    }

    /**
     * WHAT THIS USED TO PIN, AND WHY IT CHANGED.
     *
     * This test asserted that the RESOLVED ABSOLUTE PATH was present in
     * getMessage(). The message is the part that travels: it lands in a log
     * line, an alert email, a Tracy panel, an APM event, sometimes a 500 page.
     * Putting the deployment's filesystem layout in it disclosed the server
     * path to every one of those readers for nothing, because the only actor
     * who can act on a missing template is a developer who already has the
     * repository.
     *
     * $page STAYS in the message. It is a developer-authored identifier
     * ('users/show'), not a filesystem fact, and it is the whole diagnostic
     * value of the sentence.
     *
     * The resolved path is not discarded, it MOVED: resolvedPath() exposes it
     * as structured context, so a caller that genuinely needs it still asks and
     * gets it. Nothing lost, one disclosure closed. Same shape as
     * LocalizationException::unreadableDirectory(), which already keeps the
     * absolute path out of its sentence.
     */
    public function testTemplateNotFoundKeepsTheServerPathOutOfTheMessage(): void
    {
        $exception = RenderException::templateNotFound('users/show', '/srv/app/views/users/show.latte');

        self::assertStringContainsString('users/show', $exception->getMessage());
        self::assertStringNotContainsString('/srv/app/views/', $exception->getMessage());
        self::assertStringNotContainsString('/srv/app/views/users/show.latte', $exception->getMessage());
    }

    public function testTemplateNotFoundExposesTheResolvedPathAsStructuredContext(): void
    {
        $exception = RenderException::templateNotFound('users/show', '/srv/app/views/users/show.latte');

        self::assertSame('/srv/app/views/users/show.latte', $exception->resolvedPath());
    }

    /**
     * The accessor is null for every other factory, so a caller can tell "this
     * exception carries no path" from "the path was empty".
     */
    public function testResolvedPathIsNullWhenTheExceptionCarriesNoPath(): void
    {
        self::assertNull(RenderException::engineError('Cache directory not writable')->resolvedPath());
        self::assertNull((new RenderException('test'))->resolvedPath());
    }

    public function testRenderFailed(): void
    {
        $previous = new \RuntimeException('Syntax error on line 5');
        $exception = RenderException::renderFailed('page', $previous);

        self::assertStringContainsString('page', $exception->getMessage());
        self::assertStringContainsString('Syntax error on line 5', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testEngineError(): void
    {
        $exception = RenderException::engineError('Cache directory not writable');

        self::assertSame('Cache directory not writable', $exception->getMessage());
    }

    public function testEngineErrorWithPrevious(): void
    {
        $previous = new \RuntimeException('Permission denied');
        $exception = RenderException::engineError('Failed to create cache', $previous);

        self::assertSame('Failed to create cache', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
    }
}
