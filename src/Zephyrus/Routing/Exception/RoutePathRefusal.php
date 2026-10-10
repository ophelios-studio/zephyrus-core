<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Exception;

/**
 * Why a request path, or a parameter value decoded from it, was refused before a route could match.
 */
enum RoutePathRefusal: string
{
    /** A C0 control character or DEL. */
    case ControlCharacter = 'control_character';

    case InvalidUtf8 = 'invalid_utf8';
}
