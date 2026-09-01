<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Localization;

use PHPUnit\Framework\TestCase;
use Zephyrus\Exceptions\ZephyrusRuntimeException;
use Zephyrus\Localization\LocalizationException;

final class LocalizationExceptionTest extends TestCase
{
    public function testExtendsZephyrusRuntimeException(): void
    {
        $e = LocalizationException::unreadableFile('/path');
        self::assertInstanceOf(ZephyrusRuntimeException::class, $e);
    }

    /**
     * WHAT THIS USED TO PIN, AND WHY IT CHANGED.
     *
     * This asserted that the ABSOLUTE SERVER PATH was present in getMessage().
     * Locale loading runs at BOOT, before the kernel's error handling exists,
     * so this message is among the likeliest in the framework to land raw in a
     * log line, an alert email or a bluescreen. It disclosed the deployment's
     * filesystem layout to every one of those readers for nothing.
     *
     * The file NAME stays, because that is the diagnostic. The path moved to
     * path(), the same shape RenderException::templateNotFound() already uses
     * and the same posture unreadableDirectory() already took.
     */
    public function testUnreadableFileKeepsTheServerPathOutOfTheMessage(): void
    {
        $e = LocalizationException::unreadableFile('/srv/app/locale/en/messages.json');

        self::assertStringContainsString('Unable to read', $e->getMessage());
        self::assertStringContainsString('messages.json', $e->getMessage());
        self::assertStringNotContainsString('/srv/app/locale/', $e->getMessage());
        self::assertSame('/srv/app/locale/en/messages.json', $e->path());
    }

    /**
     * Previously asserted '/locales/en.json' inside the message. See
     * testUnreadableFileKeepsTheServerPathOutOfTheMessage for the ruling.
     *
     * The JsonException message IS kept: it says "Syntax error" or "Control
     * character error", never a path, and it is the entire reason a developer
     * reads this line.
     */
    public function testInvalidJsonKeepsTheServerPathOutOfTheMessage(): void
    {
        $previous = new \JsonException('Syntax error');
        $e = LocalizationException::invalidJson('/srv/app/locale/en/messages.json', $previous);

        self::assertStringContainsString('Invalid JSON', $e->getMessage());
        self::assertStringContainsString('messages.json', $e->getMessage());
        self::assertStringContainsString('Syntax error', $e->getMessage());
        self::assertStringNotContainsString('/srv/app/locale/', $e->getMessage());
        self::assertSame($previous, $e->getPrevious());
        self::assertSame('/srv/app/locale/en/messages.json', $e->path());
    }

    public function testInvalidJsonWithoutPrevious(): void
    {
        $e = LocalizationException::invalidJson('/srv/app/locale/en/messages.json');

        self::assertStringContainsString('Invalid JSON', $e->getMessage());
        self::assertStringNotContainsString('/srv/app/locale/', $e->getMessage());
        self::assertNull($e->getPrevious());
        self::assertSame('/srv/app/locale/en/messages.json', $e->path());
    }

    /**
     * Previously asserted '/locales/en.json' inside the message. See
     * testUnreadableFileKeepsTheServerPathOutOfTheMessage for the ruling.
     */
    public function testInvalidFormatKeepsTheServerPathOutOfTheMessage(): void
    {
        $e = LocalizationException::invalidFormat('/srv/app/locale/en/messages.json');

        self::assertStringContainsString('must decode to an object', $e->getMessage());
        self::assertStringContainsString('messages.json', $e->getMessage());
        self::assertStringNotContainsString('/srv/app/locale/', $e->getMessage());
        self::assertSame('/srv/app/locale/en/messages.json', $e->path());
    }

    /**
     * basename() alone was ambiguous: a catalog is nested as
     * locale/<tag>/<file>.json, so fr/legal.json and en/legal.json produced the
     * SAME sentence, and the locale tag is the single most useful
     * disambiguator when a translation file fails to parse.
     *
     * The message therefore carries the last TWO segments. That is not an
     * invented convention, it is the catalog's own structure, and a directory
     * NAME is not a server path: nothing above the catalog is disclosed.
     */
    public function testALocaleFileIsNamedByItsCatalogRelativePathNotJustItsBasename(): void
    {
        $fr = LocalizationException::invalidFormat('/srv/app/locale/fr/legal.json');
        $en = LocalizationException::invalidFormat('/srv/app/locale/en/legal.json');

        self::assertStringContainsString('fr/legal.json', $fr->getMessage());
        self::assertStringContainsString('en/legal.json', $en->getMessage());
        self::assertNotSame($fr->getMessage(), $en->getMessage());
        self::assertStringNotContainsString('/srv/app/locale/', $fr->getMessage());
    }

    /**
     * A path with no parent segment to show must not grow a stray separator.
     */
    public function testALocaleFileWithNoParentSegmentIsNamedByItselfAlone(): void
    {
        self::assertStringContainsString(
            '"en.json"',
            LocalizationException::unreadableFile('/en.json')->getMessage(),
        );
        self::assertStringContainsString(
            '"en.json"',
            LocalizationException::unreadableFile('en.json')->getMessage(),
        );
    }

    /**
     * unreadableDirectory() was already correct: the caller hands it a
     * basename, never a path, so there is no path to expose and the accessor
     * says so rather than returning a misleading ''.
     */
    public function testUnreadableDirectoryReportsNoPathBecauseItNeverReceivesOne(): void
    {
        $e = LocalizationException::unreadableDirectory('locale');

        self::assertStringContainsString('locale', $e->getMessage());
        self::assertNull($e->path());
    }
}
