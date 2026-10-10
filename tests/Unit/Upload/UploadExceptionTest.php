<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Upload;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Upload\UploadException;

final class UploadExceptionTest extends TestCase
{
    /**
     * @return iterable<string, array{UploadException, string}>
     */
    public static function refusalsNamingAValue(): iterable
    {
        yield 'path segment' => [
            UploadException::pathTraversalDetected("..\x1b[2K"),
            "Path traversal attempt detected in upload path segment: \"..\\u001b[2K\".",
        ];
        yield 'target name' => [
            UploadException::invalidTargetName("a\u{202E}gpj.php"),
            "Invalid upload target filename: \"a\\u202egpj.php\".",
        ];
        yield 'directory' => [
            UploadException::directoryCreationFailed("/srv/uploads/a\nb"),
            "Unable to create upload directory: \"/srv/uploads/a\\nb\"",
        ];
        yield 'move' => [
            UploadException::moveFileFailed("/tmp/php\x7f", "/srv/uploads/a\u{0085}"),
            "Unable to move uploaded file from \"/tmp/php\\u007f\" to \"/srv/uploads/a\\u0085\".",
        ];
        yield 'not an upload' => [
            UploadException::notAnUploadedFile("/tmp/x\r"),
            "Refusing to move \"/tmp/x\\r\": it is not a genuine PHP upload. Inject a \$fileMover when storing files "
            . 'that did not arrive over HTTP.',
        ];
        yield 'unreadable source' => [
            UploadException::unreadableSource("/tmp/x\t"),
            "Upload temporary file \"/tmp/x\\t\" is missing or unreadable.",
        ];
        yield 'mime sniff' => [
            UploadException::mimeTypeSniffFailed("/tmp/x\x1b"),
            "Unable to determine the real MIME type of \"/tmp/x\\u001b\".",
        ];
        yield 'existing destination' => [
            UploadException::destinationAlreadyExists("/srv/uploads/a\x1b.png"),
            "Refusing to overwrite the existing file \"/srv/uploads/a\\u001b.png\". Pass \$overwriteExisting to allow "
            . 'replacement.',
        ];
        yield 'destination outside the root' => [
            UploadException::destinationNotContained("/srv/a\x7f"),
            "Upload destination \"/srv/a\\u007f\" resolves outside the destination root.",
        ];
        yield 'extension' => [
            UploadException::extensionNotAllowed("p\x1bhp", ['pdf', 'png']),
            "Upload extension \"p\\u001bhp\" is not allowed. Allowed extensions: pdf, png",
        ];
        yield 'mime type' => [
            UploadException::mimeTypeNotAllowed("text/x\x1b", ['image/png']),
            "Upload MIME type \"text/x\\u001b\" is not allowed. Allowed MIME types: image/png",
        ];
    }

    #[DataProvider('refusalsNamingAValue')]
    public function testARefusalShowsItsValueEscaped(UploadException $exception, string $message): void
    {
        self::assertSame($message, $exception->getMessage());
    }

    public function testALongPathKeepsItsEnd(): void
    {
        $path = '/srv/' . str_repeat('a', 100) . '/evil.php';

        self::assertSame(
            'Upload destination "...' . substr($path, -64) . '" (' . strlen($path) . ' bytes) resolves outside the '
            . 'destination root.',
            UploadException::destinationNotContained($path)->getMessage(),
        );
    }
}
