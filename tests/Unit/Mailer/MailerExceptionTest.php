<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Mailer;

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

    public function testTransportTextIsNotAPublicProperty(): void
    {
        self::assertFalse((new \ReflectionProperty(MailerException::class, 'transportMessage'))->isPublic());
    }

    public function testTransportTextIsAbsentFromJsonEncoding(): void
    {
        $exception = MailerException::sendFailed('550 jane@example.com mailbox unavailable');

        self::assertStringNotContainsString('jane@example.com', (string) json_encode($exception));
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
            MailerException::attachmentRejected('../x', 'contains a path separator')->failure,
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
