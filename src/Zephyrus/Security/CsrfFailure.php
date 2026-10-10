<?php

declare(strict_types=1);

namespace Zephyrus\Security;

/**
 * Why CsrfMiddleware refused a request.
 *
 * The failure callback receives attacker-controlled input, see CsrfMiddleware::__construct().
 */
enum CsrfFailure: string
{
    /** The request carried no token in the body field or the header. */
    case TokenMissing = 'token_missing';

    /**
     * The token manager refused the submitted token: it was forged, came from another session, or the
     * session's token was lost or rotated.
     */
    case TokenInvalid = 'token_invalid';
}
