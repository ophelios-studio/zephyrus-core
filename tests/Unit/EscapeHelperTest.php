<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The framework offers `engine: php` as a first-class RenderConfig option, and
 * PhpEngine::capture() is raw extract() plus include: nothing between a template
 * variable and the response body. Until this helper existed there was no
 * escaping function anywhere in functions.php, so the documented way to render a
 * Flash message on that engine was `<?= $message ?>`, which is stored XSS.
 */
final class EscapeHelperTest extends TestCase
{
    public function testHelperIsDeclared(): void
    {
        self::assertTrue(function_exists('e'), 'functions.php must declare the escaping helper.');
    }

    public function testEscapesTheFiveHtmlSignificantCharacters(): void
    {
        self::assertSame(
            '&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;',
            e("<script>alert('x')</script>"),
        );
        self::assertSame('&amp;', e('&'));
        self::assertSame('&quot;', e('"'));
    }

    /**
     * ENT_QUOTES, not the default. The default leaves the single quote alone,
     * which is exactly the character that breaks out of a single-quoted
     * attribute: <a title='<?= e($v) ?>'>.
     */
    public function testEscapesSingleQuotesSoASingleQuotedAttributeCannotBeBrokenOut(): void
    {
        self::assertSame('&#039; onmouseover=&#039;alert(1)', e("' onmouseover='alert(1)"));
    }

    /**
     * ENT_SUBSTITUTE, not the default. Without it htmlspecialchars() returns an
     * EMPTY STRING for invalid UTF-8, which is the silent-failure mode this
     * whole review has been killing: the value simply vanishes from the page
     * with nothing logged and nothing thrown.
     */
    public function testInvalidUtf8SubstitutesTheReplacementCharacterInsteadOfVanishing(): void
    {
        $invalid = "valid\xB1\x31invalid";

        self::assertNotSame('', e($invalid));
        self::assertStringContainsString("\u{FFFD}", e($invalid));
        self::assertStringStartsWith('valid', e($invalid));
    }

    public function testDoesNotDoubleEscapeBecauseItIsNotItsJob(): void
    {
        self::assertSame('&amp;amp;', e('&amp;'));
    }

    /**
     * NULL is accepted deliberately. `e($row->middleName)` on a nullable column
     * is the single most common expression a template author writes, and a
     * strict `string` parameter would make it a TypeError at render time. The
     * author's fix would not be `e($x ?? '')`, it would be deleting the `e()`,
     * so a helper that rejects null is a helper that gets removed.
     */
    public function testAcceptsNullAndReturnsAnEmptyString(): void
    {
        self::assertSame('', e(null));
    }

    /**
     * Stringable is accepted for the same reason: the framework's own value
     * objects are echoed in templates, and a helper less capable than a raw
     * `<?= ?>` gets skipped.
     */
    public function testAcceptsStringable(): void
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return '<b>value</b>';
            }
        };

        self::assertSame('&lt;b&gt;value&lt;/b&gt;', e($stringable));
    }

    public function testAcceptsScalarsWithPhpEchoSemantics(): void
    {
        self::assertSame('42', e(42));
        self::assertSame('1.5', e(1.5));
        self::assertSame('1', e(true));
        self::assertSame('', e(false));
    }

    /**
     * An array or a plain object is REFUSED at the signature. (string) []
     * produces the literal 'Array' plus a warning, and (string) $plainObject is
     * a fatal, so neither is a value a template ever meant to print. Refusing
     * statically is what ConfigSection's typed getters already do at runtime.
     */
    public function testRefusesAnArray(): void
    {
        $this->expectException(\TypeError::class);
        /** @phpstan-ignore-next-line argument.type -- the refusal is the assertion. */
        e(['a']);
    }
}
