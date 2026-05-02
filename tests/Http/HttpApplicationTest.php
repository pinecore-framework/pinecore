<?php

namespace Tests\Pinecore\Pinecore\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Config;
use Pinecore\Pinecore\Http\CorsPolicy;
use Pinecore\Pinecore\Http\HttpApplication;
use Pinecore\Pinecore\Http\MiddlewarePipeline;
use Pinecore\Pinecore\Http\Response;
use Pinecore\Pinecore\Http\RouteDefinition;
use Pinecore\Pinecore\Http\Router;
use Pinecore\Pinecore\Log\NullLogger;
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
    public function explicitRawBodyArgumentBypassesReadRawBody(): void
    {
        // Worker-runtime path: adapter feeds the body in, php://input is not touched.
        $app = self::makeApp(rawBody: 'should-not-be-read', maxBodyBytes: 1024);

        $output = self::captureOutput(fn() => $app->handleRequest(
            get: [], post: [], cookie: [], files: [],
            server: [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI'    => '/echo',
                'CONTENT_LENGTH' => '11',
            ],
            rawBody: 'from-adapter',
        ));

        self::assertSame(200, http_response_code());
        self::assertSame('{"ok":true}', $output);
        self::assertSame(0, $app->readCount, 'readRawBody() must not be called when rawBody is provided');
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

    #[Test]
    public function optionsPreflightFromAllowedOriginEmitsFullCorsHeaders(): void
    {
        $app = self::makeApp(allowedOrigins: ['https://app.example.com']);

        self::captureOutput(fn() => $app->handleRequest(
            get: [], post: [], cookie: [], files: [],
            server: [
                'REQUEST_METHOD' => 'OPTIONS',
                'REQUEST_URI'    => '/echo',
                'HTTP_ORIGIN'    => 'https://app.example.com',
            ],
        ));

        self::assertSame(204, http_response_code());
        self::assertSame('https://app.example.com', $app->capturedHeader('Access-Control-Allow-Origin'));
        self::assertNotNull($app->capturedHeader('Access-Control-Allow-Methods'));
        self::assertNotNull($app->capturedHeader('Access-Control-Allow-Headers'));
        self::assertNotNull($app->capturedHeader('Access-Control-Max-Age'));
        self::assertSame('Origin', $app->capturedHeader('Vary'));
    }

    #[Test]
    public function optionsPreflightFromDisallowedOriginEmitsNoCorsHeaders(): void
    {
        $app = self::makeApp(allowedOrigins: ['https://app.example.com']);

        self::captureOutput(fn() => $app->handleRequest(
            get: [], post: [], cookie: [], files: [],
            server: [
                'REQUEST_METHOD' => 'OPTIONS',
                'REQUEST_URI'    => '/echo',
                'HTTP_ORIGIN'    => 'https://evil.example.org',
            ],
        ));

        self::assertSame(204, http_response_code());
        self::assertNull($app->capturedHeader('Access-Control-Allow-Origin'));
        self::assertNull($app->capturedHeader('Access-Control-Allow-Methods'));
        // Vary: Origin is still emitted to keep caches honest.
        self::assertSame('Origin', $app->capturedHeader('Vary'));
    }

    /**
     * @param list<string>|null $allowedOrigins
     */
    private static function makeApp(
        string $rawBody = '',
        int $maxBodyBytes = 1024,
        ?array $allowedOrigins = null,
    ): object {
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
                'allowed_origins'   => $allowedOrigins ?? ['*'],
                'allowed_methods'   => ['GET', 'POST', 'OPTIONS'],
                'allowed_headers'   => ['Content-Type', 'Authorization'],
                'allow_credentials' => false,
                'max_age'           => 600,
            ]],
        ]);
        $logger = new NullLogger();
        $cors   = new CorsPolicy($config);

        return new class($container, $router, $pipeline, $config, $logger, $cors, $rawBody) extends HttpApplication {
            public int $readCount = 0;
            /** @var array<string, string> */
            private array $headers = [];

            public function __construct(
                ContainerInterface $container,
                Router             $router,
                MiddlewarePipeline $pipeline,
                Config             $config,
                NullLogger         $logger,
                CorsPolicy         $cors,
                private readonly string $rawBody,
            ) {
                parent::__construct($container, $router, $pipeline, $config, $logger, $cors);
            }

            protected function readRawBody(): string
            {
                $this->readCount++;
                return $this->rawBody;
            }

            protected function sendHeader(string $line): void
            {
                [$name, $value] = explode(':', $line, 2);
                $this->headers[trim($name)] = trim($value);
            }

            public function capturedHeader(string $name): ?string
            {
                return $this->headers[$name] ?? null;
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
