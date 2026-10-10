<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Mailer;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Zephyrus\Mailer\Mailer;
use Zephyrus\Mailer\MailerConfig;
use Zephyrus\Mailer\MailerException;
use Zephyrus\Mailer\MailerFailure;
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
            ->with('emails/welcome', ['name' => 'Ada'])
            ->willReturn('<h1>Welcome, Ada!</h1>');

        $mailer = new Mailer($this->config, $renderEngine);
        $mailer->template('emails/welcome', ['name' => 'Ada']);

        self::assertSame('<h1>Welcome, Ada!</h1>', $mailer->getPhpMailer()->Body);
    }

    public function testTemplateAfterTextKeepsTheTextPart(): void
    {
        $renderEngine = $this->createMock(RenderEngine::class);
        $renderEngine->method('render')->willReturn('<h1>Welcome</h1>');

        $mailer = new Mailer($this->config, $renderEngine);
        $mailer->text('Welcome')->template('emails/welcome');

        self::assertSame('<h1>Welcome</h1>', $mailer->getPhpMailer()->Body);
        self::assertSame('Welcome', $mailer->getPhpMailer()->AltBody);
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

    public function testAnInvalidFromAddressNamesTheConfigurationKey(): void
    {
        $config = MailerConfig::fromArray([
            'smtp' => ['host' => 'localhost', 'port' => 2525, 'encryption' => ''],
            'from' => ['address' => 'not an address', 'name' => 'Test App'],
        ]);

        try {
            new Mailer($config);
            self::fail('An invalid from address was accepted.');
        } catch (MailerException $e) {
            self::assertSame('Invalid email address in the from.address configuration.', $e->getMessage());
            self::assertSame(MailerFailure::InvalidAddress, $e->failure);
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

    public function testPartialRefusalFromTheTransportMapsToRecipientsRefused(): void
    {
        $mailer = new Mailer($this->config);
        $reason = $mailer->getPhpMailer()->getTranslations()['recipients_failed'];

        $mapped = $this->mapTransportFailure(
            $mailer,
            new PHPMailerException($reason . 'a@example.test: 550 no such user', PHPMailer::STOP_CONTINUE),
        );

        self::assertSame(MailerFailure::RecipientsRefused, $mapped->failure);
        self::assertSame($reason . 'a@example.test: 550 no such user', $mapped->transportMessage());
        foreach ($mapped->getTrace() as $frame) {
            if (($frame['function'] ?? null) === 'transportFailure') {
                self::assertStringNotContainsString('a@example.test', print_r($frame['args'] ?? [], true));
            }
        }
        self::assertNull($mapped->getPrevious());
    }

    public function testAttachmentUnreadableAtSendTimeIsNotARecipientRefusal(): void
    {
        $mailer = new Mailer($this->config);
        $reason = $mailer->getPhpMailer()->getTranslations()['file_open'];

        $mapped = $this->mapTransportFailure(
            $mailer,
            new PHPMailerException($reason . '/missing/report.pdf', PHPMailer::STOP_CONTINUE),
        );

        self::assertSame(MailerFailure::SendFailed, $mapped->failure);
    }

    public function testUncodedTransportErrorMapsToSendFailed(): void
    {
        $mapped = $this->mapTransportFailure(
            new Mailer($this->config),
            new PHPMailerException('SMTP connect() failed.', PHPMailer::STOP_CRITICAL),
        );

        self::assertSame(MailerFailure::SendFailed, $mapped->failure);
    }

    public function testRefusedRecipientAfterAnAcceptedOneIsRecipientsRefusedThroughSend(): void
    {
        $mailer = $this->mailerWithScriptedTransport(refusedRecipients: ['bad@example.test']);
        $mailer->to('good@example.test')->to('bad@example.test');

        $this->assertTransportFailure($mailer, MailerFailure::RecipientsRefused, 'bad@example.test');
    }

    public function testEveryRecipientRefusedIsRecipientsRefusedThroughSend(): void
    {
        $mailer = $this->mailerWithScriptedTransport(refusedRecipients: ['bad1@example.test', 'bad2@example.test']);
        $mailer->to('bad1@example.test')->cc('bad2@example.test');

        $this->assertTransportFailure($mailer, MailerFailure::RecipientsRefused, 'bad2@example.test');
    }

    public function testRefusedDataIsSendFailedThroughSend(): void
    {
        $mailer = $this->mailerWithScriptedTransport(refusedRecipients: [], refuseData: true);
        $mailer->to('good@example.test');

        $this->assertTransportFailure($mailer, MailerFailure::SendFailed, 'data not accepted');
    }

    public function testAttachmentUnreadableAtSendTimeIsSendFailedThroughSend(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zephyrus-mail-');
        file_put_contents($path, 'attachment content');

        $mailer = $this->mailerWithScriptedTransport(refusedRecipients: []);
        $mailer->to('good@example.test')->attach($path, 'document.pdf');
        unlink($path);

        try {
            $mailer->subject('s')->text('t')->send();
            self::fail('The send succeeded without its attachment.');
        } catch (MailerException $e) {
            self::assertSame(MailerFailure::SendFailed, $e->failure);
        }
    }

    private function assertTransportFailure(Mailer $mailer, MailerFailure $failure, string $transportText): void
    {
        try {
            $mailer->subject('s')->text('t')->send();
            self::fail('The transport accepted a message it should have refused.');
        } catch (MailerException $e) {
            self::assertSame($failure, $e->failure);
            self::assertStringNotContainsString('@example.test', $e->getMessage());
            self::assertStringContainsString($transportText, (string) $e->transportMessage());
        }
    }

    /**
     * @param list<string> $refusedRecipients
     */
    private function mailerWithScriptedTransport(array $refusedRecipients, bool $refuseData = false): Mailer
    {
        $mailer = new Mailer($this->config);
        $mailer->getPhpMailer()->setSMTPInstance(new class ($refusedRecipients, $refuseData) extends SMTP {
            protected $error = ['error' => '', 'detail' => '', 'smtp_code' => '', 'smtp_code_ex' => ''];

            /**
             * @param list<string> $refusedRecipients
             */
            public function __construct(private readonly array $refusedRecipients, private readonly bool $refuseData)
            {
            }

            public function connected(): bool
            {
                return true;
            }

            public function mail($from): bool
            {
                return true;
            }

            public function recipient($address, $dsn = ''): bool
            {
                if (in_array($address, $this->refusedRecipients, true)) {
                    $this->error['detail'] = '550 no such user';

                    return false;
                }

                return true;
            }

            public function data($msg_data): bool
            {
                if ($this->refuseData) {
                    return false;
                }

                return true;
            }

            public function quit($close_on_error = true): bool
            {
                return true;
            }

            public function close(): void
            {
            }

            /**
             * @return array{error: string, detail: string, smtp_code: string, smtp_code_ex: string}
             */
            public function getError(): array
            {
                return $this->error;
            }

            public function getLastTransactionID(): string
            {
                return '';
            }
        });

        return $mailer;
    }

    private function mapTransportFailure(Mailer $mailer, PHPMailerException $error): MailerException
    {
        $mapped = (new \ReflectionMethod(Mailer::class, 'transportFailure'))->invoke($mailer, $error);
        self::assertInstanceOf(MailerException::class, $mapped);

        return $mapped;
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

    public function testAttachContentKeepsMediaTypeParameters(): void
    {
        $mailer = new Mailer($this->config);
        $mailer->attachContent('BEGIN:VCALENDAR', 'invite.ics', 'text/calendar; method=REQUEST');

        self::assertSame('text/calendar; method=REQUEST', $mailer->getPhpMailer()->getAttachments()[0][4]);
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
        yield 'zero' => ['0'];
        yield 'parent traversal' => ['../x'];
        yield 'CRLF header injection' => ["a\r\nb"];
        yield 'bare LF' => ["a\nb"];
        yield 'bare CR' => ["a\rb"];
        yield 'NUL byte' => ["a\0b"];
        yield 'forward slash' => ['a/b'];
        yield 'backslash' => ['a\\b'];
        yield 'dot' => ['.'];
        yield 'dot dot' => ['..'];
        yield 'blank' => ['   '];
        yield 'tab only' => ["\t"];
        yield 'zero padded with a space' => [' 0'];
        yield 'zero before a tab' => ["0\t"];
        yield 'dot dot padded with a space' => [' ..'];
        yield 'dot dot before a space' => ['.. '];
        yield 'three dots' => ['...'];
        yield 'zero with a trailing dot' => ['0.'];
        yield 'trailing dot' => ['report.'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidMimeTypeProvider(): iterable
    {
        yield 'no subtype' => ['text'];
        yield 'empty subtype' => ['text/'];
        yield 'empty type' => ['/pdf'];
        yield 'extra segment' => ['application/pdf/extra'];
        yield 'leading space' => [' application/pdf'];
        yield 'CRLF header injection' => ["text/plain\r\nX-Injected: 1"];
        yield 'CR LF inside a quoted value' => ["text/plain; charset=\"a\r\nX-Injected: 1\""];
        yield 'CR LF after a bare value' => ["text/plain; charset=utf-8\r\nX-Injected: 1"];
        yield 'backslash inside quotes' => ['text/plain; charset="a\\"b"'];
        yield 'vertical tab inside quotes' => ["text/plain; charset=\"a\x0Bb\""];
        yield 'non-ASCII inside quotes' => ['text/plain; charset="caf' . "\u{e9}" . '"'];
        yield 'semicolon inside quotes' => ['application/pdf; x="a; name=evil.exe"'];
        yield 'equals sign inside quotes' => ['text/plain; charset="a=b"'];
        yield 'unterminated quote' => ['text/plain; name="x'];
        yield 'name parameter' => ['application/pdf; name=evil.exe'];
        yield 'filename parameter' => ['application/pdf; filename="../../evil.exe"'];
        yield 'boundary parameter' => ['multipart/mixed; boundary=x'];
        yield 'upper case reserved parameter' => ['application/pdf; NAME=evil.exe'];
    }

    public function testAMediaTypeOf127BytesIsAccepted(): void
    {
        $prefix = 'text/plain; charset="';
        $mimeType = $prefix . str_repeat('a', 127 - strlen($prefix) - 1) . '"';
        self::assertSame(127, strlen($mimeType));

        $mailer = new Mailer($this->config);
        $mailer->attachContent('content', 'notes.txt', $mimeType);

        self::assertSame($mimeType, $mailer->getPhpMailer()->getAttachments()[0][4]);
    }

    public function testAMediaTypeOf128BytesIsRefused(): void
    {
        $prefix = 'text/plain; charset="';
        $mimeType = $prefix . str_repeat('a', 128 - strlen($prefix) - 1) . '"';
        self::assertSame(128, strlen($mimeType));

        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('is longer than 127 bytes');

        $mailer->attachContent('content', 'notes.txt', $mimeType);
    }

    public function testADisplayNameOf255BytesIsAcceptedByAttachContent(): void
    {
        $name = str_repeat('a', 251) . '.pdf';
        self::assertSame(255, strlen($name));

        $mailer = new Mailer($this->config);
        $mailer->attachContent('content', $name);

        self::assertSame($name, $mailer->getPhpMailer()->getAttachments()[0][2]);
    }

    public function testADisplayNameLongerThan255BytesIsRefusedByAttachContent(): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('is longer than 255 bytes');

        $mailer->attachContent('content', str_repeat('a', 256) . '.pdf');
    }

    #[DataProviderExternal(MailerAttachmentGuardTest::class, 'controlBidiOrLineSeparatorNameProvider')]
    public function testAControlBidiOrLineSeparatorInTheDisplayNameIsRefusedByAttachContent(string $name): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('contains a control, bidirectional formatting or line separator character');

        $mailer->attachContent('content', $name);
    }

    public function testAPlainDisplayNameWithSpacesAndAccentsIsAcceptedByAttachContent(): void
    {
        $mailer = new Mailer($this->config);
        $mailer->attachContent('content', 'Rapport annuel été.pdf');

        self::assertSame('Rapport annuel été.pdf', $mailer->getPhpMailer()->getAttachments()[0][2]);
    }

    public function testAnEncodedWordInTheDisplayNameIsRefusedByAttachContent(): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('contains "=?", an encoded word; pass a plain file name');

        $mailer->attachContent('content', '=?utf-8?Q?=2E=2E=2F=2E=2E=2Fevil.exe?=');
    }

    public function testAnEmptyDisplayNameIsRefusedByAttachContentWithOneMessage(): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('is not a usable file name');

        $mailer->attachContent('content', '');
    }

    public function testAMalformedMediaTypeMessageNamesTheParameterPlaceholderAndTheFileNameRule(): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('attribute=value parameters; name, filename and boundary are set by the mailer; pass the file name as $name');

        $mailer->attachContent('content', 'report.pdf', 'text');
    }

    public function testAttachContentAcceptsWhitespaceBeforeTheParameterSeparator(): void
    {
        $mailer = new Mailer($this->config);
        $mailer->attachContent('content', 'notes.txt', 'text/plain ; charset=utf-8');

        self::assertSame('text/plain ; charset=utf-8', $mailer->getPhpMailer()->getAttachments()[0][4]);
    }
}
