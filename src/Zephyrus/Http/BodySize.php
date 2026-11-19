<?php

declare(strict_types=1);

namespace Zephyrus\Http;

use Zephyrus\Upload\FileUpload;

use function is_array;
use function is_scalar;
use function max;
use function preg_match;
use function strlen;
use function trim;

/**
 * The size of a request body: one measure for Request::fromGlobals(), which truncates a body over its read limit, and
 * MaxBodySizeMiddleware, which refuses one over its budget (its docblock describes the measure). In Http because the
 * request is measured before any middleware exists.
 *
 * @internal
 */
final class BodySize
{
    /**
     * @param array<array-key, mixed> $fields The parsed body.
     * @param array<string, FileUpload|array<int, FileUpload>> $files
     */
    public static function measure(?string $contentLength, string $raw, array $fields, array $files): int
    {
        $parsedBytes = self::uploadedBytes($files);

        if ($raw === '') {
            $parsedBytes = self::add($parsedBytes, self::fieldBytes($fields));
        }

        return max(self::declared($contentLength) ?? 0, strlen($raw), $parsedBytes);
    }

    /** A Content-Length of digits only, surrounding whitespace ignored; null otherwise. */
    public static function declared(?string $contentLength): ?int
    {
        if ($contentLength === null) {
            return null;
        }

        $contentLength = trim($contentLength);

        return preg_match('/^\d+$/D', $contentLength) === 1 ? (int) $contentLength : null;
    }

    /** @param array<array-key, mixed> $fields */
    private static function fieldBytes(array $fields): int
    {
        $total = 0;

        foreach ($fields as $key => $value) {
            $total = self::add($total, strlen((string) $key));
            $total = self::add(
                $total,
                is_array($value) ? self::fieldBytes($value) : (is_scalar($value) ? strlen((string) $value) : 0),
            );
        }

        return $total;
    }

    /** @param array<string, FileUpload|array<int, FileUpload>> $files */
    private static function uploadedBytes(array $files): int
    {
        $total = 0;

        foreach ($files as $entry) {
            foreach (is_array($entry) ? $entry : [$entry] as $file) {
                $total = self::add($total, $file->sizeBytes);
            }
        }

        return $total;
    }

    private static function add(int $total, int $bytes): int
    {
        return $bytes > PHP_INT_MAX - $total ? PHP_INT_MAX : $total + $bytes;
    }
}
