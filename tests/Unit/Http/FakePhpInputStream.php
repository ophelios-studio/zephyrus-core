<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Http;

/** Stands in for the "php" stream wrapper and serves a large php://input, counting what is read. */
final class FakePhpInputStream
{
    public static int $served = 0;
    public static int $opened = 0;
    public const BODY_SIZE = 10_485_760;

    /** @var resource|null */
    public $context;

    private int $position = 0;

    public static function install(): void
    {
        self::$served = 0;
        self::$opened = 0;
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', self::class);
    }

    public static function restore(): void
    {
        @stream_wrapper_unregister('php');
        stream_wrapper_restore('php');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$opened++;

        return $path === 'php://input';
    }

    public function stream_read(int $count): string|false
    {
        $length = min($count, self::BODY_SIZE - $this->position);
        $this->position += $length;
        self::$served += $length;

        return str_repeat('a', $length);
    }

    public function stream_eof(): bool
    {
        return $this->position >= self::BODY_SIZE;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
    }
}
