<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;
use Zephyrus\Uploader\UploadedFile;

final class RequestTest extends TestCase
{
    // -------------------------------------------------------------------------
    // fromArray — existing contract tests
    // -------------------------------------------------------------------------

    public function testFactoryNormalizesMethodAndHeaders(): void
    {
        $request = Request::fromArray(
            method: 'post',
            uri: '/users',
            headers: ['Content-Type' => 'application/json'],
        );

        self::assertSame('POST', $request->method);
        self::assertSame('application/json', $request->header('content-type'));
    }

    public function testQueryAndInputHelpersReturnDefaultWhenMissing(): void
    {
        $request = Request::fromArray(
            method: 'GET',
            uri: '/search',
            query: ['q' => 'zephyrus'],
            parsedBody: ['name' => 'molt'],
        );

        self::assertSame('zephyrus', $request->query('q'));
        self::assertSame('fallback', $request->query('missing', 'fallback'));

        self::assertSame('molt', $request->input('name'));
        self::assertNull($request->input('missing'));
    }

    public function testPathAndMethodHelpers(): void
    {
        $request = Request::fromArray(
            method: 'post',
            uri: '/users/42?expand=roles',
        );

        self::assertSame('/users/42', $request->path());
        self::assertTrue($request->isMethod('POST'));
        self::assertFalse($request->isMethod('GET'));
    }

    public function testAttributeHelpersAreImmutable(): void
    {
        $request = Request::fromArray('GET', '/users', attributes: ['tenant' => 'acme']);
        $updated = $request->withAttribute('userId', '42');

        self::assertSame('acme', $request->attribute('tenant'));
        self::assertNull($request->attribute('userId'));

        self::assertSame('acme', $updated->attribute('tenant'));
        self::assertSame('42', $updated->attribute('userId'));
    }

    public function testFromArrayCookiesAreAccessible(): void
    {
        $request = Request::fromArray(
            method: 'GET',
            uri: '/dashboard',
            cookies: ['session' => 'abc123', 'theme' => 'dark'],
        );

        self::assertSame('abc123', $request->cookie('session'));
        self::assertSame('dark', $request->cookie('theme'));
        self::assertNull($request->cookie('missing'));
        self::assertSame('fallback', $request->cookie('missing', 'fallback'));
    }

    public function testWithAttributesPreservesCookies(): void
    {
        $request = Request::fromArray(
            method: 'GET',
            uri: '/page',
            cookies: ['token' => 'xyz'],
        );

        $updated = $request->withAttributes(['role' => 'admin']);

        self::assertSame('xyz', $updated->cookie('token'));
        self::assertSame('admin', $updated->attribute('role'));
    }

    public function testWithAttributePreservesCookies(): void
    {
        $request = Request::fromArray(
            method: 'GET',
            uri: '/page',
            cookies: ['lang' => 'fr'],
        );

        $updated = $request->withAttribute('userId', 7);

        self::assertSame('fr', $updated->cookie('lang'));
        self::assertSame(7, $updated->attribute('userId'));
    }

    public function testFromArrayProvidesUploadedFileHelper(): void
    {
        $file = new UploadedFile('avatar', 'me.png', 'image/png', '/tmp/phpA', 123);
        $request = Request::fromArray('POST', '/profile', files: ['avatar' => $file]);

        self::assertSame($file, $request->file('avatar'));
        self::assertNull($request->file('missing'));
    }

    // -------------------------------------------------------------------------
    // isJson + isSecure helpers
    // -------------------------------------------------------------------------

    public function testIsJsonReturnsTrueForJsonContentType(): void
    {
        $request = Request::fromArray(
            method: 'POST',
            uri: '/api/items',
            headers: ['Content-Type' => 'application/json; charset=utf-8'],
        );

        self::assertTrue($request->isJson());
    }

    public function testIsJsonReturnsFalseForFormContentType(): void
    {
        $request = Request::fromArray(
            method: 'POST',
            uri: '/form',
            headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
        );

        self::assertFalse($request->isJson());
    }

    public function testIsSecureDetectsHttpsUri(): void
    {
        $secure  = Request::fromArray('GET', 'https://example.com/page');
        $plain   = Request::fromArray('GET', 'http://example.com/page');

        self::assertTrue($secure->isSecure());
        self::assertFalse($plain->isSecure());
    }

    // -------------------------------------------------------------------------
    // fromGlobals — URI construction
    // -------------------------------------------------------------------------

    public function testFromGlobalsBuildsHttpUri(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/hello?foo=bar',
            ],
            get: ['foo' => 'bar'],
        );

        self::assertSame('http://example.com/hello?foo=bar', $request->uri);
        self::assertSame('/hello', $request->path());
        self::assertSame('bar', $request->query('foo'));
    }

    public function testFromGlobalsBuildsHttpsUriWhenHttpsKeyIsOn(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTPS'          => 'on',
                'HTTP_HOST'      => 'secure.example.com',
                'REQUEST_URI'    => '/dashboard',
            ],
        );

        self::assertSame('https://secure.example.com/dashboard', $request->uri);
        self::assertTrue($request->isSecure());
    }

    public function testFromGlobalsHonorsForwardedProtoAndHost(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'         => 'GET',
                'HTTP_HOST'              => 'app.internal',
                'REQUEST_URI'            => '/reports',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_HOST'  => 'public.example.com',
            ],
        );

        self::assertSame('https://public.example.com/reports', $request->uri);
        self::assertTrue($request->isSecure());
    }

    public function testFromGlobalsForwardedHeaderTakesPriority(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'         => 'GET',
                'HTTP_HOST'              => 'app.internal',
                'REQUEST_URI'            => '/api',
                'HTTP_FORWARDED'         => 'for=1.2.3.4;proto=https;host=api.example.com',
                'HTTP_X_FORWARDED_HOST'  => 'ignored.example.com',
                'HTTP_X_FORWARDED_PROTO' => 'http',
            ],
        );

        self::assertSame('https://api.example.com/api', $request->uri);
    }

    public function testFromGlobalsHttpsKeyOf1AlsoTriggersSecure(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTPS'          => '1',
                'HTTP_HOST'      => 'secure.example.com',
                'REQUEST_URI'    => '/',
            ],
        );

        self::assertTrue($request->isSecure());
    }

    public function testFromGlobalsHttpsOffKeyIsNotSecure(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTPS'          => 'off',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/',
            ],
        );

        self::assertFalse($request->isSecure());
    }

    public function testFromGlobalsFallsBackToServerNameWhenHostMissing(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'SERVER_NAME'    => 'internal.host',
                'REQUEST_URI'    => '/probe',
            ],
        );

        self::assertSame('http://internal.host/probe', $request->uri);
    }

    public function testFromGlobalsDefaultsToLocalhostAndSlashWhenMinimal(): void
    {
        $request = Request::fromGlobals(server: ['REQUEST_METHOD' => 'GET']);

        self::assertSame('http://localhost/', $request->uri);
    }

    public function testFromGlobalsIncludesNonDefaultServerPortInUri(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'SERVER_NAME'    => 'localhost',
                'SERVER_PORT'    => '8080',
                'REQUEST_URI'    => '/health',
            ],
        );

        self::assertSame('http://localhost:8080/health', $request->uri);
    }

    public function testFromGlobalsOmitsDefaultHttpsPortFromUri(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'SERVER_NAME'    => 'secure.example.com',
                'SERVER_PORT'    => '443',
                'HTTPS'          => 'on',
                'REQUEST_URI'    => '/health',
            ],
        );

        self::assertSame('https://secure.example.com/health', $request->uri);
    }

    public function testFromGlobalsDefaultsMethodToGetWhenAbsent(): void
    {
        $request = Request::fromGlobals(server: []);

        self::assertSame('GET', $request->method);
    }

    // -------------------------------------------------------------------------
    // fromGlobals — header extraction
    // -------------------------------------------------------------------------

    public function testFromGlobalsExtractsHttpPrefixedHeaders(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'      => 'GET',
                'HTTP_HOST'           => 'example.com',
                'REQUEST_URI'         => '/',
                'HTTP_ACCEPT'         => 'application/json',
                'HTTP_X_REQUEST_ID'   => 'abc-123',
                'HTTP_AUTHORIZATION'  => 'Bearer token',
            ],
        );

        self::assertSame('application/json', $request->header('accept'));
        self::assertSame('abc-123', $request->header('x-request-id'));
        self::assertSame('Bearer token', $request->header('authorization'));
    }

    public function testFromGlobalsExtractsContentTypeWithoutHttpPrefix(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/upload',
                'CONTENT_TYPE'   => 'multipart/form-data',
                'CONTENT_LENGTH' => '1024',
            ],
        );

        self::assertSame('multipart/form-data', $request->header('content-type'));
        self::assertSame('1024', $request->header('content-length'));
    }

    public function testFromGlobalsHeaderLookupIsCaseInsensitive(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'   => 'GET',
                'HTTP_HOST'        => 'example.com',
                'REQUEST_URI'      => '/',
                'HTTP_X_CUSTOM_HDR' => 'value',
            ],
        );

        self::assertSame('value', $request->header('X-Custom-Hdr'));
        self::assertSame('value', $request->header('x-custom-hdr'));
    }

    public function testFromGlobalsEmptyContentTypeIsNotExtracted(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/',
                'CONTENT_TYPE'   => '',
            ],
        );

        self::assertNull($request->header('content-type'));
    }

    // -------------------------------------------------------------------------
    // fromGlobals — body parsing
    // -------------------------------------------------------------------------

    public function testFromGlobalsJsonBodyParsedFromRawBody(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'api.example.com',
                'REQUEST_URI'    => '/items',
                'CONTENT_TYPE'   => 'application/json',
            ],
            rawBody: '{"title":"Widget","price":9.99}',
        );

        self::assertTrue($request->isJson());
        self::assertSame('Widget', $request->input('title'));
        self::assertSame(9.99, $request->input('price'));
    }

    public function testFromGlobalsJsonBodyWithCharsetParameterParsed(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'PUT',
                'HTTP_HOST'      => 'api.example.com',
                'REQUEST_URI'    => '/items/1',
                'CONTENT_TYPE'   => 'application/json; charset=utf-8',
            ],
            rawBody: '{"status":"active"}',
        );

        self::assertSame('active', $request->input('status'));
    }

    public function testFromGlobalsEmptyJsonBodyReturnsEmptyParsedBody(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'api.example.com',
                'REQUEST_URI'    => '/items',
                'CONTENT_TYPE'   => 'application/json',
            ],
            rawBody: '',
        );

        self::assertSame([], $request->parsedBody);
    }

    public function testFromGlobalsInvalidJsonBodyReturnsEmptyParsedBody(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'api.example.com',
                'REQUEST_URI'    => '/items',
                'CONTENT_TYPE'   => 'application/json',
            ],
            rawBody: '{"title":"broken"',
        );

        self::assertSame([], $request->parsedBody);
    }

    public function testFromGlobalsFormBodyUsesPostArray(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/login',
                'CONTENT_TYPE'   => 'application/x-www-form-urlencoded',
            ],
            post: ['username' => 'alice', 'password' => 's3cr3t'],
        );

        self::assertSame('alice', $request->input('username'));
        self::assertSame('s3cr3t', $request->input('password'));
    }

    public function testFromGlobalsMultipartFormUsesPostArray(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/upload',
                'CONTENT_TYPE'   => 'multipart/form-data; boundary=----xyz',
            ],
            post: ['field' => 'value'],
        );

        self::assertSame('value', $request->input('field'));
    }

    public function testFromGlobalsGetRequestHasNoParsedBody(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/search',
            ],
            post: ['should' => 'be ignored'],
        );

        self::assertSame([], $request->parsedBody);
    }

    public function testFromGlobalsHeadRequestHasNoParsedBody(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'HEAD',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/ping',
            ],
            post: ['ignored' => 'yes'],
        );

        self::assertSame([], $request->parsedBody);
    }

    // -------------------------------------------------------------------------
    // fromGlobals — method override
    // -------------------------------------------------------------------------

    public function testFromGlobalsMethodOverrideViaPostField(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/items/5',
                'CONTENT_TYPE'   => 'application/x-www-form-urlencoded',
            ],
            post: ['_method' => 'DELETE', 'confirm' => '1'],
        );

        self::assertSame('DELETE', $request->method);
        // Override field stays in parsedBody (controller may inspect it)
        self::assertSame('DELETE', $request->input('_method'));
    }

    public function testFromGlobalsMethodOverrideViaPutPostField(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/items/5',
                'CONTENT_TYPE'   => 'application/x-www-form-urlencoded',
            ],
            post: ['_method' => 'put', 'name' => 'Widget'],
        );

        self::assertSame('PUT', $request->method);
    }

    public function testFromGlobalsMethodOverrideViaHeader(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'          => 'POST',
                'HTTP_HOST'               => 'api.example.com',
                'REQUEST_URI'             => '/items/3',
                'CONTENT_TYPE'            => 'application/json',
                'HTTP_X_HTTP_METHOD_OVERRIDE' => 'PATCH',
            ],
            rawBody: '{"status":"closed"}',
        );

        self::assertSame('PATCH', $request->method);
    }

    public function testFromGlobalsHeaderOverrideTakesPriorityOverFieldOverride(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'              => 'POST',
                'HTTP_HOST'                   => 'example.com',
                'REQUEST_URI'                 => '/items/1',
                'CONTENT_TYPE'                => 'application/x-www-form-urlencoded',
                'HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE',
            ],
            post: ['_method' => 'PATCH'],
        );

        // Header wins over body field
        self::assertSame('DELETE', $request->method);
    }

    public function testFromGlobalsIgnoresUnsupportedMethodOverride(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'              => 'POST',
                'HTTP_HOST'                   => 'example.com',
                'REQUEST_URI'                 => '/items/1',
                'CONTENT_TYPE'                => 'application/x-www-form-urlencoded',
                'HTTP_X_HTTP_METHOD_OVERRIDE' => 'TRACE',
            ],
            post: ['_method' => 'OPTIONS'],
        );

        self::assertSame('POST', $request->method);
    }

    public function testFromGlobalsMethodOverrideIgnoredForNonPostRequests(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'              => 'GET',
                'HTTP_HOST'                   => 'example.com',
                'REQUEST_URI'                 => '/',
                'HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE',
            ],
        );

        self::assertSame('GET', $request->method);
    }

    // -------------------------------------------------------------------------
    // fromGlobals — cookies
    // -------------------------------------------------------------------------

    public function testFromGlobalsCookiesAreAvailable(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/profile',
            ],
            cookie: ['session_id' => 'xyz789', 'pref_lang' => 'en'],
        );

        self::assertSame('xyz789', $request->cookie('session_id'));
        self::assertSame('en', $request->cookie('pref_lang'));
        self::assertNull($request->cookie('nonexistent'));
    }

    public function testFromGlobalsNormalizesSingleFileUpload(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST' => 'example.com',
                'REQUEST_URI' => '/upload',
            ],
            files: [
                'avatar' => [
                    'name' => 'my-photo.JPG',
                    'type' => 'image/jpeg',
                    'tmp_name' => '/tmp/php-upload',
                    'error' => UPLOAD_ERR_OK,
                    'size' => 1024,
                ],
            ],
        );

        $file = $request->file('avatar');

        self::assertInstanceOf(UploadedFile::class, $file);
        self::assertSame('my-photo.JPG', $file->originalName);
        self::assertSame('jpg', $file->clientExtension());
        self::assertSame('/tmp/php-upload', $file->tmpPath);
    }

    public function testFromGlobalsSkipsMultiFileUploadArrayShape(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST' => 'example.com',
                'REQUEST_URI' => '/upload',
            ],
            files: [
                'photos' => [
                    'name' => ['a.jpg', 'b.jpg'],
                    'type' => ['image/jpeg', 'image/jpeg'],
                    'tmp_name' => ['/tmp/a', '/tmp/b'],
                    'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                    'size' => [100, 200],
                ],
            ],
        );

        self::assertNull($request->file('photos'));
    }

    // -------------------------------------------------------------------------
    // fromGlobals — query string
    // -------------------------------------------------------------------------

    public function testFromGlobalsPopulatesQueryFromGetArray(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/search?q=zephyrus&page=2',
            ],
            get: ['q' => 'zephyrus', 'page' => '2'],
        );

        self::assertSame('zephyrus', $request->query('q'));
        self::assertSame('2', $request->query('page'));
        self::assertNull($request->query('missing'));
    }

    // -------------------------------------------------------------------------
    // fromGlobals — absolute REQUEST_URI (reverse proxy)
    // -------------------------------------------------------------------------

    public function testFromGlobalsPassesThroughAbsoluteRequestUri(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'proxy.internal',
                'REQUEST_URI'    => 'https://real.example.com/api/v1/users',
            ],
        );

        self::assertSame('https://real.example.com/api/v1/users', $request->uri);
    }

    // -------------------------------------------------------------------------
    // fromGlobals — delete / patch with JSON body
    // -------------------------------------------------------------------------

    public function testFromGlobalsDeleteWithJsonBodyIsParsed(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'DELETE',
                'HTTP_HOST'      => 'api.example.com',
                'REQUEST_URI'    => '/items/bulk',
                'CONTENT_TYPE'   => 'application/json',
            ],
            rawBody: '{"ids":[1,2,3]}',
        );

        self::assertSame('DELETE', $request->method);
        self::assertSame([1, 2, 3], $request->input('ids'));
    }

    public function testFromGlobalsPatchWithJsonBodyIsParsed(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'PATCH',
                'HTTP_HOST'      => 'api.example.com',
                'REQUEST_URI'    => '/items/7',
                'CONTENT_TYPE'   => 'application/json',
            ],
            rawBody: '{"active":true,"score":4.5}',
        );

        self::assertSame(true, $request->input('active'));
        self::assertSame(4.5, $request->input('score'));
    }
}
