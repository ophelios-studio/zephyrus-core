<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Mailer;

use PHPUnit\Framework\TestCase;
use Zephyrus\Mailer\Mailer;
use Zephyrus\Mailer\MailerConfig;
use Zephyrus\Mailer\MailerException;

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

        $base = sys_get_temp_dir() . '/zephyrus-attach-' . uniqid('', true);
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
     * The display name lands in a MIME header and is what the recipient's
     * client writes to disk, so a separator in it is a vector against the
     * RECIPIENT rather than against us.
     */
    public function testAPathSeparatorInTheDisplayNameIsRefused(): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('may not contain a path separator');

        $mailer->attach($this->inside, '../../.ssh/authorized_keys');
    }

    public function testTheHistoricalUnboundedCallStillWorks(): void
    {
        $mailer = new Mailer($this->config);
        $mailer->attach($this->outside, 'secrets.env');

        self::assertCount(1, $mailer->getPhpMailer()->getAttachments());
    }

    public function testAMissingFileStillReportsItAsMissing(): void
    {
        $mailer = new Mailer($this->config);

        $this->expectException(MailerException::class);
        $this->expectExceptionMessage('Attachment not found');

        $mailer->attach('/nonexistent/file.pdf');
    }
}
