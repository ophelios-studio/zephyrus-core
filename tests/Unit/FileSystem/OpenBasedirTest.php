<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\FileSystem;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\FileSystem\FileSystemException;

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
        $this->base = sys_get_temp_dir() . '/zob-' . bin2hex(random_bytes(4));
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
                $refused . 'Failed to create directory for: "{outside}/sub/b.txt" ('
                    . '"mkdir(): open_basedir restriction in effect. '
                    . 'File({outside}/sub) is not within the allowed path(s): ({allowed}:{root})")',
            ],
            'File copy to outside' => [
                "(new $file(\$allowed . '/a.txt'))->copy(\$outside . '/sub/b.txt')",
                $refused . 'Failed to create directory for: "{outside}/sub/b.txt" ('
                    . '"mkdir(): open_basedir restriction in effect. '
                    . 'File({outside}/sub) is not within the allowed path(s): ({allowed}:{root})")',
            ],
            'File move to outside' => [
                "(new $file(\$allowed . '/a.txt'))->move(\$outside . '/sub/b.txt')",
                $refused . 'Failed to create directory for: "{outside}/sub/b.txt" ('
                    . '"mkdir(): open_basedir restriction in effect. '
                    . 'File({outside}/sub) is not within the allowed path(s): ({allowed}:{root})")',
            ],
            'File write outside' => [
                "(new $file(\$outside . '/b.txt'))->write('x')",
                $refused . 'Failed to write to: "{outside}/b.txt" ('
                    . '"tempnam(): open_basedir restriction in effect. '
                    . 'File({outside}) is not within the allowed path(s): ({allowed}:{root})")',
            ],
            'Directory ensure outside' => [
                "$directory::ensure(\$outside . '/sub')",
                $refused . 'Failed to create directory: "{outside}/sub" ('
                    . '"mkdir(): open_basedir restriction in effect. '
                    . 'File({outside}/sub) is not within the allowed path(s): ({allowed}:{root})")',
            ],
            'Directory create outside' => [
                "(new $directory(\$outside . '/sub'))->create()",
                $refused . 'Failed to create directory: "{outside}/sub" ('
                    . '"mkdir(): open_basedir restriction in effect. '
                    . 'File({outside}/sub) is not within the allowed path(s): ({allowed}:{root})")',
            ],
            'File delete outside' => [
                "(new $file(\$outside . '/x'))->delete()",
                $refused . 'Failed to delete: "{outside}/x" ('
                    . '"is_link(): open_basedir restriction in effect. '
                    . 'File({outside}/x) is not within the allowed path(s): ({allowed}:{root})")',
            ],
            'Directory delete outside' => [
                "(new $directory(\$outside))->delete()",
                $refused . 'Failed to delete: "{outside}" ('
                    . '"is_link(): open_basedir restriction in effect. '
                    . 'File({outside}) is not within the allowed path(s): ({allowed}:{root})")',
            ],
            'File delete of an absent path inside' => ["(new $file(\$allowed . '/x'))->delete()", 'NULL'],
            'Directory delete of an absent path inside' => ["(new $directory(\$allowed . '/x'))->delete()", 'NULL'],
            'File delete under a regular file inside' => ["(new $file(\$allowed . '/a.txt/x'))->delete()", 'NULL'],
            'Directory delete under a regular file inside' => [
                "(new $directory(\$allowed . '/a.txt/x'))->delete()",
                'NULL',
            ],
            'File delete of a name holding the restriction phrase' => [
                "(new $file(\$allowed . '/open_basedir restriction in effect. x'))->delete()",
                'NULL',
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
            self::withTheReasonCutAsTheExceptionCutsIt(
                strtr($expected, ['{allowed}' => $this->allowed, '{outside}' => $this->outside, '{root}' => $root]),
            ),
            $output,
        );
    }

    private static function withTheReasonCutAsTheExceptionCutsIt(string $expected): string
    {
        $shape = '/^(' . preg_quote(FileSystemException::class, '/') . '): Failed to (.+?): "(.+?)" \("(.*)"\)$/s';
        if (preg_match($shape, $expected, $parts) !== 1) {
            return $expected;
        }

        return $parts[1] . ': ' . FileSystemException::operationFailed($parts[2], $parts[3], reason: $parts[4])
            ->getMessage();
    }

    public function testARecursiveDeleteMeetingALinkOutsideOpenBasedirThrowsAFileSystemExceptionWithoutAWarning(): void
    {
        mkdir($this->allowed . '/tree');
        symlink($this->outside, $this->allowed . '/tree/link');
        $directory = '\Zephyrus\FileSystem\Directory';

        [$output, $errors] = $this->runChild("(new $directory(\$allowed . '/tree'))->delete(true)");

        @unlink($this->allowed . '/tree/link');
        @rmdir($this->allowed . '/tree');
        self::assertSame('', $errors);
        self::assertSame(
            'Zephyrus\FileSystem\FileSystemException: Failed to delete: "' . $this->allowed . '/tree"',
            $output,
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function absentPathsToDelete(): array
    {
        $file = '\Zephyrus\FileSystem\File';
        $directory = '\Zephyrus\FileSystem\Directory';

        return [
            'a file' => ["(new $file(\$allowed . '/absent'))->delete()"],
            'a directory' => ["(new $directory(\$allowed . '/absent'))->delete()"],
            'a file named after the restriction phrase' => [
                "(new $file(\$allowed . '/open_basedir restriction in effect. x'))->delete()",
            ],
        ];
    }

    #[DataProvider('absentPathsToDelete')]
    public function testDeletingAnAbsentPathWithoutOpenBasedirIsSilentToAnErrorHandlerThatIgnoresTheSilenceOperator(
        string $delete,
    ): void {
        $code = "(function () use (\$allowed): array { set_error_handler(static function (int \$n, string \$m): never {"
            . " throw new \ErrorException(\$m, 0, \$n); }); \$r = $delete; restore_error_handler();"
            . " return [\$r, error_get_last()]; })()";

        [$output, $errors] = $this->runChild($code, restricted: false);

        self::assertSame('', $errors);
        self::assertSame("array (\n  0 => NULL,\n  1 => NULL,\n)", $output);
    }

    public function testAnErrorHandlerThatIgnoresTheSilenceOperatorSeesNothingWhenTheRestrictionIsProbed(): void
    {
        $code = "(function () use (\$allowed): array { set_error_handler(static function (int \$n, string \$m): never {"
            . " throw new \ErrorException(\$m, 0, \$n); });"
            . " \Zephyrus\FileSystem\NativeFileSystem::assertNotRestricted('delete', \$allowed);"
            . " restore_error_handler(); return [error_get_last()]; })()";

        [$output, $errors] = $this->runChild($code);

        self::assertSame('', $errors);
        self::assertSame("array (\n  0 => NULL,\n)", $output);
    }

    public function testADeleteOutsideOpenBasedirIsRefusedWhenPhpWritesItsErrorsAsHtmlWithAManualLink(): void
    {
        $file = '\Zephyrus\FileSystem\File';

        [$output, $errors] = $this->runChild(
            "(new $file(\$outside . '/x'))->delete()",
            ini: ['html_errors' => '1', 'docref_root' => 'http://php.net/'],
        );

        self::assertSame('', $errors);
        self::assertSame(
            self::withTheReasonCutAsTheExceptionCutsIt(
                FileSystemException::class . ': Failed to delete: "' . $this->outside . '/x" ("is_link(): '
                . 'open_basedir restriction in effect. File(' . $this->outside . '/x) is not within the allowed '
                . 'path(s): (' . $this->allowed . ':' . dirname(__DIR__, 3) . ')")',
            ),
            $output,
        );
    }

    /**
     * @param array<string, string> $ini
     * @return list<string>
     */
    private static function iniArguments(array $ini): array
    {
        $arguments = [];
        foreach ($ini as $key => $value) {
            array_push($arguments, '-d', $key . '=' . $value);
        }

        return $arguments;
    }

    /**
     * @param array<string, string> $ini
     * @return array{string, string} the child's standard output and standard error
     */
    private function runChild(string $code, bool $restricted = true, array $ini = []): array
    {
        $root = dirname(__DIR__, 3);
        $process = proc_open(
            [
                PHP_BINARY,
                '-d', 'open_basedir=' . ($restricted ? $this->allowed . ':' . $root : ''),
                ...self::iniArguments($ini),
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
