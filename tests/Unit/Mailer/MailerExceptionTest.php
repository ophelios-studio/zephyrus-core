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

    public function testSendFailed(): void
    {
        $exception = MailerException::sendFailed('SMTP timeout');
        self::assertStringContainsString('SMTP timeout', $exception->getMessage());
    }

    public function testSendFailedWithPrevious(): void
    {
        $previous = new \RuntimeException('socket error');
        $exception = MailerException::sendFailed('connection failed', $previous);
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testInvalidAddress(): void
    {
        $exception = MailerException::invalidAddress('not-an-email');
        self::assertStringContainsString('not-an-email', $exception->getMessage());
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
