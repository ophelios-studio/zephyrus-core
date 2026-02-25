<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

final class AuthGuardMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthGuardInterface $guard,
        private readonly int $status = 401,
        private readonly string $message = 'Unauthorized',
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        if (!$this->guard->isAuthorized($request)) {
            return Response::json(['error' => $this->message], $this->status);
        }

        /** @var Response */
        return $next($request);
    }
}
