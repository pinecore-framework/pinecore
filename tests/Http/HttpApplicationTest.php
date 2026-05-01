<?php

namespace Tests\Pronchev\Pinecore\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pronchev\Pinecore\Config;
use Pronchev\Pinecore\Http\HttpApplication;
use Pronchev\Pinecore\Http\MiddlewarePipeline;
use Pronchev\Pinecore\Http\Response;
use Pronchev\Pinecore\Http\RouteDefinition;
use Pronchev\Pinecore\Http\Router;
use Pronchev\Pinecore\Log\NullLogger;
use Psr\Container\ContainerInterface;

final class HttpApplicationTest extends TestCase
{
    protected function setUp(): void
    {
        // http_response_code() persists across tests; normalize at setup.
        http_response_code(200);
    }

    #[Test]
    public function declaredContentLengthOverLimitReturns413WithoutReadingBody(): void
    {
        $app = self::makeApp(rawBody: '', maxBodyBytes: 1024);

        $output = self::captureOutput(fn() => $app->handleRequest(
            get: [], post: [], cookie: [], files: [],
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => '/echo',
                'CONTENT_LENGTH' => '5000',
            ],
        ));

        self::assertSame(413, http_response_code());
        self::assertStringContainsString('Payload Too Large', $output);
        self::assertSame(0, $app->readCount, 'body must not be read when Content-Length exceeds limit');
    }

    #[Test]
    public function actualBodyOverLimitReturns413(): void
    {
        // Client lies about Content-Length (or doesn't send it) but ships a big body.
        $app = self::makeApp(rawBody: str_repeat('x', 5000), maxBodyBytes: 1024);

        $output = self::captureOutput(fn() => $app->handleRequest(
            get: [], post: [], cookie: [], files: [],
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => '/echo',
                'CONTENT_LENGTH' => '0',
            ],
        ));

        self::assertSame(413, http_response_code());
        self::assertStringContainsString('Payload Too Large', $output);
        self::assertSame(1, $app->readCount);
    }

    #[Test]
    public function bodyAtOrBelowLimitIsAccepted(): void
    {
        $app = self::makeApp(rawBody: str_repeat('x', 100), maxBodyBytes: 1024);

        $output = self::captureOutput(fn() => $app->handleRequest(
            get: [], post: [], cookie: [], files: [],
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => '/echo',
                'CONTENT_LENGTH' => '100',
            ],
        ));

        self::assertSame(200, http_response_code());
        self::assertSame('{"ok":true}', $output);
    }

    #[Test]
    public function maxBodyBytesZeroDisablesLimit(): void
    {
        // Even 1 MB body passes when limit is 0.
        $app = self::makeApp(rawBody: str_repeat('x', 1_000_000), maxBodyBytes: 0);

        $output = self::captureOutput(fn() => $app->handleRequest(
            get: [], post: [], cookie: [], files: [],
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => '/echo',
                'CONTENT_LENGTH' => '1000000',
            ],
        ));

        self::assertSame(200, http_response_code());
        self::assertSame('{"ok":true}', $output);
    }

    /**
     * Anonymous HttpApplication subclass that:
     *  - returns a fixed body from readRawBody()
     *  - counts how many times readRawBody() was called
     *  - uses a one-route Router and a controller returning {"ok":true}
     */
    private static function makeApp(string $rawBody, int $maxBodyBytes): object
    {
        $controller = static fn() => Response::json(['ok' => true]);

        $container = new class($controller) implements ContainerInterface {
            public function __construct(private readonly \Closure $controller) {}
            public function get(string $id): mixed { return $this->controller; }
            public function has(string $id): bool { return true; }
        };

        $router   = new Router([new RouteDefinition('POST', '/echo', 'EchoController')]);
        $pipeline = new MiddlewarePipeline($container);
        $config   = new Config([
            'http' => ['max_body_bytes' => $maxBodyBytes],
            'app'  => ['cors' => [
                'origins' => '*', 'methods' => '*', 'headers' => '*', 'max_age' => 600,
            ]],
        ]);
        $logger   = new NullLogger();

        return new class($container, $router, $pipeline, $config, $logger, $rawBody) extends HttpApplication {
            public int $readCount = 0;

            public function __construct(
                ContainerInterface $container,
                Router             $router,
                MiddlewarePipeline $pipeline,
                Config             $config,
                NullLogger         $logger,
                private readonly string $rawBody,
            ) {
                parent::__construct($container, $router, $pipeline, $config, $logger);
            }

            protected function readRawBody(): string
            {
                $this->readCount++;
                return $this->rawBody;
            }
        };
    }

    private static function captureOutput(callable $fn): string
    {
        ob_start();
        try {
            $fn();
        } finally {
            $output = ob_get_clean();
        }
        return $output;
    }
}
