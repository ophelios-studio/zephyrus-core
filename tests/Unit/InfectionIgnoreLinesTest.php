<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A green run proves that each ignore points at code of its method, not that it is the mutant its comment names:
 * after moving an entry, read the Infection log.
 */
final class InfectionIgnoreLinesTest extends TestCase
{
    private const int MINIMUM_ENTRIES = 363;

    private const string NO_CLASS = 'the class does not exist';
    private const string NO_METHOD = 'the class has no such method';
    private const string OUTSIDE_METHOD = 'the line is outside the method';
    private const string NO_CODE = 'the line holds no code';

    public function testTheInfectionConfigurationStillDeclaresItsIgnores(): void
    {
        self::assertGreaterThanOrEqual(self::MINIMUM_ENTRIES, count(self::realEntries()));
    }

    public function testEveryIgnoredLineOfTheInfectionConfigurationStillHoldsTheCodeItNames(): void
    {
        $problems = self::problems(self::realEntries());

        self::assertSame(
            [],
            $problems,
            count($problems) . ' ignore(s) in infection.json5 no longer point at code of their method. '
            . 'When code moves, an ignore moves with its mutant: find the escaped mutant of the same mutator '
            . 'on the same code in the Infection log (or run Infection on that file without the entry) and set '
            . 'the new line, or delete the entry if the mutant is gone. Never guess a line.',
        );
    }

    public function testAnEntryNamingAClassThatDoesNotExistIsReported(): void
    {
        $entry = 'Zephyrus\Tests\Unit\NoSuchClass::run::10';

        self::assertSame([$entry => self::NO_CLASS], self::problemsOf($entry));
    }

    public function testAnEntryNamingAMissingMethodIsReported(): void
    {
        $entry = self::fixtureEntry('vanished', 'code');

        self::assertSame([$entry => self::NO_METHOD], self::problemsOf($entry));
    }

    public function testAnEntryWhoseLineLeftTheMethodIsReported(): void
    {
        $entry = self::fixtureEntry('withComments', 'outside');
        $method = new \ReflectionMethod(InfectionIgnoreFixture::class, 'withComments');

        self::assertSame(
            [$entry => self::OUTSIDE_METHOD . ' (lines ' . $method->getStartLine() . '-' . $method->getEndLine() . ')'],
            self::problemsOf($entry),
        );
    }

    public function testAnEntryPointingAtACommentOrADocblockLineIsReported(): void
    {
        $comment = self::fixtureEntry('withComments', 'comment');
        $docblock = self::fixtureEntry('withComments', 'docblock');

        self::assertSame(
            [$comment => self::NO_CODE, $docblock => self::NO_CODE],
            self::problemsOf($comment, $docblock),
        );
    }

    public function testAnEntryOnACodeLineOfTheMethodPasses(): void
    {
        self::assertSame([], self::problemsOf(self::fixtureEntry('withComments', 'code')));
    }

    public function testAMutantAfterAnAnonymousClassIsNamedAfterThatClassMethod(): void
    {
        $after = self::fixtureEntry('nested', 'trailing');
        $before = self::fixtureEntry('nested', 'leading');

        self::assertSame([$before => self::NO_METHOD], self::problemsOf($after, $before));
    }

    /**
     * @return list<array{class-string, string, int}>
     */
    private static function realEntries(): array
    {
        return self::entries((string) file_get_contents(dirname(__DIR__, 2) . '/infection.json5'));
    }

    private static function fixtureEntry(string $method, string $marker): string
    {
        return InfectionIgnoreFixture::class . '::' . $method . '::' . self::lineOf($marker);
    }

    /**
     * @return array<string, string>
     */
    private static function problemsOf(string ...$entries): array
    {
        $config = '"' . implode('", "', str_replace('\\', '\\\\', $entries)) . '"';

        return self::problems(self::entries($config));
    }

    /**
     * @return list<array{class-string, string, int}>
     */
    private static function entries(string $config): array
    {
        preg_match_all('/"([A-Za-z0-9_\\\\]+)::(\w+)::(\d+)"/', $config, $matches, PREG_SET_ORDER);
        $entries = [];
        foreach ($matches as $match) {
            /** @var class-string $class */
            $class = str_replace('\\\\', '\\', $match[1]);
            $entries[] = [$class, $match[2], (int) $match[3]];
        }

        return $entries;
    }

    /**
     * @param list<array{class-string, string, int}> $entries
     * @return array<string, string>
     */
    private static function problems(array $entries): array
    {
        $problems = [];
        foreach ($entries as [$class, $method, $line]) {
            $problem = self::problemOf($class, $method, $line);
            if ($problem !== null) {
                $problems[$class . '::' . $method . '::' . $line] = $problem;
            }
        }

        return $problems;
    }

    /**
     * @param class-string $class
     */
    private static function problemOf(string $class, string $method, int $line): ?string
    {
        if (!class_exists($class) && !enum_exists($class) && !trait_exists($class)) {
            return self::NO_CLASS;
        }

        $reflection = new \ReflectionClass($class);
        $file = (string) $reflection->getFileName();
        $range = self::rangeOf($reflection, $method, $file);

        if ($range !== null && $line >= $range[0] && $line <= $range[1]) {
            return self::codeProblem($file, $line);
        }

        if (self::followsAnonymousClassMethod($reflection, $method, $line, $file)) {
            return self::codeProblem($file, $line);
        }

        return $range === null ? self::NO_METHOD : self::OUTSIDE_METHOD . ' (lines ' . $range[0] . '-' . $range[1] . ')';
    }

    /**
     * @param \ReflectionClass<object> $class
     * @return array{int, int}|null
     */
    private static function rangeOf(\ReflectionClass $class, string $method, string $file): ?array
    {
        if (!$class->hasMethod($method)) {
            return null;
        }

        $reflection = $class->getMethod($method);
        if ($reflection->getFileName() !== $file) {
            return null;
        }

        return [(int) $reflection->getStartLine(), (int) $reflection->getEndLine()];
    }

    /**
     * Infection names a mutant that follows an anonymous class after the method that class declares.
     *
     * @param \ReflectionClass<object> $class
     */
    private static function followsAnonymousClassMethod(
        \ReflectionClass $class,
        string $method,
        int $line,
        string $file,
    ): bool {
        $anonymous = self::anonymousClassMethods((string) file_get_contents($file));

        foreach ($class->getMethods() as $enclosing) {
            if ($enclosing->getFileName() !== $file) {
                continue;
            }
            $start = (int) $enclosing->getStartLine();
            $end = (int) $enclosing->getEndLine();
            if ($line < $start || $line > $end) {
                continue;
            }
            foreach ($anonymous as [$name, $declaredAt]) {
                if ($name === $method && $declaredAt >= $start && $declaredAt < $line) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<array{string, int}> Each method declared in an anonymous class: its name and its line.
     */
    private static function anonymousClassMethods(string $source): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $token): bool => !$token->isIgnorable(),
        ));
        $methods = [];

        foreach ($tokens as $i => $token) {
            if (!$token->is(T_NEW)) {
                continue;
            }
            $next = $i + 1;
            if (isset($tokens[$next]) && $tokens[$next]->is(T_READONLY)) {
                $next++;
            }
            if (!isset($tokens[$next]) || !$tokens[$next]->is(T_CLASS)) {
                continue;
            }

            $depth = 0;
            for ($j = $next; isset($tokens[$j]); $j++) {
                $current = $tokens[$j];
                if ($current->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    $depth++;
                } elseif ($current->is('}')) {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($depth > 0 && $current->is(T_FUNCTION) && isset($tokens[$j + 1]) && $tokens[$j + 1]->is(T_STRING)) {
                    $methods[] = [$tokens[$j + 1]->text, $current->line];
                }
            }
        }

        return $methods;
    }

    private static function codeProblem(string $file, int $line): ?string
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $text = trim($lines[$line - 1] ?? '');

        if (preg_match('/^[{}()\[\];,]*$/', $text) === 1) {
            return self::NO_CODE;
        }

        foreach (['//', '#', '/*', '*'] as $prefix) {
            if (str_starts_with($text, $prefix)) {
                return self::NO_CODE;
            }
        }

        return null;
    }

    private static function lineOf(string $marker): int
    {
        foreach (file(__FILE__, FILE_IGNORE_NEW_LINES) ?: [] as $index => $text) {
            if (str_contains($text, 'marker:' . $marker)) {
                return $index + 1;
            }
        }

        self::fail('no marker');
    }
}

final class InfectionIgnoreFixture
{
    public function withComments(): int
    {
        // marker:comment
        /*
         * marker:docblock
         */
        $value = 1; // marker:code

        return $value;
    }

    public function after(): int
    {
        return 2; // marker:outside
    }

    public function inner(): int
    {
        $anonymous = new class () { // marker:leading
            public function nested(): int
            {
                return 3;
            }
        };

        return $anonymous->nested() + 1; // marker:trailing
    }
}
