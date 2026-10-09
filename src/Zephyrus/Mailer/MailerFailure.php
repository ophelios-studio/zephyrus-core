<?php

declare(strict_types=1);

namespace Zephyrus\Mailer;

/**
 * The family of a MailerException, so a caller can tell what happened without
 * parsing the message.
 */
enum MailerFailure: string
{
    /** A recipient or sender address was refused before anything was sent. */
    case InvalidAddress = 'invalid_address';

    /**
     * The transport did not confirm acceptance of the message: a connection,
     * authentication or the relay refusing the data.
     */
    case SendFailed = 'send_failed';

    /**
     * Some or all recipients were refused. The accepted ones may already have
     * received the message, so sending it again can duplicate it for them.
     */
    case RecipientsRefused = 'recipients_refused';

    /** An attachment file does not exist. */
    case AttachmentNotFound = 'attachment_not_found';

    /** An attachment, its display name or its media type was refused, or its path is outside the allowed directory. */
    case AttachmentRejected = 'attachment_rejected';

    /** The mailer was used without the configuration it needs. */
    case ConfigurationMissing = 'configuration_missing';
}
