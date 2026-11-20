<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Cache;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Cache\AtomicFileWriter;
use Zephyrus\Core\Cache\BootCacheException;

/** Needs a process of its own: PHP reads TMPDIR once, so it must be set before anything asks for the temporary directory. */
final class AtomicFileWriterProcessOwnerTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheOwnerOfAProcessThatCannotCreateATemporaryFileIsUndetermined(): void
    {
        putenv('TMPDIR=/proc');

        try {
            AtomicFileWriter::processOwner();
            self::fail('expected a refusal');
        } catch (BootCacheException $e) {
            self::assertSame(
                'Cannot write a boot cache: the owner of this process cannot be determined.',
                $e->getMessage(),
            );
        }
    }
}
