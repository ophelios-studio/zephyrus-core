<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Localization;

use PHPUnit\Framework\Attributes\DataProvider;
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
     * The file name is kept in the message, but the absolute path moves to path(): boot-time messages
     * reach logs and error pages.
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
     * The JsonException message is kept: it names the syntax error, never a path.
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

    public function testInvalidFormatKeepsTheServerPathOutOfTheMessage(): void
    {
        $e = LocalizationException::invalidFormat('/srv/app/locale/en/messages.json');

        self::assertStringContainsString('must decode to an object', $e->getMessage());
        self::assertStringContainsString('messages.json', $e->getMessage());
        self::assertStringNotContainsString('/srv/app/locale/', $e->getMessage());
        self::assertSame('/srv/app/locale/en/messages.json', $e->path());
    }

    /**
     * The message carries the last two path segments: the locale tag tells catalogs apart, and a
     * directory name is not a server path.
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
     * unreadableDirectory() receives a basename, so there is no path to expose.
     */
    public function testUnreadableDirectoryReportsNoPathBecauseItNeverReceivesOne(): void
    {
        $e = LocalizationException::unreadableDirectory('locale');

        self::assertStringContainsString('locale', $e->getMessage());
        self::assertNull($e->path());
    }

    /**
     * @return iterable<string, array{LocalizationException, string}>
     */
    public static function refusalsNamingAValue(): iterable
    {
        yield 'unreadable file' => [
            LocalizationException::unreadableFile("/srv/locale/fr\x1b.json"),
            "Unable to read locale file \"locale/fr\\u001b.json\".",
        ];
        yield 'invalid json' => [
            LocalizationException::invalidJson("/srv/locale/fr\x7f.json"),
            "Invalid JSON in locale file \"locale/fr\\u007f.json\"",
        ];
        yield 'unknown pipe' => [
            LocalizationException::unknownPipe("up\x1b", "Hi {n|up\x1b}", ['upper']),
            "Unknown pipe \"up\\u001b\" in translation key \"Hi {n|up\\u001b}\". Valid pipes: upper. Add a formatter "
            . 'with Formatter::register().',
        ];
        yield 'formatter required' => [
            LocalizationException::formatterRequired('money', "Price\u{202E}"),
            "Pipe \"money\" in translation key \"Price\\u202e\" needs a Formatter: call App::setFormatter() first.",
        ];
        yield 'formatter pipe failed' => [
            LocalizationException::formatterPipeFailed('money', "Price\r\n", new \RuntimeException('x')),
            "Unable to apply pipe \"money\" in translation key \"Price\\r\\n\".",
        ];
        yield 'invalid format' => [
            LocalizationException::invalidFormat("/srv/locale/fr\u{0085}.json"),
            "Locale file \"locale/fr\\u0085.json\" must decode to an object.",
        ];
        yield 'unreadable directory' => [
            LocalizationException::unreadableDirectory("fr\x1b"),
            "Unable to read locale catalog directory \"fr\\u001b\".",
        ];
    }

    #[DataProvider('refusalsNamingAValue')]
    public function testARefusalShowsItsValuesEscaped(LocalizationException $exception, string $message): void
    {
        self::assertSame($message, $exception->getMessage());
    }
}
