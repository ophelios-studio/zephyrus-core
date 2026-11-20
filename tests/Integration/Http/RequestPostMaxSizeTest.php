<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs Request::fromGlobals() and the kernel under PHP's built-in server, which applies post_max_size as a web server
 * SAPI does. Each server starts once for the class, on first use.
 */
final class RequestPostMaxSizeTest extends TestCase
{
    private const int POST_MAX_SIZE = 65_536;

    private const int READ_LIMIT = 1_048_576;

    private const string FORM = 'application/x-www-form-urlencoded';

    /** @var array<string, array{resource, int}> enable_post_data_reading => [process, port] */
    private static array $servers = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$servers as [$process]) {
            proc_terminate($process);
            proc_close($process);
        }
        self::$servers = [];
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
            'an uppercase form with parameters' => ['Application/X-WWW-Form-Urlencoded;charset=UTF-8', $form, false],
            'an uppercase multipart with parameters' => ['MULTIPART/FORM-DATA;boundary=edge', $multipart, false],
            'a form followed by a second type after a comma' => [self::FORM . ',text/plain', $form, false],
            'a form followed by a parameter after a space' => [self::FORM . ' charset=x', $form, false],
            'a multipart followed by a parameter after a space' => ['multipart/form-data boundary=edge', $multipart, false],
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

    public function testAFormPostIsReadPastPostMaxSizeWhenPostDataReadingIsOff(): void
    {
        $seen = self::send('POST', '/submit?limit=' . self::READ_LIMIT, self::FORM, 'notes=' . str_repeat('a', 100_000), enablePostDataReading: '"Off"');

        self::assertSame([false, self::READ_LIMIT, 200], [$seen['truncated'], $seen['readLimit'], $seen['status']]);
    }

    public function testAFormPostIsTruncatedAtPostMaxSizeWhenPostDataReadingIsOnInAnyCase(): void
    {
        $seen = self::send('POST', '/submit?limit=' . self::READ_LIMIT, self::FORM, 'notes=' . str_repeat('a', 100_000), enablePostDataReading: '"On"');

        self::assertSame([true, self::POST_MAX_SIZE, 413], [$seen['truncated'], $seen['readLimit'], $seen['status']]);
    }

    public function testAFormPostCutAtAPostMaxSizeBelowTheConfiguredLimitIsReportedWithItsFix(): void
    {
        $query = '/submit?limit=' . self::READ_LIMIT . '&configured=' . self::READ_LIMIT;

        $seen = self::send('POST', $query, self::FORM, 'notes=' . str_repeat('a', 100_000));

        self::assertSame(
            "PHP's post_max_size (65536 bytes) is below security.maxBodySize (1048576): PHP drops or cuts the fields and "
                . 'files of a form POST past it before the application runs. Raise post_max_size to at least '
                . 'security.maxBodySize.',
            $seen['report'],
        );
    }

    public function testAFormPostCutAtPostMaxSizeUnderAnUnlimitedConfigurationIsReportedWithItsFix(): void
    {
        $seen = self::send('POST', '/submit?limit=0&configured=0', self::FORM, 'notes=' . str_repeat('a', 100_000));

        self::assertSame(
            "PHP's post_max_size (65536 bytes) bounds a form POST, but security.maxBodySize is 0 (unlimited): PHP drops "
                . 'or cuts the fields and files of a form POST past it before the application runs. Set '
                . 'security.maxBodySize to the largest body the application accepts and post_max_size to at least that.',
            $seen['report'],
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function methodOverrideHeaders(): array
    {
        return [
            'a lowercase override' => ['put'],
            'an override followed by whitespace' => ['PUT '],
        ];
    }

    #[DataProvider('methodOverrideHeaders')]
    public function testAFormPostCutAtPostMaxSizeUnderAMethodOverrideHeaderIsReportedWithPostMaxSize(string $override): void
    {
        $query = '/submit?limit=' . self::READ_LIMIT . '&configured=' . self::READ_LIMIT;

        $seen = self::send('POST', $query, self::FORM, 'notes=' . str_repeat('a', 100_000), headers: ['X-HTTP-Method-Override' => $override]);

        self::assertSame(
            "PHP's post_max_size (65536 bytes) is below security.maxBodySize (1048576): PHP drops or cuts the fields and "
                . 'files of a form POST past it before the application runs. Raise post_max_size to at least '
                . 'security.maxBodySize.',
            $seen['report'],
        );
    }

    public function testABodyCutAtAReadLimitBelowPostMaxSizeKeepsTheReadLimitReport(): void
    {
        $seen = self::send('POST', '/submit?limit=1000&configured=' . self::READ_LIMIT, self::FORM, 'notes=' . str_repeat('a', 2_000));

        self::assertSame(
            'Request::fromGlobals() read the body with a limit of 1000 bytes, but security.maxBodySize allows 1048576: '
                . 'pass maxBodySize: $config->security->maxBodySize to Request::fromGlobals().',
            $seen['report'],
        );
    }

    public function testAFormPutCutAtALimitEqualToPostMaxSizeKeepsTheReadLimitReport(): void
    {
        $query = '/submit?limit=' . self::POST_MAX_SIZE . '&configured=' . self::READ_LIMIT;

        $seen = self::send('PUT', $query, self::FORM, 'notes=' . str_repeat('a', 100_000));

        self::assertSame(
            'Request::fromGlobals() read the body with a limit of 65536 bytes, but security.maxBodySize allows 1048576: '
                . 'pass maxBodySize: $config->security->maxBodySize to Request::fromGlobals().',
            $seen['report'],
        );
    }

    /**
     * @param array<string, string> $headers
     * @return array{truncated: bool, readLimit: int, fields: list<string>, status: ?int, report: ?string}
     */
    private static function send(
        string $method,
        string $target,
        ?string $contentType,
        string $body,
        bool $chunked = false,
        string $enablePostDataReading = '1',
        array $headers = [],
    ): array {
        $socket = stream_socket_client('tcp://127.0.0.1:' . self::port($enablePostDataReading), $errorCode, $errorMessage, 5);
        self::assertIsResource($socket, $errorMessage);

        $head = "{$method} {$target} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n";
        $head .= $contentType === null ? '' : "Content-Type: {$contentType}\r\n";
        foreach ($headers as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }
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

        if (PHP_VERSION_ID >= 80600 && str_starts_with($response, 'HTTP/1.1 413 ') && str_contains($response, '<html')) {
            self::markTestSkipped("PHP 8.6's built-in server refuses a body past post_max_size before the script runs; the 8.4 and 8.5 jobs cover this path");
        }

        /** @var array{truncated: bool, readLimit: int, fields: list<string>, status: ?int, report: ?string} */
        return json_decode(substr($response, (int) strpos($response, "\r\n\r\n") + 4), true, 512, JSON_THROW_ON_ERROR);
    }

    /** The port of the server running with this enable_post_data_reading, started on first use. */
    private static function port(string $enablePostDataReading): int
    {
        if (isset(self::$servers[$enablePostDataReading])) {
            return self::$servers[$enablePostDataReading][1];
        }

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($probe);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $process = proc_open(
            [
                PHP_BINARY, '-d', 'post_max_size=' . self::POST_MAX_SIZE, '-d', 'enable_post_data_reading=' . $enablePostDataReading,
                '-d', 'display_errors=0', '-d', 'log_errors=0', '-S', '127.0.0.1:' . $port, __DIR__ . '/Fixtures/body_router.php',
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        self::$servers[$enablePostDataReading] = [$process, $port];
        // The server announces itself on stderr once it listens.
        do {
            $line = fgets($pipes[2]);
        } while ($line !== false && !str_contains($line, 'Development Server'));
        self::assertNotFalse($line, 'The built-in server did not start.');

        return $port;
    }
}
