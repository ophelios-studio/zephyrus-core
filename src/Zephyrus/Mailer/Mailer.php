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
    private ?string $htmlBody = null;
    private ?string $textBody = null;

    private const string MIME_TYPE_PATTERN = '~\A[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*\z~i';

    private const string DISPLAY_NAME_PATTERN = '~[\x00\r\n/\\\\]~';

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
    public function to(#[\SensitiveParameter] string $address, #[\SensitiveParameter] string $name = ''): self
    {
        try {
            $this->mail->addAddress($address, $name);
        } catch (PHPMailerException $e) {
            throw MailerException::invalidAddress('to');
        }

        return $this;
    }

    /**
     * Add a "CC" recipient.
     */
    public function cc(#[\SensitiveParameter] string $address, #[\SensitiveParameter] string $name = ''): self
    {
        try {
            $this->mail->addCC($address, $name);
        } catch (PHPMailerException $e) {
            throw MailerException::invalidAddress('cc');
        }

        return $this;
    }

    /**
     * Add a "BCC" recipient.
     */
    public function bcc(#[\SensitiveParameter] string $address, #[\SensitiveParameter] string $name = ''): self
    {
        try {
            $this->mail->addBCC($address, $name);
        } catch (PHPMailerException $e) {
            throw MailerException::invalidAddress('bcc');
        }

        return $this;
    }

    /**
     * Set a "Reply-To" address.
     */
    public function replyTo(#[\SensitiveParameter] string $address, #[\SensitiveParameter] string $name = ''): self
    {
        try {
            $this->mail->addReplyTo($address, $name);
        } catch (PHPMailerException $e) {
            throw MailerException::invalidAddress('replyTo');
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
        $this->htmlBody = $body;
        $this->compose();
        return $this;
    }

    /**
     * Set a plain text body (or alternative text for HTML emails).
     */
    public function text(string $body): self
    {
        $this->textBody = $body;
        $this->compose();
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

        return $this->html($this->renderEngine->render($page, $args));
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
        if (str_contains($path, "\0")) {
            throw MailerException::attachmentRejected('path', $path, 'contains a NUL byte');
        }

        // A wrapper turns "attach a file" into "fetch a URL" or "read a php://
        // stream". is_file() rejects most of them already, but not all wrappers
        // in every build, and refusing here states the rule instead of relying
        // on that.
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $path) === 1) {
            throw MailerException::attachmentRejected('path', $path, 'is a stream wrapper, not a local file');
        }

        $this->assertDisplayName($name);

        if (!is_file($path)) {
            throw MailerException::attachmentNotFound($path);
        }

        if ($allowedRoot !== null) {
            $this->assertWithinRoot($path, $allowedRoot);
        }

        try {
            $this->mail->addAttachment($path, $name);
        } catch (PHPMailerException) {
            throw MailerException::attachmentRejected('path', $path, 'could not be attached');
        }

        return $this;
    }

    /**
     * Attach bytes held in memory, such as a generated PDF.
     *
     * @param string      $content  The file contents.
     * @param string      $name     Display name the recipient's client writes to disk: non-empty,
     *                              without NUL, CR, LF or a path separator.
     * @param string|null $mimeType Media type as type/subtype. Null lets PHPMailer infer it from $name.
     *
     * @throws MailerException if the name or the media type is malformed.
     */
    public function attachContent(string $content, string $name, ?string $mimeType = null): self
    {
        if ($name === '') {
            throw MailerException::attachmentRejected('display name', $name, 'must not be empty');
        }

        if ($name === '0') {
            throw MailerException::attachmentRejected('display name', $name, 'is treated as empty by the mail library');
        }

        $this->assertDisplayName($name);

        if ($mimeType !== null && preg_match(self::MIME_TYPE_PATTERN, $mimeType) !== 1) {
            throw MailerException::attachmentRejected('media type', $mimeType, 'is not type/subtype');
        }

        try {
            $this->mail->addStringAttachment($content, $name, PHPMailer::ENCODING_BASE64, $mimeType ?? '');
        } catch (PHPMailerException) {
            throw MailerException::attachmentRejected('display name', $name, 'could not be attached');
        }

        return $this;
    }

    /**
     * Send the email.
     *
     * @throws MailerException if sending fails, with a MailerFailure of SendFailed or RecipientsRefused; its transportMessage() may name recipients.
     */
    public function send(): void
    {
        try {
            $this->mail->send();
        } catch (PHPMailerException $e) {
            throw $this->transportFailure($e);
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
     * PHPMailer raises STOP_CONTINUE after DATA went to the accepted recipients,
     * but also when an attachment cannot be read while the body is built, before
     * any DATA. Only the first one means recipients were refused.
     */
    private function transportFailure(#[\SensitiveParameter] PHPMailerException $e): MailerException
    {
        $recipientsRefused = $e->getCode() === PHPMailer::STOP_CONTINUE
            && str_starts_with($e->getMessage(), $this->mail->getTranslations()['recipients_failed']);

        return $recipientsRefused
            ? MailerException::recipientsRefused($e->getMessage())
            : MailerException::sendFailed($e->getMessage());
    }

    /**
     * Refuse a display name that could split a MIME header or name a path.
     */
    private function assertDisplayName(string $name): void
    {
        if (preg_match(self::DISPLAY_NAME_PATTERN, $name) === 1) {
            throw MailerException::attachmentRejected(
                'display name',
                $name,
                'contains a NUL byte, a line break or a path separator; pass a bare file name',
            );
        }
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
            throw MailerException::attachmentRejected('directory', $allowedRoot, 'is not an existing directory');
        }

        $resolvedFile = realpath($path);
        if ($resolvedFile === false) {
            throw MailerException::attachmentNotFound($path);
        }

        if (!str_starts_with($resolvedFile, rtrim($resolvedRoot, '/\\') . DIRECTORY_SEPARATOR)) {
            throw MailerException::attachmentRejected('path', $path, 'resolves outside the allowed directory');
        }
    }

    /**
     * Derive the PHPMailer body from the HTML and text parts, whatever order they were set in.
     */
    private function compose(): void
    {
        if ($this->htmlBody !== null) {
            $this->mail->isHTML(true);
            $this->mail->Body = $this->htmlBody;
            $this->mail->AltBody = $this->textBody ?? '';
            return;
        }

        $this->mail->isHTML(false);
        $this->mail->Body = $this->textBody ?? '';
        $this->mail->AltBody = '';
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
                throw MailerException::invalidAddress('from');
            }
        }
    }
}
