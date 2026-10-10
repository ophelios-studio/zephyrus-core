<?php

declare(strict_types=1);

namespace Zephyrus\Mailer;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use Zephyrus\Rendering\RenderEngine;

/**
 * Fluent email builder wrapping PHPMailer.
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
 *       ->template('emails/welcome', ['name' => 'Ada'])
 *       ->send();
 */
final class Mailer
{
    /** Error log line written once when a mailer would send credentials unencrypted. */
    public const string PLAINTEXT_CREDENTIALS_WARNING =
        'Zephyrus: SMTP credentials will be sent WITHOUT transport encryption, because '
        . 'mailer.smtp.encryption is empty. Set it to "tls" (submission, port 587) or "ssl" '
        . '(implicit TLS, port 465) unless this really is a local sink.';

    /** Set once the plaintext warning is logged, so a send loop does not flood the log. */
    private static bool $plaintextWarningEmitted = false;

    private PHPMailer $mail;
    private ?RenderEngine $renderEngine;
    private ?string $htmlBody = null;
    private ?string $textBody = null;

    private const string MIME_TYPE_PATTERN = '~\A[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*(?:[ \t]*;[ \t]*(?!(?:name|filename|boundary)=)[a-z0-9][a-z0-9!#$&^_.+-]*=(?:[a-z0-9!#$&^_.+-]+|"[\x20\x21\x23-\x3A\x3C\x3E-\x5B\x5D-\x7E]*"))*\z~i';

    private const string PATH_SEPARATOR_PATTERN = '~[/\\\\]~';

    /** Controls, DEL, U+061C, line separators and bidi controls, matched on bytes. */
    private const string REFUSED_NAME_CHARACTER_PATTERN = '~[\x00-\x1F\x7F]|\xC2[\x80-\x9F]|\xD8\x9C|\xE2\x80[\x8E\x8F\xA8-\xAE]|\xE2\x81[\xA6-\xA9]~';

    /** Longest display name: PHPMailer folds header lines over 998 bytes unindented. */
    private const int MAX_HEADER_VALUE_BYTES = 255;

    /** Longest media type: the Content-Type line also carries the encoded display name. */
    private const int MAX_MEDIA_TYPE_BYTES = 127;

    /**
     * @throws MailerException when from.address is not a valid address.
     */
    public function __construct(MailerConfig $config, ?RenderEngine $renderEngine = null)
    {
        $this->renderEngine = $renderEngine;
        $this->mail = new PHPMailer(exceptions: true);
        $this->configureSmtp($config);
        $this->configureFrom($config);
    }

    /**
     * Add a "To" recipient.
     *
     * @throws MailerException if the address is invalid, line breaks included (PHPMailer's default validator).
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
     *
     * @throws MailerException if the address is invalid, line breaks included (PHPMailer's default validator).
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
     *
     * @throws MailerException if the address is invalid, line breaks included (PHPMailer's default validator).
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
     *
     * @throws MailerException if the address is invalid, line breaks included (PHPMailer's default validator).
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
     * Set the email subject. PHPMailer strips CR and LF from it before encoding.
     */
    public function subject(string $subject): self
    {
        $this->mail->Subject = $subject;
        return $this;
    }

    /**
     * Set the HTML body. Each call recomposes the PHPMailer body, replacing any AltBody set on getPhpMailer().
     */
    public function html(string $body): self
    {
        $this->htmlBody = $body;
        $this->compose();
        return $this;
    }

    /**
     * Set the plain text body, or the alternative text of an HTML body. Each call recomposes the PHPMailer body.
     */
    public function text(string $body): self
    {
        $this->textBody = $body;
        $this->compose();
        return $this;
    }

    /**
     * Render a template as the HTML body, using the RenderEngine given to the constructor.
     *
     * @param string              $page The template identifier.
     * @param array<string,mixed> $args Template variables.
     *
     * @throws MailerException when no RenderEngine was given.
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
     * Refuses NUL bytes, stream wrappers and unsafe names. Pass $allowedRoot whenever any
     * part of $path comes from outside the application: the file must then resolve inside it.
     *
     * @param string      $path        Path to the file. Absolute is strongly preferred.
     * @param string      $name        Display name, defaulting to the file's basename. It must not
     *                                 contain a control, bidirectional or line separator character,
     *                                 a path separator or "=?", exceed 255 bytes, carry surrounding
     *                                 spaces, be blank, "0", "." or "..", or end with a dot. The
     *                                 media type follows its extension, else the file's.
     * @param string|null $allowedRoot Directory the file must resolve under. Null trusts the caller.
     *
     * @throws MailerException if the path or name is refused, the file does not exist, $allowedRoot
     *                         is not an existing directory, or the file resolves outside it.
     */
    public function attach(string $path, string $name = '', ?string $allowedRoot = null): self
    {
        if (str_contains($path, "\0")) {
            throw MailerException::attachmentRejected('path', $path, 'contains a NUL byte');
        }

        // Not every build makes is_file() reject every wrapper, so refuse them explicitly.
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $path) === 1) {
            throw MailerException::attachmentRejected('path', $path, 'is a stream wrapper, not a local file');
        }

        if (!is_file($path)) {
            throw MailerException::attachmentNotFound($path);
        }

        $sentName = $name !== '' ? $name : basename($path);
        $this->assertSentName($sentName, false, $name === '');

        if ($allowedRoot !== null) {
            $this->assertWithinRoot($path, $allowedRoot);
        }

        try {
            $this->mail->addAttachment($path, $sentName, PHPMailer::ENCODING_BASE64, $this->mediaTypeOf($sentName, $path));
        } catch (PHPMailerException) {
            throw MailerException::attachmentRejected('path', $path, 'could not be attached');
        }

        return $this;
    }

    /**
     * Attach bytes held in memory, such as a generated PDF.
     *
     * @param string      $content  The file contents.
     * @param string      $name     Display name. Same rules as attach().
     * @param string|null $mimeType Type/subtype of at most 127 bytes, optionally followed by parameters
     *                              such as "; method=REQUEST" but not name, filename or boundary. Null
     *                              lets PHPMailer infer it from $name.
     *
     * @throws MailerException if the name or the media type is malformed.
     */
    public function attachContent(string $content, string $name, ?string $mimeType = null): self
    {
        $this->assertSentName($name, true);

        if ($mimeType !== null && strlen($mimeType) > self::MAX_MEDIA_TYPE_BYTES) {
            throw MailerException::attachmentRejected('media type', $mimeType, 'is longer than ' . self::MAX_MEDIA_TYPE_BYTES . ' bytes');
        }

        if ($mimeType !== null && preg_match(self::MIME_TYPE_PATTERN, $mimeType) !== 1) {
            throw MailerException::attachmentRejected('media type', $mimeType, 'is not type/subtype optionally followed by ; attribute=value parameters; name, filename and boundary are set by the mailer; pass the file name as $name');
        }

        $this->mail->addStringAttachment($content, $name, PHPMailer::ENCODING_BASE64, $mimeType ?? '');

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
     * STOP_CONTINUE also fires when an attachment is unreadable before DATA; only the recipients_failed message means refusal.
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
     * The sent name's type, or the file's when the sent name has no known extension.
     */
    private function mediaTypeOf(string $sentName, string $path): string
    {
        $type = PHPMailer::filenameToType($sentName);

        return $type === 'application/octet-stream' ? PHPMailer::filenameToType(basename($path)) : $type;
    }

    /**
     * Refuse a display or file name that could split a MIME header, name a path, or be altered by PHPMailer.
     *
     * @param bool $fromFileName True when the name is the file's own name, because the caller gave no display name.
     */
    private function assertSentName(string $name, bool $isStringAttachment, bool $fromFileName = false): void
    {
        if (strlen($name) > self::MAX_HEADER_VALUE_BYTES) {
            $this->refuseName($name, $fromFileName, 'is longer than ' . self::MAX_HEADER_VALUE_BYTES . ' bytes');
        }

        if (preg_match(self::PATH_SEPARATOR_PATTERN, $name) === 1) {
            $this->refuseName($name, $fromFileName, 'contains a path separator', 'pass a bare file name');
        }

        if (str_contains($name, '=?')) {
            $this->refuseName($name, $fromFileName, 'contains "=?", an encoded word', 'pass a plain file name');
        }

        if (preg_match(self::REFUSED_NAME_CHARACTER_PATTERN, $name) === 1) {
            $this->refuseName($name, $fromFileName, 'contains a control, bidirectional formatting or line separator character');
        }

        $basename = $isStringAttachment ? PHPMailer::mb_pathinfo($name, PATHINFO_BASENAME) : $name;
        $sent = trim(is_string($basename) ? $basename : '');

        if ($sent !== $name || str_ends_with($name, '.') || in_array($name, ['', '0', '.', '..'], true)) {
            $this->refuseName(
                $name,
                $fromFileName,
                'is not a usable file name (blank, "0", "." or "..", ends with a dot, or the mailer would trim or shorten it)',
            );
        }
    }

    /**
     * @throws MailerException always.
     */
    private function refuseName(string $name, bool $fromFileName, string $reason, string $advice = ''): never
    {
        if ($fromFileName) {
            throw MailerException::attachmentRejected(
                'file name',
                $name,
                $reason . '; pass a display name as the second argument of attach()',
            );
        }

        throw MailerException::attachmentRejected(
            'display name',
            $name,
            $advice === '' ? $reason : $reason . '; ' . $advice,
        );
    }

    /**
     * Refuse a $path that resolves outside $allowedRoot. realpath() on both sides
     * collapses '..', follows symlinks and fails closed on a missing path.
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

        // Empty means no encryption at all: disable opportunistic STARTTLS too, since
        // a network attacker can downgrade to plaintext by omitting it from the EHLO reply.
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
     * Log PLAINTEXT_CREDENTIALS_WARNING once per process.
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
            } catch (PHPMailerException) {
                throw MailerException::invalidFromAddress();
            }
        }
    }
}
