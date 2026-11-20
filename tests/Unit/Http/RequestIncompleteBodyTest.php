<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;

final class RequestIncompleteBodyTest extends TestCase
{
    private const FORM = ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'];

    private const MULTIPART = ['CONTENT_TYPE' => 'multipart/form-data; boundary=form-boundary'];

    private const PARTIAL_UPLOAD = ['name' => 'scan.pdf', 'type' => 'application/pdf', 'tmp_name' => '', 'error' => UPLOAD_ERR_PARTIAL, 'size' => 0];

    /**
     * @param array<string, string> $server
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    private static function request(array $server, string $raw, array $post = [], array $files = [], string $method = 'POST'): Request
    {
        return Request::fromGlobals(
            server: ['REQUEST_METHOD' => $method, 'REQUEST_URI' => '/save'] + $server,
            get: [],
            post: $post,
            cookie: [],
            files: $files,
            rawBody: $raw,
        );
    }

    /**
     * @return array<string, array{array<string, string>, string, array<string, mixed>}>
     */
    public static function bodiesShorterThanTheirDeclaredLength(): array
    {
        return [
            'a form cut inside a field' => [self::FORM + ['CONTENT_LENGTH' => '40'], 'title=Draft&items[]=1', ['title' => 'Draft', 'items' => ['1']]],
            'a form one byte short' => [self::FORM + ['CONTENT_LENGTH' => '12'], 'title=Draft', ['title' => 'Draft']],
            'a form of which nothing arrived' => [self::FORM + ['CONTENT_LENGTH' => '3015'], '', []],
            'a JSON body cut where it still decodes' => [['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '30'], '{"items":[1]}', []],
            'a multipart body PHP left unparsed' => [self::MULTIPART + ['CONTENT_LENGTH' => '300'], "--form-boundary\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nDraft", []],
        ];
    }

    /**
     * @param array<string, string> $server
     * @param array<string, mixed> $post
     */
    #[DataProvider('bodiesShorterThanTheirDeclaredLength')]
    public function testABodyShorterThanItsDeclaredLengthReadsAsEmptyAndMalformed(array $server, string $raw, array $post): void
    {
        $body = self::request($server, $raw, $post)->body();

        self::assertSame([[], '', true, false], [$body->all(), $body->raw(), $body->isMalformed(), $body->isTruncated()]);
    }

    public function testARawBodyShorterThanItsDeclaredLengthIsNotHandedToTheApplication(): void
    {
        $body = self::request(['CONTENT_TYPE' => 'text/csv', 'CONTENT_LENGTH' => '64'], "id,name\n1,Ada\n2,Gra", method: 'PUT')->body();

        self::assertSame(['', true], [$body->raw(), $body->isMalformed()]);
    }

    public function testABodyOfExactlyItsDeclaredLengthIsReadWhole(): void
    {
        $body = self::request(self::FORM + ['CONTENT_LENGTH' => '11'], 'title=Draft', ['title' => 'Draft'])->body();

        self::assertSame([['title' => 'Draft'], 'title=Draft', false], [$body->all(), $body->raw(), $body->isMalformed()]);
    }

    public function testACutFormCannotOverrideTheMethod(): void
    {
        $request = self::request(self::FORM + ['CONTENT_LENGTH' => '60'], '_method=DELETE&id=4', ['_method' => 'DELETE', 'id' => '4']);

        self::assertSame('POST', $request->method);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function multipartContentTypes(): array
    {
        return [
            'in lowercase' => ['multipart/form-data; boundary=form-boundary'],
            'in uppercase with its parameters' => ['MULTIPART/FORM-DATA;boundary=XX'],
        ];
    }

    #[DataProvider('multipartContentTypes')]
    public function testAMultipartPostParsedByPhpIsNotJudgedOnItsEmptyRawBody(string $contentType): void
    {
        $body = self::request(['CONTENT_TYPE' => $contentType, 'CONTENT_LENGTH' => '1207'], '', ['title' => 'Draft'])->body();

        self::assertSame([['title' => 'Draft'], false], [$body->all(), $body->isMalformed()]);
    }

    public function testAMultipartSentWithALowercaseMethodIsNotParsedByPhpSoItsEmptyBodyIsIncomplete(): void
    {
        $body = self::request(self::MULTIPART + ['CONTENT_LENGTH' => '1207'], '', method: 'post')->body();

        self::assertSame([true, false], [$body->isMalformed(), $body->isTruncated()]);
    }

    public function testABodySentWithAContentEncodingIsNotJudgedOnItsLength(): void
    {
        $server = self::FORM + ['CONTENT_LENGTH' => '23', 'HTTP_CONTENT_ENCODING' => 'gzip'];

        $body = self::request($server, 'a=1', ['a' => '1'])->body();

        self::assertSame([['a' => '1'], 'a=1', false], [$body->all(), $body->raw(), $body->isMalformed()]);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function uploadsEndedWithoutTheirBoundary(): array
    {
        return [
            'a single file' => [['scan' => self::PARTIAL_UPLOAD]],
            'one file of a list' => [['scans' => [
                'name' => ['a.pdf', 'b.pdf'],
                'type' => ['application/pdf', 'application/pdf'],
                'tmp_name' => ['php-upload', ''],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_PARTIAL],
                'size' => [10, 0],
            ]]],
        ];
    }

    /**
     * @param array<string, mixed> $files
     */
    #[DataProvider('uploadsEndedWithoutTheirBoundary')]
    public function testAMultipartWithAnUploadEndedWithoutItsBoundaryReadsAsEmptyAndMalformed(array $files): void
    {
        $request = self::request(self::MULTIPART + ['CONTENT_LENGTH' => '50255'], '', ['title' => 'Draft'], $files);

        self::assertSame([[], true, false], [$request->body()->all(), $request->body()->isMalformed(), $request->body()->isTruncated()]);
    }

    public function testAnUploadEndedWithoutItsBoundaryStaysListedWithItsError(): void
    {
        $request = self::request(self::MULTIPART, '', ['title' => 'Draft'], ['scan' => self::PARTIAL_UPLOAD]);

        self::assertSame(UPLOAD_ERR_PARTIAL, $request->file('scan')?->errorCode);
    }

    public function testAMultipartWithAFileTooLargeForPhpIsNotIncomplete(): void
    {
        $tooLarge = ['error' => UPLOAD_ERR_INI_SIZE] + self::PARTIAL_UPLOAD;

        $body = self::request(self::MULTIPART, '', ['title' => 'Draft'], ['scan' => $tooLarge])->body();

        self::assertSame([['title' => 'Draft'], false], [$body->all(), $body->isMalformed()]);
    }
}
