<?php

declare(strict_types=1);

namespace Zephyrus\Mailer;

/**
 * The family of a MailerException, so a caller can tell a permanent refusal
 * (an invalid address, a missing attachment) from a transport failure that may
 * succeed on retry, without parsing the message.
 */
enum MailerFailure: string
{
    case InvalidAddress = 'invalid_address';
    case SendFailed = 'send_failed';
    case AttachmentNotFound = 'attachment_not_found';
    case AttachmentRejected = 'attachment_rejected';
    case ConfigurationMissing = 'configuration_missing';
}
