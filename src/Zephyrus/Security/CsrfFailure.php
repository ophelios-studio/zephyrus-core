<?php

declare(strict_types=1);

namespace Zephyrus\Security;

/**
 * Why CsrfMiddleware refused a request.
 *
 * The failure callback runs on attacker-controlled input: it must answer the refusal only, never replay the
 * request or act on the account from it.
 */
enum CsrfFailure
{
    /** The request carried no token in the body field or the header. */
    case TokenMissing;

    /**
     * The token manager refused the submitted token: it was forged, came from another session, or the
     * session's token was lost or rotated.
     */
    case TokenInvalid;
}
