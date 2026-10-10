<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Exception;
use Throwable;

/**
 * Internal marker for an exception that an application's exception handler
 * deliberately rethrew.
 *
 * Rethrowing the bare exception would reach pipe()'s backstop and run the
 * exception handler a second time, so it travels in this wrapper instead.
 *
 * @internal Never escapes HttpKernel: handle() unwraps it and throws the original.
 */
final class KernelRethrowSignal extends Exception
{
    public function __construct(public readonly Throwable $original)
    {
        parent::__construct($original->getMessage(), (int) $original->getCode(), $original);
    }
}
