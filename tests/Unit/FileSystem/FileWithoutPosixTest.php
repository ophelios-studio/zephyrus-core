<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\FileSystem;

use PHPUnit\Framework\TestCase;

final class FileWithoutPosixTest extends TestCase
{
    private const string SCRIPT = <<<'PHP'
        require $argv[1] . '/vendor/autoload.php';
        $path = $argv[2];
        file_put_contents($path, 'old');
        try {
            (new Zephyrus\FileSystem\File($path))->write('new');
            echo file_get_contents($path);
        } catch (Zephyrus\FileSystem\FileSystemException $e) {
            echo $e->getMessage();
        } finally {
            @unlink($path);
        }
        PHP;

    public function testAFileOfTheProcessInTheTemporaryDirectoryIsWrittenWithoutThePosixExtension(): void
    {
        $this->skipUnlessTheTemporaryDirectoryIsStickyAndNotOwnedByUs();

        [$output, $errors] = $this->runChild($this->temporaryPath(), 'posix_geteuid');

        self::assertSame('new', $output, $errors);
    }

    public function testWithoutPosixOrTmpfileAForeignFileInASharedDirectoryIsRefused(): void
    {
        $this->skipUnlessTheTemporaryDirectoryIsStickyAndNotOwnedByUs();
        $path = $this->temporaryPath();

        [$output, $errors] = $this->runChild($path, 'posix_geteuid,tmpfile');

        self::assertSame('Path belongs to another user in a shared directory, so it is not written: ' . $path, $output, $errors);
    }

    private function skipUnlessTheTemporaryDirectoryIsStickyAndNotOwnedByUs(): void
    {
        $temporary = sys_get_temp_dir();
        $stat = stat($temporary);
        if ($stat === false || ($stat['mode'] & 0o1000) === 0 || $stat['uid'] === (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid())) {
            self::markTestSkipped('Needs a sticky temporary directory owned by another user.');
        }
    }

    private function temporaryPath(): string
    {
        return sys_get_temp_dir() . '/zephyrus-no-posix-' . bin2hex(random_bytes(8));
    }

    /**
     * @return array{string, string} the child's standard output and standard error
     */
    private function runChild(string $path, string $disabledFunctions): array
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'disable_functions=' . $disabledFunctions, '-r', self::SCRIPT, '--', dirname(__DIR__, 3), $path],
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
