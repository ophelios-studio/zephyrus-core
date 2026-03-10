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
     * @param string $path Absolute path to the file.
     * @param string $name Display name (default: original filename).
     */
    public function attach(string $path, string $name = ''): self
    {
        if (!is_file($path)) {
            throw MailerException::attachmentNotFound($path);
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

    private function configureSmtp(MailerConfig $config): void
    {
        $this->mail->isSMTP();
        $this->mail->Host = $config->smtpHost;
        $this->mail->Port = $config->smtpPort;
        $this->mail->SMTPSecure = $config->smtpEncryption;
        $this->mail->CharSet = PHPMailer::CHARSET_UTF8;

        if ($config->smtpUsername !== '' || $config->smtpPassword !== '') {
            $this->mail->SMTPAuth = true;
            $this->mail->Username = $config->smtpUsername;
            $this->mail->Password = $config->smtpPassword;
        }
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
