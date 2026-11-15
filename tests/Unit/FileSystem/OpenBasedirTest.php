<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\FileSystem;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenBasedirTest extends TestCase
{
    private const string SCRIPT = <<<'PHP'
        require $argv[1] . '/vendor/autoload.php';
        [$allowed, $outside] = [$argv[2], $argv[3]];
        try {
            echo var_export(eval('return ' . $argv[4] . ';'), true);
        } catch (Throwable $e) {
            echo $e::class . ': ' . $e->getMessage();
        }
        PHP;

    private string $base;
    private string $allowed;
    private string $outside;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/zephyrus-open-basedir-' . bin2hex(random_bytes(8));
        $this->allowed = $this->base . '/allowed';
        $this->outside = $this->base . '/outside';
        mkdir($this->allowed, 0755, true);
        mkdir($this->outside, 0755);
        file_put_contents($this->allowed . '/a.txt', 'content');
    }

    protected function tearDown(): void
    {
        foreach ([$this->allowed . '/a.txt', $this->allowed . '/b.txt', $this->outside . '/b.txt'] as $file) {
            @unlink($file);
        }
        @rmdir($this->allowed);
        @rmdir($this->outside);
        @rmdir($this->base);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function pathChecksOutsideTheAllowedPaths(): array
    {
        $file = '\Zephyrus\FileSystem\File';
        $safePath = '\Zephyrus\FileSystem\SafePath';
        $directory = '\Zephyrus\FileSystem\Directory';
        $refused = 'Zephyrus\FileSystem\FileSystemException: ';

        return [
            'exists' => ["(new $file(\$outside . '/x'))->exists()", 'false'],
            'isReadable' => ["(new $file(\$outside . '/x'))->isReadable()", 'false'],
            'isWritable' => ["(new $file(\$outside . '/x'))->isWritable()", 'false'],
            'permissions' => [
                "(new $file(\$outside . '/x'))->permissions()",
                $refused . 'Path not found: "{outside}/x"',
            ],
            'lastModified' => [
                "(new $file(\$outside . '/x'))->lastModified()",
                $refused . 'Path not found: "{outside}/x"',
            ],
            'a directory exists' => ["(new $directory(\$outside))->exists()", 'false'],
            'SafePath isFile' => ["$safePath::isFile(\$outside . '/x')", 'false'],
            'SafePath isDirectory' => ["$safePath::isDirectory(\$outside)", 'false'],
            'SafePath isReadable' => ["$safePath::isReadable(\$outside)", 'false'],
            'SafePath within a root outside' => ["$safePath::within(\$outside, 'x')", 'NULL'],
            'File create outside' => [
                "$file::create(\$outside . '/sub/b.txt')",
                $refused . 'Failed to create directory for: "{outside}/sub/b.txt"',
            ],
            'File copy to outside' => [
                "(new $file(\$allowed . '/a.txt'))->copy(\$outside . '/sub/b.txt')",
                $refused . 'Failed to create directory for: "{outside}/sub/b.txt"',
            ],
            'File move to outside' => [
                "(new $file(\$allowed . '/a.txt'))->move(\$outside . '/sub/b.txt')",
                $refused . 'Failed to create directory for: "{outside}/sub/b.txt"',
            ],
            'File write outside' => [
                "(new $file(\$outside . '/b.txt'))->write('x')",
                $refused . 'Failed to write to: "{outside}/b.txt" ('
                    . '"tempnam(): open_basedir restriction in effect. '
                    . 'File({outside}) is not within the allowed path(s): ({allowed}:{root})")',
            ],
            'Directory ensure outside' => [
                "$directory::ensure(\$outside . '/sub')",
                $refused . 'Failed to create directory: "{outside}/sub"',
            ],
            'Directory create outside' => [
                "(new $directory(\$outside . '/sub'))->create()",
                $refused . 'Failed to create directory: "{outside}/sub"',
            ],
            'Directory files outside' => [
                "(new $directory(\$outside))->files()",
                $refused . 'Path not found: "{outside}"',
            ],
        ];
    }

    #[DataProvider('pathChecksOutsideTheAllowedPaths')]
    public function testAPathOutsideOpenBasedirIsCheckedWithoutAWarning(string $code, string $expected): void
    {
        $root = dirname(__DIR__, 3);
        [$output, $errors] = $this->runChild($code);

        self::assertSame('', $errors);
        self::assertSame(
            strtr($expected, ['{allowed}' => $this->allowed, '{outside}' => $this->outside, '{root}' => $root]),
            $output,
        );
    }

    /**
     * @return array{string, string} the child's standard output and standard error
     */
    private function runChild(string $code): array
    {
        $root = dirname(__DIR__, 3);
        $process = proc_open(
            [
                PHP_BINARY,
                '-d', 'open_basedir=' . $this->allowed . ':' . $root,
                '-d', 'error_reporting=-1', '-d', 'display_errors=stderr', '-d', 'log_errors=0',
                '-r', self::SCRIPT, '--', $root, $this->allowed, $this->outside, $code,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        proc_close($process);

        return [$output, $errors];
    }
}
