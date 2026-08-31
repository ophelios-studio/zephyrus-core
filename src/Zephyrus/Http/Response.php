<?php

declare(strict_types=1);

namespace Zephyrus\Http;

use InvalidArgumentException;

final readonly class Response
{
    /**
     * RFC 9110 "token": the only characters a header FIELD NAME may contain.
     *
     * withHeader() used to accept any string, so Response::withHeader() with a
     * name taken from a request reached the SAPI verbatim: a route echoing a
     * path segment into a header name emitted "content-length: v" and let a
     * caller state a header the application never meant to send. The name is
     * lowercased for storage, so the case-insensitive charset is enough.
     */
    private const HEADER_NAME_PATTERN = "/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D";

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
        413 => 'Content Too Large',
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
            'content-type' => 'text/plain; charset=utf-8',
        ]);
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, [
            'content-type' => 'text/html; charset=utf-8',
        ]);
    }

    /**
     * @param mixed $payload Any value supported by json_encode
     */
    public static function json(mixed $payload, int $status = 200): self
    {
        return new self(
            body: (string) json_encode($payload, JSON_THROW_ON_ERROR),
            status: $status,
            headers: [
                'content-type' => 'application/json; charset=utf-8',
            ],
        );
    }

    public static function noContent(): self
    {
        return new self(body: '', status: 204, headers: []);
    }

    /**
     * Returns a redirect response. The given URL is placed in the Location
     * header and the body is empty.
     *
     * Default status is 302 Found. Common alternatives:
     *   301  Moved Permanently  -- cacheable, only safe to use for GET/HEAD.
     *   303  See Other          -- redirect-after-POST pattern.
     *   307  Temporary Redirect -- preserves request method.
     *   308  Permanent Redirect -- preserves request method, cacheable.
     */
    public static function redirect(string $url, int $status = 302): self
    {
        return new self(body: '', status: $status, headers: ['location' => $url]);
    }

    /**
     * @throws InvalidArgumentException When $name is not a valid header name.
     */
    public function withHeader(string $name, string $value): self
    {
        self::assertValidHeaderName($name);

        $headers = $this->headers;
        $headers[strtolower($name)] = $value;

        return new self(
            body: $this->body,
            status: $this->status,
            headers: $headers,
        );
    }

    /**
     * @param array<string, string> $headers
     * @throws InvalidArgumentException When any name is not a valid header name.
     */
    public function withHeaders(array $headers): self
    {
        $normalized = $this->headers;
        foreach ($headers as $name => $value) {
            self::assertValidHeaderName($name);
            $normalized[strtolower($name)] = $value;
        }

        return new self(
            body: $this->body,
            status: $this->status,
            headers: $normalized,
        );
    }

    /**
     * Refuse a header name outside the RFC 9110 token charset.
     *
     * Validated on the MUTATORS rather than in the constructor. The named
     * constructors all pass fixed names, and the constructor is the path
     * HttpExceptionResponder builds its fallback response on: a throw there
     * would replace a handled error with an unhandled one. The mutators are
     * where a name derived from outside input actually arrives.
     *
     * @throws InvalidArgumentException
     */
    private static function assertValidHeaderName(string $name): void
    {
        if (preg_match(self::HEADER_NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid HTTP header name "%s": a field name may only contain RFC 9110 token characters.',
                $name,
            ));
        }
    }

    public function withoutHeader(string $name): self
    {
        $headers = $this->headers;
        unset($headers[strtolower($name)]);

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
