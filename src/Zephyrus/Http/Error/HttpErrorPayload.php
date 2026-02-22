<?php

declare(strict_types=1);

namespace Zephyrus\Http\Error;

final readonly class HttpErrorPayload
{
    public function __construct(
        public int $status,
        public string $message,
    ) {
    }

    /**
     * @return array{error: array{status: int, message: string}}
     */
    public function toArray(): array
    {
        return [
            'error' => [
                'status' => $this->status,
                'message' => $this->message,
            ],
        ];
    }
}
