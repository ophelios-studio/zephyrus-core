<?php

declare(strict_types=1);

namespace Zephyrus\Mailer;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use Zephyrus\Rendering\RenderEngine;

/**
 * Fluent email builder wrapping PHPMailer.
 *
 * Supports SMTP transport, template-based HTML bodies (via any RenderEngine),
 * plain text alternatives, file attachments, and CC/BCC recipients.
 *
 * Usage:
 *
 *   $mailer = new Mailer($config);
 *   $mailer->to('user@example.com')
 *       ->subject('Welcome')
 *       ->html('<h1>Hello!</h1>')
 *       ->send();
 *
 *   // With a template engine:
 *   $mailer = new Mailer($config, $latteEngine);
 *   $mailer->to('user@example.com')
 *       ->subject('Welcome')
 *       ->template('emails/welcome', ['name' => 'David'])
 *       ->send();
 */
final class Mailer
{
    /**
     * The line written to the error log the first time this process builds a
     * mailer that will put credentials on an unencrypted socket.
     */
    public const string PLAINTEXT_CREDENTIALS_WARNING =
        'Zephyrus: SMTP credentials will be sent WITHOUT transport encryption, because '
        . 'mailer.smtp.encryption is empty. Set it to "tls" (submission, port 587) or "ssl" '
        . '(implicit TLS, port 465) unless this really is a local sink.';

    /**
     * Emitted at most once per process: this is a configuration mistake, not a
     * per-message event, and a line per email would bury it.
     */
    private static bool $plaintextWarningEmitted = false;

    private PHPMailer $mail;
    private ?RenderEngine $renderEngine;

    public function __construct(MailerConfig $config, ?RenderEngine $renderEngine = null)
    {
        $this->renderEngine = $renderEngine;
        $this->mail = new PHPMailer(exceptions: true);
        $this->configureSmtp($config);
        $this->configureFrom($config);
    }

    /**
     * Add a "To" recipient.
     */
    public function to(string $address, string $name = ''): self
    {
        try {
            $this->mail->addAddress($address, $name);
        } catch (PHPMailerException $e) {
            throw MailerException::invalidAddress($address);
        }

        return $this;
    }

    /**
     * Add a "CC" recipient.
     */
    public function cc(string $address, string $name = ''): self
    {
        try {
            $this->mail->addCC($address, $name);
        } catch (PHPMailerException $e) {
            throw MailerException::invalidAddress($address);
        }

        return $this;
    }

    /**
     * Add a "BCC" recipient.
     */
    public function bcc(string $address, string $name = ''): self
    {
        try {
            $this->mail->addBCC($address, $name);
        } catch (PHPMailerException $e) {
            throw MailerException::invalidAddress($address);
        }

        return $this;
    }

    /**
     * Set a "Reply-To" address.
     */
    public function replyTo(string $address, string $name = ''): self
    {
        try {
            $this->mail->addReplyTo($address, $name);
        } catch (PHPMailerException $e) {
            throw MailerException::invalidAddress($address);
        }

        return $this;
    }

    /**
     * Set the email subject.
     */
    public function subject(string $subject): self
    {
        $this->mail->Subject = $subject;
        return $this;
    }

    /**
     * Set a raw HTML body.
     */
    public function html(string $body): self
    {
        $this->mail->isHTML(true);
        $this->mail->Body = $body;
        return $this;
    }

    /**
     * Set a plain text body (or alternative text for HTML emails).
     */
    public function text(string $body): self
    {
        if ($this->mail->ContentType === PHPMailer::CONTENT_TYPE_TEXT_HTML) {
            $this->mail->AltBody = $body;
        } else {
            $this->mail->isHTML(false);
            $this->mail->Body = $body;
        }

        return $this;
    }

    /**
     * Render a template as the HTML body.
     *
     * Requires a RenderEngine to be provided in the constructor.
     *
     * @param string              $page The template identifier.
     * @param array<string,mixed> $args Template variables.
     */
    public function template(string $page, array $args = []): self
    {
        if ($this->renderEngine === null) {
            throw MailerException::configurationMissing(
                'A RenderEngine is required for template-based emails.',
            );
        }

        $this->mail->isHTML(true);
        $this->mail->Body = $this->renderEngine->render($page, $args);
        return $this;
    }

    /**
     * Attach a file.
     *
     * ## This is not a sandbox, and the docblock used to imply it was
     *
     * It said "Absolute path to the file" and enforced nothing, so a caller
     * that passed unvalidated input got exactly what it asked for: any file the
     * PHP process can read, traversal included, mailed to the recipient. The
     * guards below close what a library CAN close on its own -- a stream
     * wrapper, a NUL byte, a display name carrying path separators -- but none
     * of them can tell a wanted path from an attacker's.
     *
     * $allowedRoot is how a caller states the boundary it actually has. When
     * given, the resolved file must sit inside the resolved root, and anything
     * else is refused. Pass it whenever any part of $path came from outside the
     * application.
     *
     * @param string      $path        Path to the file. Absolute is strongly preferred; a
     *                                 relative path still resolves against the working
     *                                 directory, which is rarely what a caller means.
     * @param string      $name        Display name (default: original filename). May not
     *                                 contain a path separator: it lands in a MIME header
     *                                 and is what the recipient's client writes to disk.
     * @param string|null $allowedRoot Directory the attachment must live under. Null keeps
     *                                 the historical behaviour of trusting the caller.
     */
    public function attach(string $path, string $name = '', ?string $allowedRoot = null): self
    {
        if (str_contains($path, "\0") || str_contains($name, "\0")) {
            throw MailerException::attachmentRejected($path, 'contains a NUL byte');
        }

        // A wrapper turns "attach a file" into "fetch a URL" or "read a php://
        // stream". is_file() rejects most of them already, but not all wrappers
        // in every build, and refusing here states the rule instead of relying
        // on that.
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $path) === 1) {
            throw MailerException::attachmentRejected($path, 'is a stream wrapper, not a local file');
        }

        if (str_contains($name, '/') || str_contains($name, '\\')) {
            throw MailerException::attachmentRejected($name, 'is a display name and may not contain a path separator');
        }

        if (!is_file($path)) {
            throw MailerException::attachmentNotFound($path);
        }

        if ($allowedRoot !== null) {
            $this->assertWithinRoot($path, $allowedRoot);
        }

        try {
            $this->mail->addAttachment($path, $name);
        } catch (PHPMailerException $e) {
            throw MailerException::sendFailed('Failed to add attachment: ' . $e->getMessage(), $e);
        }

        return $this;
    }

    /**
     * Send the email.
     *
     * @throws MailerException if sending fails.
     */
    public function send(): void
    {
        try {
            $this->mail->send();
        } catch (PHPMailerException $e) {
            throw MailerException::sendFailed($e->getMessage(), $e);
        }
    }

    /**
     * Access the underlying PHPMailer instance for advanced configuration.
     */
    public function getPhpMailer(): PHPMailer
    {
        return $this->mail;
    }

    /**
     * Resolve $path against $allowedRoot and refuse anything outside it.
     *
     * realpath() on BOTH sides is what makes this a boundary rather than a
     * string comparison: it collapses '..', follows symlinks, and returns false
     * for a path that does not exist, so a link pointing out of the root cannot
     * pass by looking innocent.
     */
    private function assertWithinRoot(string $path, string $allowedRoot): void
    {
        $resolvedRoot = realpath($allowedRoot);
        if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
            throw MailerException::attachmentRejected($allowedRoot, 'is not an existing directory');
        }

        $resolvedFile = realpath($path);
        if ($resolvedFile === false) {
            throw MailerException::attachmentNotFound($path);
        }

        if (!str_starts_with($resolvedFile, rtrim($resolvedRoot, '/\\') . DIRECTORY_SEPARATOR)) {
            throw MailerException::attachmentRejected($path, 'resolves outside the allowed directory');
        }
    }

    private function configureSmtp(MailerConfig $config): void
    {
        $this->mail->isSMTP();
        $this->mail->Host = $config->smtpHost;
        $this->mail->Port = $config->smtpPort;
        $this->mail->SMTPSecure = $config->smtpEncryption;
        $this->mail->CharSet = PHPMailer::CHARSET_UTF8;

        // MailerConfig guarantees one of 'tls', 'ssl' or ''. An empty value is
        // the operator saying "no encryption", so say it to PHPMailer too:
        // SMTPAutoTLS would otherwise still try STARTTLS opportunistically, and
        // that is the worst of the three answers. It looks encrypted in a happy
        // capture, it produces no error when it does not happen, and a network
        // attacker turns it off simply by omitting STARTTLS from the EHLO
        // banner. '' now means none, and 'tls' means tls.
        if ($config->smtpEncryption === '') {
            $this->mail->SMTPAutoTLS = false;
        }

        if ($config->smtpUsername !== '' || $config->smtpPassword !== '') {
            $this->mail->SMTPAuth = true;
            $this->mail->Username = $config->smtpUsername;
            $this->mail->Password = $config->smtpPassword;

            if ($config->smtpEncryption === '') {
                self::warnAboutPlaintextCredentials();
            }
        }
    }

    /**
     * Say once, loudly, that this process will authenticate in the clear.
     *
     * Turning '' into "definitely no encryption" is the honest reading of the
     * setting, but it also removes the accidental safety net that opportunistic
     * STARTTLS used to provide for a deployment that simply forgot to set
     * MAIL_ENCRYPTION. Silence is what made the original bug survive, so the
     * net is replaced by a statement rather than by nothing.
     */
    private static function warnAboutPlaintextCredentials(): void
    {
        if (self::$plaintextWarningEmitted) {
            return;
        }

        self::$plaintextWarningEmitted = true;
        error_log(self::PLAINTEXT_CREDENTIALS_WARNING);
    }

    private function configureFrom(MailerConfig $config): void
    {
        if ($config->fromAddress !== '') {
            try {
                $this->mail->setFrom($config->fromAddress, $config->fromName);
            } catch (PHPMailerException $e) {
                throw MailerException::invalidAddress($config->fromAddress);
            }
        }
    }
}
