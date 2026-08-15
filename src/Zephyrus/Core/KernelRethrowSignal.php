<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Exception;
use Throwable;

/**
 * Internal marker carrying an exception that an application DELIBERATELY
 * rethrew, out through the kernel without being converted again.
 *
 * @internal This never escapes HttpKernel. handle() unwraps it and throws the
 *   original, so an application only ever sees its own exception.
 *
 * ## Why a wrapper rather than rethrowing the exception itself
 *
 * The conversion happens inside the global middleware pipeline, and pipe() has
 * a backstop that converts anything escaping that pipeline. Rethrowing the bare
 * exception therefore lands straight back in that backstop, which calls the
 * exception responder a SECOND time: the application's handler runs twice, and
 * the reporting seam fires again with a misleading source. Measured on the
 * plain rethrow: the handler was invoked twice and the second ExceptionEvent
 * was labelled "middleware" for what was actually a routing failure.
 *
 * Wrapping makes the intent explicit and lets the backstop pass it straight
 * through, so the handler runs once, ExceptionEvent fires once, and the
 * original reaches the application's debugger untouched.
 */
final class KernelRethrowSignal extends Exception
{
    public function __construct(public readonly Throwable $original)
    {
        parent::__construct($original->getMessage(), (int) $original->getCode(), $original);
    }
}
