<?php

declare(strict_types=1);

namespace Zephyrus\Http;

use Zephyrus\Upload\FileUpload;

use function in_array;
use function ini_get;
use function ini_parse_quantity;
use function is_array;
use function is_scalar;
use function max;
use function preg_match;
use function strcspn;
use function strlen;
use function strtolower;
use function substr;
use function trim;

/**
 * The size of a request body: one measure for Request::fromGlobals(), which truncates a body over its read limit, and
 * MaxBodySizeMiddleware, which refuses one over its budget (its docblock describes the measure). Also the rules
 * Request::fromGlobals() and HttpKernel share on what PHP parses and on an incomplete body. In Http because the
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

    /**
     * PHP's post_max_size in bytes when PHP parses this body itself under a server API that applies the limit, 0
     * otherwise (the CLI, no limit, or a body PHP leaves whole in php://input).
     *
     * @param string $method The method as the server gave it: PHP parses a POST only, case-sensitively.
     */
    public static function postMaxSize(string $method, ?string $contentType): int
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' || !self::phpParses($method, $contentType)) {
            return 0;
        }

        // Silenced: PHP already warned at startup about an invalid value, and reads it the same way.
        return max(0, @ini_parse_quantity((string) ini_get('post_max_size')));
    }

    /**
     * Whether the body ended early: an upload PHP ended without its boundary (UPLOAD_ERR_PARTIAL), or fewer bytes than
     * its Content-Length. A Content-Encoding makes the length count the encoded bytes, so it is not compared.
     *
     * @param string|null $raw Null when PHP parsed the body and kept none to compare (see phpConsumes()).
     * @param array<string, FileUpload|array<int, FileUpload>> $files
     */
    public static function isIncomplete(HeaderBag $headers, ?string $raw, array $files): bool
    {
        if (self::hasPartialUpload($files)) {
            return true;
        }

        $declared = self::declared($headers->get('content-length'));

        return $raw !== null
            && $declared !== null
            && $headers->get('content-encoding', '') === ''
            && strlen($raw) < $declared;
    }

    /**
     * Whether PHP parses this body itself and leaves php://input empty: a multipart POST.
     *
     * @param string $method The method as the server gave it.
     */
    public static function phpConsumes(string $method, ?string $contentType): bool
    {
        return self::phpParses($method, $contentType) && self::mediaType($contentType) === 'multipart/form-data';
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

    /** Whether PHP parses this body into $_POST and $_FILES before the application runs. */
    private static function phpParses(string $method, ?string $contentType): bool
    {
        return $method === 'POST'
            && self::iniFlag((string) ini_get('enable_post_data_reading'))
            && in_array(self::mediaType($contentType), ['application/x-www-form-urlencoded', 'multipart/form-data'], true);
    }

    /** An ini boolean read as PHP reads it: on, yes or true in any case, or a non-zero number. */
    private static function iniFlag(string $value): bool
    {
        return in_array(strtolower($value), ['on', 'yes', 'true'], true) || (int) $value !== 0;
    }

    /** The media type PHP matches its body parsers against: lowercased, cut at the first ";", "," or space. */
    private static function mediaType(?string $contentType): string
    {
        $contentType ??= '';

        return strtolower(substr($contentType, 0, strcspn($contentType, ';, ')));
    }

    /** @param array<string, FileUpload|array<int, FileUpload>> $files */
    private static function hasPartialUpload(array $files): bool
    {
        foreach ($files as $entry) {
            foreach (is_array($entry) ? $entry : [$entry] as $file) {
                if ($file->errorCode === UPLOAD_ERR_PARTIAL) {
                    return true;
                }
            }
        }

        return false;
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
