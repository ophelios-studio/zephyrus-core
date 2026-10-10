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
            'Attachment rejected: path "a\\000b\\r\\nc\\\\" contains a NUL byte.',
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

    public function testAttachmentRejectedNeverSplitsAnEscapeSequence(): void
    {
        self::assertSame(
            'Attachment rejected: path "ab' . str_repeat('\\001', 62) . '..." (72 bytes) contains a NUL byte.',
            MailerException::attachmentRejected('path', 'ab' . str_repeat("\x01", 70), 'contains a NUL byte')->getMessage(),
        );
    }

    public function testConfigurationMissingCarriesConfigurationMissingFailure(): void
    {
        self::assertSame(
            MailerFailure::ConfigurationMissing,
            MailerException::configurationMissing('SMTP host not set')->failure,
        );
    }
}
