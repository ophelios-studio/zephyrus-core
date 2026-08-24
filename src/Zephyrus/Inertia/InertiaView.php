<?php

declare(strict_types=1);

namespace Zephyrus\Inertia;

final class InertiaView
{
    /** @var array<string, mixed>|null */
    private static ?array $page = null;

    private function __construct() {}

    /**
     * @param array<string, mixed> $page
     */
    public static function setPage(array $page): void
    {
        self::$page = $page;
    }

    public static function clearPage(): void
    {
        self::$page = null;
    }

    /**
     * @param array<string, mixed>|null $page
     */
    public static function app(?array $page = null, string $id = 'app'): string
    {
        $page ??= self::$page;

        if ($page === null) {
            return '<div id="' . self::escape($id) . '"></div>';
        }

        $json = json_encode(
            $page,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return sprintf(
            '<div id="%s" data-page="%s"></div>',
            self::escape($id),
            self::escape($json),
        );
    }

    /**
     * @param array<string, mixed>|null $page
     */
    public static function head(?array $page = null): string
    {
        $page ??= self::$page;

        if ($page === null) {
            return '';
        }

        $props = is_array($page['props'] ?? null) ? $page['props'] : [];
        $title = is_string($props['title'] ?? null) ? $props['title'] : null;

        if ($title === null || $title === '') {
            return '';
        }

        return '<title>' . self::escape($title) . '</title>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
