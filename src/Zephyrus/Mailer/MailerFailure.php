<?php

declare(strict_types=1);

namespace Zephyrus\Mailer;

/**
 * The family of a MailerException, so callers need not parse its message.
 */
enum MailerFailure: string
{
    /** A recipient or sender address was refused before anything was sent. */
    case InvalidAddress = 'invalid_address';

    /** The transport did not confirm acceptance: connection, authentication or relay refusal. */
    case SendFailed = 'send_failed';

    /** Some recipients were refused; accepted ones may already have the message, so a resend can duplicate it. */
    case RecipientsRefused = 'recipients_refused';

    /** An attachment file does not exist. */
    case AttachmentNotFound = 'attachment_not_found';

    /** An attachment, its display name or its media type was refused, or its path is outside the allowed directory. */
    case AttachmentRejected = 'attachment_rejected';

    /** The mailer was used without the configuration it needs. */
    case ConfigurationMissing = 'configuration_missing';
}
