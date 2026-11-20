<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs Request::fromGlobals() and the kernel under PHP's built-in server, which applies post_max_size as a web server
 * SAPI does. The server starts once for the class.
 */
final class RequestPostMaxSizeTest extends TestCase
{
    private const int POST_MAX_SIZE = 65_536;

    private const int READ_LIMIT = 1_048_576;

    private const string FORM = 'application/x-www-form-urlencoded';

    /** @var resource|null */
    private static $server = null;

    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($probe);
        self::$port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $server = proc_open(
            [
                PHP_BINARY, '-d', 'post_max_size=' . self::POST_MAX_SIZE, '-d', 'display_errors=0', '-d', 'log_errors=0',
                '-S', '127.0.0.1:' . self::$port, __DIR__ . '/Fixtures/body_router.php',
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($server);
        self::$server = $server;
        // The server announces itself on stderr once it listens.
        do {
            $line = fgets($pipes[2]);
        } while ($line !== false && !str_contains($line, 'Development Server'));
        self::assertNotFalse($line, 'The built-in server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function formPostsPastPostMaxSize(): array
    {
        $form = 'title=Draft&notes=' . str_repeat('a', 100_000);
        $multipart = "--edge\r\nContent-Disposition: form-data; name=\"notes\"\r\n\r\n" . str_repeat('a', 100_000) . "\r\n--edge--\r\n";

        return [
            'a form declaring its length' => [self::FORM, $form, false],
            'a chunked form' => [self::FORM, $form, true],
            'a multipart form declaring its length' => ['multipart/form-data; boundary=edge', $multipart, false],
        ];
    }

    #[DataProvider('formPostsPastPostMaxSize')]
    public function testAFormPostPastPostMaxSizeIsTruncatedAtItAndRefused(string $contentType, string $body, bool $chunked): void
    {
        $seen = self::send('POST', '/submit?limit=' . self::READ_LIMIT, $contentType, $body, $chunked);

        self::assertSame([true, self::POST_MAX_SIZE, 413], [$seen['truncated'], $seen['readLimit'], $seen['status']]);
    }

    public function testAFormPostUnderPostMaxSizeIsReadWhole(): void
    {
        $seen = self::send('POST', '/submit?limit=' . self::READ_LIMIT, self::FORM, 'title=Draft&notes=' . str_repeat('a', 60_000));

        self::assertSame([false, ['title', 'notes'], 200], [$seen['truncated'], $seen['fields'], $seen['status']]);
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function bodiesPhpLeavesWholeInItsInput(): array
    {
        return [
            'a JSON POST' => ['POST', 'application/json'],
            'a POST without a Content-Type' => ['POST', null],
            'a form PUT' => ['PUT', self::FORM],
        ];
    }

    #[DataProvider('bodiesPhpLeavesWholeInItsInput')]
    public function testABodyPhpDoesNotParseIsReadPastPostMaxSizeUpToTheReadLimit(string $method, ?string $contentType): void
    {
        $seen = self::send($method, '/submit?limit=' . self::READ_LIMIT, $contentType, '{"notes":"' . str_repeat('a', 100_000) . '"}');

        self::assertSame([false, self::READ_LIMIT, 200], [$seen['truncated'], $seen['readLimit'], $seen['status']]);
    }

    /**
     * @return array{truncated: bool, readLimit: int, fields: list<string>, status: ?int, report: ?string}
     */
    private static function send(string $method, string $target, ?string $contentType, string $body, bool $chunked = false): array
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . self::$port, $errorCode, $errorMessage, 5);
        self::assertIsResource($socket, $errorMessage);

        $head = "{$method} {$target} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n";
        $head .= $contentType === null ? '' : "Content-Type: {$contentType}\r\n";
        if ($chunked) {
            fwrite($socket, $head . "Transfer-Encoding: chunked\r\n\r\n");
            foreach (str_split($body, 8_192) as $chunk) {
                fwrite($socket, dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n");
            }
            fwrite($socket, "0\r\n\r\n");
        } else {
            fwrite($socket, $head . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body);
        }

        $response = (string) stream_get_contents($socket);
        fclose($socket);

        /** @var array{truncated: bool, readLimit: int, fields: list<string>, status: ?int, report: ?string} */
        return json_decode(substr($response, (int) strpos($response, "\r\n\r\n") + 4), true, 512, JSON_THROW_ON_ERROR);
    }
}
