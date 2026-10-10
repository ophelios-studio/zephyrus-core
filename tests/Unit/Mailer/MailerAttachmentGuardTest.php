<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Mailer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Mailer\Mailer;
use Zephyrus\Mailer\MailerConfig;
use Zephyrus\Mailer\MailerException;
use Zephyrus\Mailer\MailerFailure;

/**
 * attach() had no guard at all and a docblock that implied one: it said
 * "Absolute path to the file" and enforced nothing. A caller that passed
 * unvalidated input therefore attached any file the process could read.
 *
 * A library cannot tell a wanted path from an attacker's, so the fix is two
 * parts: refuse what could never be a legitimate local attachment, and give the
 * caller a way to state the boundary it actually has ($allowedRoot).
 *
 * Nothing here sends anything: every case builds the message and stops.
 */
final class MailerAttachmentGuardTest extends TestCase
{
    private MailerConfig $config;
    private string $root;
    private string $inside;
    private string $outside;

    protected function setUp(): void
    {
        $this->config = MailerConfig::fromArray([
            'smtp' => ['host' => 'localhost', 'port' => 1025, 'encryption' => ''],
            'from' => ['address' => 'noreply@example.test', 'name' => 'Test'],
        ]);

        $base = sys_get_temp_dir() . '/zephyrus-attach-' . bin2hex(random_bytes(8));
        $this->root = $base . '/allowed';
        mkdir($this->root, 0o755, true);

        $this->inside = $this->root . '/invoice.pdf';
        file_put_contents($this->inside, 'inside');

        $this->outside = $base . '/secrets.env';
        file_put_contents($this->outside, 'outside');
    }

    protected function tearDown(): void
    {
        @unlink($this->inside);
        @unlink($this->outside);
        @rmdir($this->root);
        @rmdir(dirname($this->root));
    }

    public function testAFileInsideTheAllowedRootIsAttached(): void
    {
        $mailer = new Mailer($this->config);
        $mailer->attach($this->inside, 'invoice.pdf', allowedRoot: $this->root);

        self::assertCount(1, $mailer->getPhpMailer()->getAttachments());
    }

    public function testATraversalOutOfTheAllowedRootIsRefused(): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('resolves outside the allowed directory');

        $mailer->attach($this->root . '/../secrets.env', 'anything.pdf', allowedRoot: $this->root);
    }

    public function testAnAbsolutePathOutsideTheAllowedRootIsRefused(): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('resolves outside the allowed directory');

        $mailer->attach($this->outside, 'anything.pdf', allowedRoot: $this->root);
    }

    public function testAnAllowedRootThatDoesNotExistIsRefusedRatherThanIgnored(): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('is not an existing directory');

        $mailer->attach($this->inside, 'invoice.pdf', allowedRoot: $this->root . '/nope');
    }

    public function testAnUnreadableFileIsRefusedAsAttachmentRejected(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Root reads files whatever their mode.');
        }

        chmod($this->inside, 0o000);

        try {
            $mailer = new Mailer($this->config);
            $mailer->attach($this->inside, 'invoice.pdf', allowedRoot: $this->root);
            self::fail('An unreadable file was attached.');
        } catch (MailerException $e) {
            self::assertSame(MailerFailure::AttachmentRejected, $e->failure);
        } finally {
            chmod($this->inside, 0o644);
        }
    }

    public function testAStreamWrapperIsNotAFile(): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('is a stream wrapper, not a local file');

        $mailer->attach('https://example.test/payload.pdf');
    }

    public function testAPhpStreamIsNotAFileEither(): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('is a stream wrapper, not a local file');

        $mailer->attach('php://input');
    }

    public function testANulByteInThePathIsRefused(): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('contains a NUL byte');

        $mailer->attach($this->inside . "\0.png");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function displayNameWithControlCharacterProvider(): iterable
    {
        yield 'carriage return' => ["a\rb"];
        yield 'line feed' => ["a\nb"];
        yield 'NUL byte' => ["a\0b"];
    }

    #[DataProvider('displayNameWithControlCharacterProvider')]
    public function testADisplayNameWithAControlCharacterIsRefusedByAttach(string $name): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('contains a NUL byte, a line break or a path separator');

        $mailer->attach($this->inside, $name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableDisplayNameProvider(): iterable
    {
        yield 'zero' => ['0'];
        yield 'dot' => ['.'];
        yield 'dot dot' => ['..'];
        yield 'blank' => [' '];
        yield 'tab only' => ["\t"];
        yield 'zero padded with a space' => [' 0'];
        yield 'zero before a tab' => ["0\t"];
        yield 'dot dot padded with a space' => [' ..'];
        yield 'dot dot before a space' => ['.. '];
        yield 'trailing dot' => ['report.'];
        yield 'double extension with trailing dot' => ['evil.exe.'];
        yield 'dots only' => ['...'];
        yield 'zero before a dot' => ['0.'];
    }

    #[DataProvider('unusableDisplayNameProvider')]
    public function testAnUnusableDisplayNameIsRefusedByAttach(string $name): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('is not a usable file name');

        $mailer->attach($this->inside, $name);
    }


    public function testADisplayNameOf255BytesIsAcceptedByAttach(): void
    {
        $mailer = new Mailer($this->config);
        $mailer->attach($this->inside, str_repeat('a', 255));

        self::assertSame(str_repeat('a', 255), $mailer->getPhpMailer()->getAttachments()[0][2]);
    }

    public function testADisplayNameLongerThan255BytesIsRefusedByAttach(): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('is longer than 255 bytes');

        $mailer->attach($this->inside, str_repeat('a', 957));
    }

    public function testAnEmptyDisplayNameMeansTheFileName(): void
    {
        $mailer = new Mailer($this->config);
        $mailer->attach($this->inside);

        self::assertSame('invoice.pdf', $mailer->getPhpMailer()->getAttachments()[0][2]);
    }

    public static function encodedWordDisplayNameProvider(): iterable
    {
        yield 'encoded dot dot slash' => ['=?utf-8?Q?=2E=2E=2F=2E=2E=2Fevil.exe?='];
        yield 'encoded word in the middle' => ['report=?utf-8?Q?x?=.pdf'];
        yield 'bare equals and question mark' => ['a=?b'];
    }

    #[DataProvider('encodedWordDisplayNameProvider')]
    public function testAnEncodedWordInTheDisplayNameIsRefusedByAttach(string $name): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('contains "=?", an encoded word; pass a plain file name');

        $mailer->attach($this->inside, $name);
    }

    public function testAPathSeparatorInTheDisplayNameIsRefused(): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('a path separator; pass a bare file name');

        $mailer->attach($this->inside, '../../.ssh/authorized_keys');
    }

    public function testTheHistoricalUnboundedCallStillWorks(): void
    {
        $mailer = new Mailer($this->config);
        $mailer->attach($this->outside, 'secrets.env');

        self::assertCount(1, $mailer->getPhpMailer()->getAttachments());
    }

    public static function unusableFileNameProvider(): iterable
    {
        yield 'encoded word' => ['=?utf-8?Q?evil.exe?='];
        yield 'trailing dot' => ['report.'];
        yield 'leading space' => [' invoice.pdf'];
    }

    #[DataProvider('unusableFileNameProvider')]
    public function testAnUnusableFileNameIsRefusedWhenNoDisplayNameIsGiven(string $fileName): void
    {
        $path = $this->root . '/' . $fileName;
        file_put_contents($path, 'payload');

        try {
            $mailer = new Mailer($this->config);

            $this->expectException(MailerException::class);
            $this->expectExceptionMessage('Attachment rejected: file name');

            $mailer->attach($path);
        } finally {
            @unlink($path);
        }
    }

    public function testAMissingFileStillReportsItAsMissing(): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('Attachment not found');

        $mailer->attach('/nonexistent/file.pdf');
    }

    public function testAnAttachmentIsSentUnderTheNameThatWasChecked(): void
    {
        $dir = $this->root . "/dir\nsub";
        if (!@mkdir($dir, 0o755)) {
            self::markTestSkipped('The file system refuses a directory name with a line feed.');
        }

        $path = $dir . '/report.pdf';
        file_put_contents($path, 'pdf');

        try {
            $mailer = new Mailer($this->config);
            $mailer->to('good@example.test')->subject('s')->text('t')->attach($path, allowedRoot: $this->root);
            $mailer->getPhpMailer()->preSend();
            $message = $mailer->getPhpMailer()->getSentMIMEMessage();

            self::assertStringContainsString('name=report.pdf', $message);
            self::assertStringContainsString('filename=report.pdf', $message);
            self::assertStringContainsString('Content-Type: application/pdf', $message);
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    public function testAMissingFileIsReportedAsMissingBeforeItsNameIsChecked(): void
    {
        foreach (['', '/nope/x.pdf'] as $path) {
            $mailer = new Mailer($this->config);

            try {
                $mailer->attach($path);
                self::fail('A missing file was attached.');
            } catch (MailerException $e) {
                self::assertSame(MailerFailure::AttachmentNotFound, $e->failure, $path);
            }
        }
    }

    public function testARefusedFileNameSaysFileNameAndPointsToTheDisplayNameArgument(): void
    {
        $path = $this->root . '/report.';
        file_put_contents($path, 'payload');

        try {
            $mailer = new Mailer($this->config);

            try {
                $mailer->attach($path);
                self::fail('A file name ending with a dot was attached.');
            } catch (MailerException $e) {
                self::assertStringStartsWith('Attachment rejected: file name "report." ', $e->getMessage());
                self::assertStringEndsWith('; pass a display name as the second argument of attach().', $e->getMessage());
            }
        } finally {
            @unlink($path);
        }
    }

    public function testARefusedDisplayNameSaysDisplayName(): void
    {
        $mailer = new Mailer($this->config);

        try {
            $mailer->attach($this->inside, 'report.');
            self::fail('A display name ending with a dot was attached.');
        } catch (MailerException $e) {
            self::assertStringStartsWith('Attachment rejected: display name "report." ', $e->getMessage());
            self::assertStringNotContainsString('second argument', $e->getMessage());
        }
    }

    public function testAFileNameLongerThan255BytesIsRefusedAsAFileName(): void
    {
        $fileName = str_repeat('é', 128) . '.pdf';
        $path = $this->root . '/' . $fileName;

        if (@file_put_contents($path, 'payload') === false) {
            self::markTestSkipped('The file system refuses a file name longer than 255 bytes.');
        }

        try {
            $mailer = new Mailer($this->config);

            $this->expectException(MailerException::class);
            $this->expectExceptionMessage('Attachment rejected: file name');
            $this->expectExceptionMessage('is longer than 255 bytes; pass a display name as the second argument of attach()');

            $mailer->attach($path);
        } finally {
            @unlink($path);
        }
    }
}
