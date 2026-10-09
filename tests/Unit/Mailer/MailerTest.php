<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Mailer;

use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Mailer\Mailer;
use Zephyrus\Mailer\MailerConfig;
use Zephyrus\Mailer\MailerException;
use Zephyrus\Rendering\RenderEngine;

final class MailerTest extends TestCase
{
    private MailerConfig $config;

    protected function setUp(): void
    {
        $this->config = MailerConfig::fromArray([
            'smtp' => [
                'host' => 'localhost',
                'port' => 2525,
                'username' => '',
                'password' => '',
                'encryption' => '',
            ],
            'from' => [
                'address' => 'noreply@example.com',
                'name' => 'Test App',
            ],
        ]);
    }

    public function testToAddsRecipient(): void
    {
        $mailer = new Mailer($this->config);
        $result = $mailer->to('user@example.com', 'User');

        self::assertSame($mailer, $result); // Fluent interface.

        $phpMailer = $mailer->getPhpMailer();
        $addresses = $phpMailer->getToAddresses();
        self::assertCount(1, $addresses);
        self::assertSame('user@example.com', $addresses[0][0]);
        self::assertSame('User', $addresses[0][1]);
    }

    public function testCcAddsCcRecipient(): void
    {
        $mailer = new Mailer($this->config);
        $mailer->cc('cc@example.com', 'CC User');

        $addresses = $mailer->getPhpMailer()->getCcAddresses();
        self::assertCount(1, $addresses);
        self::assertSame('cc@example.com', $addresses[0][0]);
    }

    public function testBccAddsBccRecipient(): void
    {
        $mailer = new Mailer($this->config);
        $mailer->bcc('bcc@example.com');

        $addresses = $mailer->getPhpMailer()->getBccAddresses();
        self::assertCount(1, $addresses);
        self::assertSame('bcc@example.com', $addresses[0][0]);
    }

    public function testReplyToSetsReplyToAddress(): void
    {
        $mailer = new Mailer($this->config);
        $mailer->replyTo('reply@example.com', 'Reply');

        $replyTo = $mailer->getPhpMailer()->getReplyToAddresses();
        self::assertArrayHasKey('reply@example.com', $replyTo);
    }

    public function testSubjectSetsSubject(): void
    {
        $mailer = new Mailer($this->config);
        $mailer->subject('Test Subject');

        self::assertSame('Test Subject', $mailer->getPhpMailer()->Subject);
    }

    public function testHtmlSetsHtmlBody(): void
    {
        $mailer = new Mailer($this->config);
        $mailer->html('<h1>Hello</h1>');

        $phpMailer = $mailer->getPhpMailer();
        self::assertSame('<h1>Hello</h1>', $phpMailer->Body);
        self::assertSame(PHPMailer::CONTENT_TYPE_TEXT_HTML, $phpMailer->ContentType);
    }

    public function testTextSetsPlainTextBody(): void
    {
        $mailer = new Mailer($this->config);
        $mailer->text('Plain text');

        $phpMailer = $mailer->getPhpMailer();
        self::assertSame('Plain text', $phpMailer->Body);
        self::assertSame(PHPMailer::CONTENT_TYPE_PLAINTEXT, $phpMailer->ContentType);
    }

    public function testTextAfterHtmlSetsAltBody(): void
    {
        $mailer = new Mailer($this->config);
        $mailer->html('<h1>HTML</h1>');
        $mailer->text('Fallback text');

        $phpMailer = $mailer->getPhpMailer();
        self::assertSame('<h1>HTML</h1>', $phpMailer->Body);
        self::assertSame('Fallback text', $phpMailer->AltBody);
    }

    public function testTextThenHtmlKeepsTextAsAltBody(): void
    {
        $mailer = new Mailer($this->config);
        $mailer->text('Plain text')->html('<h1>HTML</h1>');

        $phpMailer = $mailer->getPhpMailer();
        self::assertSame('<h1>HTML</h1>', $phpMailer->Body);
        self::assertSame('Plain text', $phpMailer->AltBody);
        self::assertSame(PHPMailer::CONTENT_TYPE_TEXT_HTML, $phpMailer->ContentType);
    }

    public function testHtmlThenTextAndTextThenHtmlComposeTheSameMessage(): void
    {
        $htmlFirst = new Mailer($this->config);
        $htmlFirst->html('<h1>HTML</h1>')->text('Plain text');

        $textFirst = new Mailer($this->config);
        $textFirst->text('Plain text')->html('<h1>HTML</h1>');

        self::assertSame($htmlFirst->getPhpMailer()->Body, $textFirst->getPhpMailer()->Body);
        self::assertSame($htmlFirst->getPhpMailer()->AltBody, $textFirst->getPhpMailer()->AltBody);
        self::assertSame($htmlFirst->getPhpMailer()->ContentType, $textFirst->getPhpMailer()->ContentType);
    }

    public function testHtmlAloneHasNoAltBody(): void
    {
        $mailer = new Mailer($this->config);
        $mailer->html('<h1>HTML</h1>');

        $phpMailer = $mailer->getPhpMailer();
        self::assertSame(PHPMailer::CONTENT_TYPE_TEXT_HTML, $phpMailer->ContentType);
        self::assertSame('', $phpMailer->AltBody);
    }

    public function testTextAloneIsPlainTextWithNoAltBody(): void
    {
        $mailer = new Mailer($this->config);
        $mailer->text('Plain text');

        $phpMailer = $mailer->getPhpMailer();
        self::assertSame(PHPMailer::CONTENT_TYPE_PLAINTEXT, $phpMailer->ContentType);
        self::assertSame('', $phpMailer->AltBody);
    }

    public function testTemplateRendersAndSetsHtmlBody(): void
    {
        $renderEngine = $this->createMock(RenderEngine::class);
        $renderEngine->method('render')
            ->with('emails/welcome', ['name' => 'David'])
            ->willReturn('<h1>Welcome, David!</h1>');

        $mailer = new Mailer($this->config, $renderEngine);
        $mailer->template('emails/welcome', ['name' => 'David']);

        self::assertSame('<h1>Welcome, David!</h1>', $mailer->getPhpMailer()->Body);
    }

    public function testTemplateThrowsWithoutRenderEngine(): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('RenderEngine is required');
        $mailer->template('emails/welcome');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function recipientMethodProvider(): iterable
    {
        yield 'to' => ['to'];
        yield 'cc' => ['cc'];
        yield 'bcc' => ['bcc'];
        yield 'replyTo' => ['replyTo'];
    }

    #[DataProvider('recipientMethodProvider')]
    public function testAnInvalidRecipientIsAbsentFromTheStackTrace(string $method): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            $mailer = new Mailer($this->config);
            $mailer->{$method}('jean.tremblay@@example.test', 'Jean Tremblay');
            self::fail('An invalid address was accepted.');
        } catch (MailerException $e) {
            $trace = $e->getTraceAsString();
            self::assertStringNotContainsString('jean.tremblay', $trace);
            self::assertStringNotContainsString('Jean Tremblay', $trace);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    public function testInvalidRecipientMessageNamesMethodNotAddress(): void
    {
        $mailer = new Mailer($this->config);

        try {
            $mailer->cc('not an address');
            self::fail('An invalid address was accepted.');
        } catch (MailerException $e) {
            self::assertSame('Invalid email address given to cc().', $e->getMessage());
        }
    }

    public function testAttachThrowsForMissingFile(): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('Attachment not found');
        $mailer->attach('/nonexistent/file.pdf');
    }

    public function testAttachAddsAttachment(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zephyrus-mail-');
        file_put_contents($path, 'attachment content');

        try {
            $mailer = new Mailer($this->config);
            $result = $mailer->attach($path, 'document.pdf');

            self::assertSame($mailer, $result);
            $attachments = $mailer->getPhpMailer()->getAttachments();
            self::assertCount(1, $attachments);
        } finally {
            @unlink($path);
        }
    }

    public function testSmtpConfigurationIsApplied(): void
    {
        $config = MailerConfig::fromArray([
            'smtp' => [
                'host' => 'mail.example.com',
                'port' => 465,
                'username' => 'user',
                'password' => 'pass',
                'encryption' => 'ssl',
            ],
            'from' => [
                'address' => 'sender@example.com',
                'name' => 'Sender',
            ],
        ]);

        $mailer = new Mailer($config);
        $phpMailer = $mailer->getPhpMailer();

        self::assertSame('mail.example.com', $phpMailer->Host);
        self::assertSame(465, $phpMailer->Port);
        self::assertTrue($phpMailer->SMTPAuth);
        self::assertSame('user', $phpMailer->Username);
        self::assertSame('pass', $phpMailer->Password);
        self::assertSame('ssl', $phpMailer->SMTPSecure);
    }

    public function testFromAddressIsConfigured(): void
    {
        $mailer = new Mailer($this->config);
        $phpMailer = $mailer->getPhpMailer();

        self::assertSame('noreply@example.com', $phpMailer->From);
        self::assertSame('Test App', $phpMailer->FromName);
    }

    public function testFluentChaining(): void
    {
        $mailer = new Mailer($this->config);

        $result = $mailer
            ->to('user@example.com')
            ->cc('cc@example.com')
            ->subject('Test')
            ->html('<p>Body</p>')
            ->text('Fallback');

        self::assertSame($mailer, $result);
    }

    public function testGetPhpMailerReturnsInstance(): void
    {
        $mailer = new Mailer($this->config);
        self::assertInstanceOf(PHPMailer::class, $mailer->getPhpMailer());
    }

    public function testSmtpAuthDisabledWhenNoCredentials(): void
    {
        $mailer = new Mailer($this->config);
        self::assertFalse($mailer->getPhpMailer()->SMTPAuth);
    }

    public function testAttachContentAttachesInMemoryFileWithNameAndType(): void
    {
        $mailer = new Mailer($this->config);
        $mailer->attachContent('%PDF-1.4 generated', 'report.pdf', 'application/pdf');

        $attachments = $mailer->getPhpMailer()->getAttachments();
        self::assertCount(1, $attachments);
        self::assertSame('%PDF-1.4 generated', $attachments[0][0]);
        self::assertSame('report.pdf', $attachments[0][1]);
        self::assertSame('application/pdf', $attachments[0][4]);
        self::assertTrue($attachments[0][5]);
    }

    public function testAttachContentInfersTypeFromNameWhenNoTypeIsGiven(): void
    {
        $mailer = new Mailer($this->config);
        $mailer->attachContent('%PDF-1.4 generated', 'report.pdf');

        self::assertSame('application/pdf', $mailer->getPhpMailer()->getAttachments()[0][4]);
    }

    #[DataProvider('invalidAttachmentNameProvider')]
    public function testAttachContentRejectsUnsafeName(string $name): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('Attachment rejected');

        $mailer->attachContent('content', $name);
    }

    #[DataProvider('invalidMimeTypeProvider')]
    public function testAttachContentRejectsMalformedMimeType(string $mimeType): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('Attachment rejected');

        $mailer->attachContent('content', 'report.pdf', $mimeType);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAttachmentNameProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'parent traversal' => ['../x'];
        yield 'CRLF header injection' => ["a\r\nb"];
        yield 'bare LF' => ["a\nb"];
        yield 'bare CR' => ["a\rb"];
        yield 'NUL byte' => ["a\0b"];
        yield 'forward slash' => ['a/b'];
        yield 'backslash' => ['a\\b'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidMimeTypeProvider(): iterable
    {
        yield 'no subtype' => ['text'];
        yield 'empty subtype' => ['text/'];
        yield 'empty type' => ['/pdf'];
        yield 'parameters' => ['text/plain; charset=utf-8'];
        yield 'extra segment' => ['application/pdf/extra'];
        yield 'leading space' => [' application/pdf'];
        yield 'CRLF header injection' => ["text/plain\r\nX-Injected: 1"];
    }
}
