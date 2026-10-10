<?php

declare(strict_types=1);

namespace Zephyrus\Security;

/**
 * Why CsrfMiddleware refused a request.
 */
enum CsrfFailure
{
    /** The request carried no token in the body field or the header. */
    case TokenMissing;

    /** A token was sent and the token manager refused it. */
    case TokenInvalid;
}
