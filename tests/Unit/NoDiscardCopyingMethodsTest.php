<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit;

use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Response;

final class NoDiscardCopyingMethodsTest extends TestCase
{
    private const int MARKED_AT_THE_TIME_OF_WRITING = 54;

    public function testEveryCopyingMethodOfTheFrameworkIsMarkedNoDiscard(): void
    {
        $copying = [];
        $unmarked = [];
        foreach (self::frameworkClasses() as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->getDeclaringClass()->getName() !== $class
                    || preg_match('/^with(out)?(?![a-z])/', $method->getName()) !== 1) {
                    continue;
                }
                $name = $class . '::' . $method->getName();
                $copying[] = $name;
                if (array_filter($method->getAttributes(), static fn ($a) => $a->getName() === 'NoDiscard') === []) {
                    $unmarked[] = $name;
                }
            }
        }

        self::assertGreaterThanOrEqual(self::MARKED_AT_THE_TIME_OF_WRITING, count($copying));
        self::assertSame([], $unmarked);
    }

    /**
     * @return list<class-string>
     */
    private static function frameworkClasses(): array
    {
        $root = dirname(__DIR__, 2) . '/src/Zephyrus';
        $classes = [];
        $files = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        foreach (new \RecursiveIteratorIterator($files) as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = 'Zephyrus\\' . str_replace('/', '\\', $relative);
            if (class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    #[RequiresPhp('>= 8.5')]
    public function testDroppingTheResultOfWithHeaderRaisesAWarning(): void
    {
        $messages = [];
        set_error_handler(static function (int $level, string $message) use (&$messages): bool {
            $messages[] = [$level, $message];

            return true;
        });

        try {
            $response = Response::text('hello');
            $response->withHeader('X-Example', 'value');
        } finally {
            restore_error_handler();
        }

        $expected = 'The return value of method Zephyrus\Http\Response::withHeader()'
            . ' should either be used or intentionally ignored by casting it as (void)';
        self::assertSame([[E_USER_WARNING, $expected]], $messages);
    }
}
