<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\FileSystem;

use PHPUnit\Framework\TestCase;
use Zephyrus\FileSystem\SafePath;

final class SafePathTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/zsp-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0755);
        file_put_contents($this->root . '/a.txt', 'content');
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/a.txt');
        @rmdir($this->root);
    }

    public function testARootHoldingANulByteResolvesToNull(): void
    {
        self::assertNull(SafePath::within("/tmp\0x", 'a'));
    }

    public function testAFileInsideAValidRootResolvesToItsRealPath(): void
    {
        self::assertSame(realpath($this->root . '/a.txt'), SafePath::within($this->root, 'a.txt'));
    }
}
