<?php

declare(strict_types=1);

namespace Zephyrus\Http;

final readonly class Response
{
    private const STATUS_PHRASES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        206 => 'Partial Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        409 => 'Conflict',
        410 => 'Gone',
        415 => 'Unsupported Media Type',
        422 => 'Unprocessable Content',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
    ];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    /**
     * @param array<mixed> $payload
     */
    public static function json(array $payload, int $status = 200): self
    {
        return new self(
            body: (string) json_encode($payload, JSON_THROW_ON_ERROR),
            status: $status,
            headers: [
                'Content-Type' => 'application/json; charset=utf-8',
            ],
        );
    }

    public static function noContent(): self
    {
        return new self(body: '', status: 204, headers: []);
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[$name] = $value;

        return new self(
            body: $this->body,
            status: $this->status,
            headers: $headers,
        );
    }

    public function withStatus(int $status): self
    {
        return new self(
            body: $this->body,
            status: $status,
            headers: $this->headers,
        );
    }

    /**
     * Returns the standard HTTP reason phrase for this response's status code.
     * Returns 'Unknown Status' for unrecognized codes.
     */
    public function statusPhrase(): string
    {
        return self::STATUS_PHRASES[$this->status] ?? 'Unknown Status';
    }

    /**
     * Returns the formatted HTTP/1.1 status line, e.g. "HTTP/1.1 200 OK".
     */
    public function toStatusLine(): string
    {
        return sprintf('HTTP/1.1 %d %s', $this->status, $this->statusPhrase());
    }

    /**
     * Returns the formatted header lines that send() will emit, e.g.
     * ["Content-Type: application/json; charset=utf-8", "X-Trace-Id: abc"].
     * Useful for inspection and testing without touching the SAPI.
     *
     * @return string[]
     */
    public function toHeaderLines(): array
    {
        $lines = [];
        foreach ($this->headers as $name => $value) {
            $lines[] = sprintf('%s: %s', $name, $value);
        }

        return $lines;
    }

    /**
     * Emits the response to the SAPI: status line, all headers, then body.
     * Skips header emission if headers have already been sent.
     *
     * Typical entry-point usage:
     *
     *     $request  = Request::fromGlobals();
     *     $response = $kernel->handle($request);
     *     $response->send();
     */
    public function send(): void
    {
        if (!headers_sent()) {
            header($this->toStatusLine(), true, $this->status);
            foreach ($this->toHeaderLines() as $line) {
                header($line);
            }
        }

        echo $this->body;
    }
}
