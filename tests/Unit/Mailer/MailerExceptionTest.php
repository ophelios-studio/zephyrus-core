<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use Zephyrus\Exceptions\ZephyrusRuntimeException;
use Zephyrus\Mailer\MailerException;

final class MailerExceptionTest extends TestCase
{
    public function testExtendsZephyrusRuntimeException(): void
    {
        $exception = new MailerException('test');
        self::assertInstanceOf(ZephyrusRuntimeException::class, $exception);
    }

    public function testSendFailedKeepsTransportTextOffTheMessage(): void
    {
        $exception = MailerException::sendFailed('550 jane@example.com mailbox unavailable');

        self::assertSame('The mail transport refused the message.', $exception->getMessage());
        self::assertSame('550 jane@example.com mailbox unavailable', $exception->transportMessage);
    }

    public function testSendFailedWithPrevious(): void
    {
        $previous = new \RuntimeException('socket error');
        $exception = MailerException::sendFailed('connection failed', $previous);
        self::assertSame($previous, $exception->getPrevious());
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
}
