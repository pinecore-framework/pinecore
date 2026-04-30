<?php

namespace Tests\Pronchev\Pinecore\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pronchev\Pinecore\Http\HttpException;
use Pronchev\Pinecore\Http\Request;

final class RequestTest extends TestCase
{
    #[Test]
    public function jsonObjectBodyIsParsed(): void
    {
        $request = self::buildRequest(
            method: 'POST',
            contentType: 'application/json',
            rawBody: '{"email":"a@b.c","name":"Alice"}',
        );

        self::assertSame(['email' => 'a@b.c', 'name' => 'Alice'], $request->body());
    }

    #[Test]
    public function jsonTopLevelListIsAccepted(): void
    {
        $request = self::buildRequest(
            method: 'POST',
            contentType: 'application/json',
            rawBody: '[1,2,3]',
        );

        self::assertSame([1, 2, 3], $request->body());
    }

    #[Test]
    public function jsonScalarBodyThrowsBadRequest(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessage('JSON body must be an object or array');

        self::buildRequest(
            method: 'POST',
            contentType: 'application/json',
            rawBody: '"hello"',
        );
    }

    #[Test]
    public function jsonNullBodyThrowsBadRequest(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessage('JSON body must be an object or array');

        self::buildRequest(
            method: 'POST',
            contentType: 'application/json',
            rawBody: 'null',
        );
    }

    #[Test]
    public function malformedJsonThrowsBadRequest(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessage('Invalid JSON body');

        self::buildRequest(
            method: 'POST',
            contentType: 'application/json',
            rawBody: '{not json}',
        );
    }

    #[Test]
    public function jsonContentTypeWithCharsetIsParsed(): void
    {
        $request = self::buildRequest(
            method: 'POST',
            contentType: 'application/json; charset=utf-8',
            rawBody: '{"x":1}',
        );

        self::assertSame(['x' => 1], $request->body());
    }

    #[Test]
    public function emptyJsonBodyYieldsEmptyArray(): void
    {
        $request = self::buildRequest(
            method: 'POST',
            contentType: 'application/json',
            rawBody: '',
        );

        self::assertSame([], $request->body());
    }

    #[Test]
    public function urlencodedPostBodyComesFromPost(): void
    {
        $request = self::buildRequest(
            method: 'POST',
            contentType: 'application/x-www-form-urlencoded',
            rawBody: 'ignored=because-php-already-parsed-into-post',
            post: ['email' => 'a@b.c'],
        );

        self::assertSame(['email' => 'a@b.c'], $request->body());
    }

    #[Test]
    public function urlencodedPutBodyIsParsedFromRawBody(): void
    {
        $request = self::buildRequest(
            method: 'PUT',
            contentType: 'application/x-www-form-urlencoded',
            rawBody: 'email=a%40b.c&name=Alice',
        );

        self::assertSame(['email' => 'a@b.c', 'name' => 'Alice'], $request->body());
    }

    #[Test]
    public function multipartBodyComesFromPost(): void
    {
        $request = self::buildRequest(
            method: 'POST',
            contentType: 'multipart/form-data; boundary=----WebKitFormBoundary',
            rawBody: '',
            post: ['title' => 'hello'],
        );

        self::assertSame(['title' => 'hello'], $request->body());
    }

    #[Test]
    public function missingContentTypeYieldsEmptyBody(): void
    {
        $request = self::buildRequest(
            method: 'POST',
            contentType: null,
            rawBody: '{"x":1}',
        );

        self::assertSame([], $request->body());
    }

    #[Test]
    public function getRequestHasEmptyBody(): void
    {
        $request = self::buildRequest(method: 'GET', contentType: null, rawBody: '');

        self::assertSame('GET', $request->method);
        self::assertSame([], $request->body());
    }

    #[Test]
    public function inputReturnsValueByKey(): void
    {
        $request = self::buildRequest(
            method: 'POST',
            contentType: 'application/json',
            rawBody: '{"email":"a@b.c"}',
        );

        self::assertSame('a@b.c', $request->input('email'));
    }

    #[Test]
    public function inputReturnsDefaultWhenKeyMissing(): void
    {
        $request = self::buildRequest(
            method: 'POST',
            contentType: 'application/json',
            rawBody: '{"email":"a@b.c"}',
        );

        self::assertNull($request->input('missing'));
        self::assertSame('fallback', $request->input('missing', 'fallback'));
    }

    /**
     * @param array<string, mixed> $post
     */
    private static function buildRequest(
        string  $method,
        ?string $contentType,
        string  $rawBody,
        array   $post = [],
    ): Request {
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => '/'];
        if ($contentType !== null) {
            $server['CONTENT_TYPE'] = $contentType;
        }

        return Request::fromGlobals(
            get: [],
            post: $post,
            cookies: [],
            files: [],
            server: $server,
            rawBody: $rawBody,
        );
    }
}
