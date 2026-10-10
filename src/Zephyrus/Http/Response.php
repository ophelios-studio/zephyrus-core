<?php

declare(strict_types=1);

namespace Zephyrus\Http;

use InvalidArgumentException;

final readonly class Response
{
    /** RFC 9110 token: the characters a header field name may contain. */
    private const HEADER_NAME_PATTERN = "/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D";

    /**
     * A single leading "/" (not "//"), then no backslash and no ASCII control character. Browsers read "/\" like "//",
     * another host.
     */
    private const LOCAL_PATH_PATTERN = '#^/(?![/\\\\])[^\x00-\x1F\x7F\\\\]*+$#D';

    /** RFC 9110 allows HTAB as the only control character in a field value. */
    private const HEADER_VALUE_FORBIDDEN_PATTERN = '/[\x00-\x08\x0A-\x1F\x7F]/D';

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

    /** @var array<string, string> Header names are stored lowercased. */
    public array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        array $headers = [],
    ) {
        $this->headers = array_change_key_case($headers);
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
     * Redirects to $url with an empty body. Never pass user input here: use localRedirect().
     *
     * Default status 302. 301 and 308 are cacheable, 303 suits redirect-after-POST, 307 and 308 keep the method.
     *
     * @throws InvalidArgumentException When $url holds a control character other than HTAB.
     */
    public static function redirect(string $url, int $status = 302): self
    {
        self::assertValidHeaderValue('location', $url);

        return new self(body: '', status: $status, headers: ['location' => $url]);
    }

    /**
     * Redirects to $target when it is a local path, otherwise to $fallback. Use it for targets read from a request.
     *
     * A local path starts with a single "/", has no backslash and no ASCII control character. A non-string $target
     * falls back too. A percent-encoded CRLF stays local: it is inert in a Location header.
     *
     * @throws InvalidArgumentException When $fallback is not a local path.
     */
    public static function localRedirect(mixed $target, string $fallback = '/', int $status = 302): self
    {
        if (preg_match(self::LOCAL_PATH_PATTERN, $fallback) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'The redirect fallback "%s" must be a local path starting with a single "/".',
                $fallback,
            ));
        }

        $isLocal = is_string($target) && preg_match(self::LOCAL_PATH_PATTERN, $target) === 1;
        $location = $isLocal ? $target : $fallback;

        return self::redirect($location, $status);
    }

    /**
     * Returns a copy with the header set. The name and the value are validated.
     *
     * @throws InvalidArgumentException When $name is not a valid header name.
     * @throws InvalidArgumentException When $value holds a control character other than HTAB.
     */
    public function withHeader(string $name, string $value): self
    {
        self::assertValidHeaderName($name);
        self::assertValidHeaderValue($name, $value);

        $headers = $this->headers;
        $headers[strtolower($name)] = $value;

        return new self(
            body: $this->body,
            status: $this->status,
            headers: $headers,
        );
    }

    /**
     * Returns a copy with the headers set, as withHeader() does.
     *
     * @param array<string, string> $headers
     * @throws InvalidArgumentException When any name is not a valid header name.
     * @throws InvalidArgumentException When any value holds a control character other than HTAB.
     */
    public function withHeaders(array $headers): self
    {
        $normalized = $this->headers;
        foreach ($headers as $name => $value) {
            self::assertValidHeaderName($name);
            self::assertValidHeaderValue($name, $value);
            $normalized[strtolower($name)] = $value;
        }

        return new self(
            body: $this->body,
            status: $this->status,
            headers: $normalized,
        );
    }

    /**
     * Refuses a name outside the RFC 9110 token charset. Not checked in the constructor, which error responses use.
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

    /**
     * Refuses a control character other than HTAB. Not checked in the constructor, which error responses use.
     *
     * @throws InvalidArgumentException
     */
    private static function assertValidHeaderValue(string $name, string $value): void
    {
        if (preg_match(self::HEADER_VALUE_FORBIDDEN_PATTERN, $value) === 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid value for HTTP header "%s": it contains a control character other than HTAB.',
                $name,
            ));
        }
    }

    /** Whether a header is present, whatever its value. The name is matched case-insensitively. */
    public function hasHeader(string $name): bool
    {
        return $this->getHeader($name) !== null;
    }

    /** The stored value of a header, or null when it is absent. The name is matched case-insensitively. */
    public function getHeader(string $name): ?string
    {
        foreach ($this->headers as $existing => $value) {
            if (strcasecmp((string) $existing, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** Whether a header is present with a non-blank value. A blank value counts as absent. */
    public function hasNonBlankHeader(string $name): bool
    {
        return trim($this->getHeader($name) ?? '') !== '';
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

    /** The reason phrase of the status code, or 'Unknown Status'. */
    public function statusPhrase(): string
    {
        return self::STATUS_PHRASES[$this->status] ?? 'Unknown Status';
    }

    /** The HTTP/1.1 status line, e.g. "HTTP/1.1 200 OK". */
    public function toStatusLine(): string
    {
        return sprintf('HTTP/1.1 %d %s', $this->status, $this->statusPhrase());
    }

    /**
     * The header lines send() emits, e.g. "X-Trace-Id: abc". Does not touch the SAPI.
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

    /** Emits the status line, headers and body to the SAPI. Headers are skipped when already sent. */
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
