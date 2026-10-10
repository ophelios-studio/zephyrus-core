<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Mailer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Exceptions\ZephyrusRuntimeException;
use Zephyrus\Mailer\MailerException;
use Zephyrus\Mailer\MailerFailure;

final class MailerExceptionTest extends TestCase
{
    public function testExtendsZephyrusRuntimeException(): void
    {
        $exception = new MailerException('test', MailerFailure::ConfigurationMissing);
        self::assertInstanceOf(ZephyrusRuntimeException::class, $exception);
    }

    public function testSendFailedKeepsTransportTextOffTheMessage(): void
    {
        $exception = MailerException::sendFailed('550 jane@example.com mailbox unavailable');

        self::assertSame(
            'The mail transport did not accept the message; the reason is withheld, '
            . 'read transportMessage() (it may name recipients).',
            $exception->getMessage(),
        );
        self::assertSame('550 jane@example.com mailbox unavailable', $exception->transportMessage());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function transportReasonFactoryProvider(): iterable
    {
        yield 'sendFailed' => ['sendFailed'];
        yield 'recipientsRefused' => ['recipientsRefused'];
    }

    #[DataProvider('transportReasonFactoryProvider')]
    public function testTransportReplyIsAbsentFromTheTrace(string $factory): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            $exception = MailerException::$factory('550 jane.tremblay@example.test mailbox unavailable');
            self::assertStringNotContainsString('jane.tremblay', print_r($exception->getTrace(), true));
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    public function testTransportTextIsNotAPublicProperty(): void
    {
        self::assertFalse((new \ReflectionProperty(MailerException::class, 'transportMessage'))->isPublic());
    }

    public function testTransportTextIsAbsentFromJsonEncoding(): void
    {
        $exception = MailerException::sendFailed('550 jane@example.com mailbox unavailable');

        self::assertStringNotContainsString('jane@example.com', (string) json_encode($exception));
    }

    public function testTransportTextIsAbsentFromEveryDumpAndCast(): void
    {
        $exception = MailerException::recipientsRefused('550 jane.tremblay@example.test mailbox unavailable');
        $outputs = [
            'print_r' => print_r($exception, true),
            'var_export' => var_export($exception, true),
            'array cast' => print_r((array) $exception, true),
            'var_dump' => $this->captureVarDump($exception),
        ];

        foreach ($outputs as $label => $output) {
            self::assertStringNotContainsString('jane.tremblay', $output, $label);
        }
        self::assertSame('550 jane.tremblay@example.test mailbox unavailable', $exception->transportMessage());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedCharacterProvider(): iterable
    {
        yield 'C1 control' => ["\u{0085}", '\u0085'];
        yield 'Arabic letter mark' => ["\u{061C}", '\u061c'];
        yield 'left-to-right mark' => ["\u{200E}", '\u200e'];
        yield 'right-to-left mark' => ["\u{200F}", '\u200f'];
        yield 'line separator' => ["\u{2028}", '\u2028'];
        yield 'paragraph separator' => ["\u{2029}", '\u2029'];
        yield 'right-to-left override' => ["\u{202E}", '\u202e'];
        yield 'left-to-right isolate' => ["\u{2066}", '\u2066'];
        yield 'pop directional isolate' => ["\u{2069}", '\u2069'];
    }

    #[DataProvider('refusedCharacterProvider')]
    public function testARefusedCharacterIsEscapedInTheMessageNotEchoed(string $character, string $escaped): void
    {
        $message = MailerException::attachmentRejected('display name', 'invoice' . $character . 'fdp.exe', 'is refused')
            ->getMessage();

        self::assertStringContainsString('"invoice' . $escaped . 'fdp.exe"', $message);
        self::assertStringNotContainsString($character, $message);
    }

    private function captureVarDump(object $value): string
    {
        ob_start();
        var_dump($value);

        return (string) ob_get_clean();
    }

    public function testSendFailedTakesNoPreviousThrowable(): void
    {
        $parameters = (new \ReflectionMethod(MailerException::class, 'sendFailed'))->getParameters();

        self::assertCount(1, $parameters);
    }

    public function testAPathOfContinuationBytesIsCutWithinThreeBytesOfTheStart(): void
    {
        $message = MailerException::attachmentRejected('path', str_repeat("\x80", 100), 'is refused')->getMessage();

        self::assertSame(
            'Attachment rejected: path "...' . str_repeat("\u{FFFD}", 61) . '" (100 bytes) is refused.',
            $message,
        );
    }

    public function testInvalidAddressNamesTheMethodNotTheAddress(): void
    {
        $exception = MailerException::invalidAddress('cc');

        self::assertSame('Invalid email address given to cc().', $exception->getMessage());
    }

    public function testAttachmentNotFound(): void
    {
        $exception = MailerException::attachmentNotFound('/missing/file.pdf');
        self::assertStringContainsString('/missing/file.pdf', $exception->getMessage());
    }

    public function testConfigurationMissing(): void
    {
        $exception = MailerException::configurationMissing('SMTP host not set');
        self::assertStringContainsString('SMTP host not set', $exception->getMessage());
    }

    public function testInvalidAddressCarriesInvalidAddressFailure(): void
    {
        self::assertSame(MailerFailure::InvalidAddress, MailerException::invalidAddress('to')->failure);
    }

    public function testSendFailedCarriesSendFailedFailure(): void
    {
        self::assertSame(MailerFailure::SendFailed, MailerException::sendFailed('421 try later')->failure);
    }

    public function testAttachmentNotFoundCarriesAttachmentNotFoundFailure(): void
    {
        self::assertSame(
            MailerFailure::AttachmentNotFound,
            MailerException::attachmentNotFound('/missing/file.pdf')->failure,
        );
    }

    public function testAttachmentRejectedCarriesAttachmentRejectedFailure(): void
    {
        self::assertSame(
            MailerFailure::AttachmentRejected,
            MailerException::attachmentRejected('path', '../x', 'contains a path separator')->failure,
        );
    }

    public function testAttachmentRejectedNamesTheRuleInItsMessage(): void
    {
        self::assertSame(
            'Attachment rejected: display name "a/b" contains a path separator; pass a bare file name.',
            MailerException::attachmentRejected('display name', 'a/b', 'contains a path separator; pass a bare file name')->getMessage(),
        );
    }

    public function testAttachmentRejectedShowsControlCharactersEscaped(): void
    {
        self::assertSame(
            'Attachment rejected: path "a\\u0000b\\r\\nc\\\\" contains a NUL byte.',
            MailerException::attachmentRejected('path', "a\0b\r\nc\\", 'contains a NUL byte')->getMessage(),
        );
    }

    public function testAttachmentRejectedTruncatesAnOversizedValueAndStatesItsLength(): void
    {
        $message = MailerException::attachmentRejected('media type', str_repeat('a', 1048576), 'is longer than 255 bytes')->getMessage();

        self::assertLessThan(300, strlen($message));
        self::assertSame(
            'Attachment rejected: media type "' . str_repeat('a', 64) . '..." (1048576 bytes) is longer than 255 bytes.',
            $message,
        );
    }

    public function testAttachmentRejectedKeepsAValueOf64BytesWhole(): void
    {
        $value = str_repeat('a', 64);

        self::assertSame(
            'Attachment rejected: media type "' . $value . '" is longer than 255 bytes.',
            MailerException::attachmentRejected('media type', $value, 'is longer than 255 bytes')->getMessage(),
        );
    }

    public function testAttachmentRejectedCutsAValueOf65BytesAndStatesItsLength(): void
    {
        self::assertSame(
            'Attachment rejected: media type "' . str_repeat('a', 64) . '..." (65 bytes) is longer than 255 bytes.',
            MailerException::attachmentRejected('media type', str_repeat('a', 65), 'is longer than 255 bytes')->getMessage(),
        );
    }

    public function testAttachmentRejectedCutsAMultibyteValueOnACharacterBoundary(): void
    {
        $message = MailerException::attachmentRejected('display name', 'a' . str_repeat('é', 200), 'is longer than 255 bytes')->getMessage();

        self::assertTrue(mb_check_encoding($message, 'UTF-8'));
        self::assertNotFalse(json_encode($message));
    }

    public function testAttachmentNotFoundKeepsTheEndOfALongPath(): void
    {
        $path = str_repeat('a', 138) . '/invoice.pdf';

        self::assertSame(
            'Attachment not found: "...' . str_repeat('a', 52) . '/invoice.pdf" (150 bytes)',
            MailerException::attachmentNotFound($path)->getMessage(),
        );
    }

    public function testAttachmentRejectedKeepsTheEndOfALongPath(): void
    {
        $path = str_repeat('a', 138) . '/invoice.pdf';

        self::assertSame(
            'Attachment rejected: path "...' . str_repeat('a', 52) . '/invoice.pdf" (150 bytes) contains a NUL byte.',
            MailerException::attachmentRejected('path', $path, 'contains a NUL byte')->getMessage(),
        );
    }

    public function testAttachmentRejectedKeepsTheEndOfALongDirectory(): void
    {
        $directory = str_repeat('a', 138) . '/allowed';

        self::assertSame(
            'Attachment rejected: directory "...' . str_repeat('a', 56) . '/allowed" (146 bytes) is not an existing directory.',
            MailerException::attachmentRejected('directory', $directory, 'is not an existing directory')->getMessage(),
        );
    }

    public function testLongPathTailIsCutOnACharacterBoundary(): void
    {
        $path = str_repeat('é', 40) . 'x';

        self::assertSame(
            'Attachment not found: "...' . str_repeat('é', 31) . 'x" (81 bytes)',
            MailerException::attachmentNotFound($path)->getMessage(),
        );
    }

    public function testLongDisplayNameKeepsTheStartNotTheEnd(): void
    {
        $name = 'name-' . str_repeat('a', 70);

        self::assertSame(
            'Attachment rejected: display name "name-' . str_repeat('a', 59) . '..." (75 bytes) is not usable.',
            MailerException::attachmentRejected('display name', $name, 'is not usable')->getMessage(),
        );
    }

    public function testAttachmentRejectedNeverSplitsAnEscapeSequence(): void
    {
        self::assertSame(
            'Attachment rejected: path "...' . str_repeat('\\u0001', 64) . '" (72 bytes) contains a NUL byte.',
            MailerException::attachmentRejected('path', 'ab' . str_repeat("\x01", 70), 'contains a NUL byte')->getMessage(),
        );
    }

    public function testAttachmentRejectedKeepsAnInvalidUtf8ValueEncodable(): void
    {
        $exception = MailerException::attachmentRejected('display name', "\xC3\x28bad name", 'is not valid');

        self::assertNotFalse(json_encode(['message' => $exception->getMessage()], JSON_THROW_ON_ERROR));
        self::assertTrue(mb_check_encoding($exception->getMessage(), 'UTF-8'));
    }

    public function testAttachmentRejectedScrubsAnInvalidUtf8ValueOverSixtyFourBytes(): void
    {
        $value = str_repeat("\xFF", 100);
        $exception = MailerException::attachmentRejected('path', $value, 'is refused');

        self::assertTrue(mb_check_encoding($exception->getMessage(), 'UTF-8'));
        self::assertStringContainsString('(100 bytes)', $exception->getMessage());
    }

    public function testConfigurationMissingCarriesConfigurationMissingFailure(): void
    {
        self::assertSame(
            MailerFailure::ConfigurationMissing,
            MailerException::configurationMissing('SMTP host not set')->failure,
        );
    }

    public function testAttachmentNotFoundKeepsAnInvalidUtf8PathEncodable(): void
    {
        $exception = MailerException::attachmentNotFound("/tmp/\xFFmissing.pdf");

        self::assertNotFalse(json_encode($exception->getMessage()));
    }

    public function testAttachmentNotFoundEscapesControlCharactersInThePath(): void
    {
        $exception = MailerException::attachmentNotFound("/tmp/a\nb\x1B.pdf");

        self::assertStringNotContainsString("\n", $exception->getMessage());
        self::assertStringNotContainsString("\x1B", $exception->getMessage());
        self::assertStringContainsString('\\n', $exception->getMessage());
    }
}
