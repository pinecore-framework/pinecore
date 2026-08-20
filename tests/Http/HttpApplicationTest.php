<?php

namespace Tests\Pinecore\Pinecore\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Auth\AuthException;
use Pinecore\Pinecore\Auth\AuthFailureInterface;
use Pinecore\Pinecore\Config;
use Pinecore\Pinecore\Http\CorsPolicy;
use Pinecore\Pinecore\Http\HttpApplication;
use Pinecore\Pinecore\Http\MiddlewareInterface;
use Pinecore\Pinecore\Http\MiddlewarePipeline;
use Pinecore\Pinecore\Http\Request;
use Pinecore\Pinecore\Http\Response;
use Pinecore\Pinecore\Http\RouteDefinition;
use Pinecore\Pinecore\Http\Router;
use Pinecore\Pinecore\Log\NullLogger;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

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

    #[Test]
    public function frameworkAuthExceptionFromControllerIs401(): void
    {
        $app = self::makeApp(controller: static fn() => throw new AuthException('Invalid token'));

        $output = self::captureOutput(fn() => $app->handleRequest(...self::postEcho()));

        self::assertSame(401, http_response_code());
        self::assertSame('{"error":"Invalid token"}', $output);
    }

    #[Test]
    public function applicationExceptionCarryingTheMarkerIs401(): void
    {
        $app = self::makeApp(controller: static fn() => throw new DomainAuthFailure('Bad credentials'));

        $output = self::captureOutput(fn() => $app->handleRequest(...self::postEcho()));

        self::assertSame(401, http_response_code());
        self::assertSame('{"error":"Bad credentials"}', $output);
    }

    #[Test]
    public function markerThrownFromMiddlewareIs401(): void
    {
        $app = self::makeApp(middleware: [new ThrowingMiddleware(new DomainAuthFailure('Refresh token revoked'))]);

        $output = self::captureOutput(fn() => $app->handleRequest(...self::postEcho()));

        self::assertSame(401, http_response_code());
        self::assertSame('{"error":"Refresh token revoked"}', $output);
    }

    #[Test]
    public function unmarkedRuntimeExceptionStays500AndIsLogged(): void
    {
        $logger = new RecordingLogger();
        $app    = self::makeApp(
            controller: static fn() => throw new \RuntimeException('db down'),
            logger: $logger,
        );

        $output = self::captureOutput(fn() => $app->handleRequest(...self::postEcho()));

        self::assertSame(500, http_response_code());
        self::assertSame('{"error":"Internal Server Error"}', $output, 'message must not leak');
        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertInstanceOf(\RuntimeException::class, $logger->records[0]['context']['exception']);
    }

    /** @return array<string, mixed> named arguments for handleRequest(): a POST /echo. */
    private static function postEcho(): array
    {
        return [
            'get' => [], 'post' => [], 'cookie' => [], 'files' => [],
            'server' => ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/echo', 'CONTENT_LENGTH' => '0'],
        ];
    }

    /**
     * @param list<string>|null          $allowedOrigins
     * @param list<MiddlewareInterface>  $middleware   instances, registered in the container by class
     */
    private static function makeApp(
        string $rawBody = '',
        int $maxBodyBytes = 1024,
        ?array $allowedOrigins = null,
        ?\Closure $controller = null,
        array $middleware = [],
        ?LoggerInterface $logger = null,
    ): object {
        $controller ??= static fn() => Response::json(['ok' => true]);

        $services = ['EchoController' => $controller];
        foreach ($middleware as $mw) {
            $services[$mw::class] = $mw;
        }

        $container = new class($services) implements ContainerInterface {
            /** @param array<string, mixed> $services */
            public function __construct(private readonly array $services) {}
            public function get(string $id): mixed { return $this->services[$id]; }
            public function has(string $id): bool { return isset($this->services[$id]); }
        };

        $router   = new Router([new RouteDefinition(
            'POST',
            '/echo',
            'EchoController',
            array_map(static fn(MiddlewareInterface $mw): string => $mw::class, $middleware),
        )]);
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
        $logger ??= new NullLogger();
        $cors     = new CorsPolicy($config);

        return new class($container, $router, $pipeline, $config, $logger, $cors, $rawBody) extends HttpApplication {
            public int $readCount = 0;
            /** @var array<string, string> */
            private array $headers = [];

            public function __construct(
                ContainerInterface $container,
                Router             $router,
                MiddlewarePipeline $pipeline,
                Config             $config,
                LoggerInterface    $logger,
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

/** Application-level auth failure: plain RuntimeException tagged with the framework marker. */
final class DomainAuthFailure extends \RuntimeException implements AuthFailureInterface {}

final class ThrowingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly \Throwable $exception) {}

    public function process(Request $request): Request
    {
        throw $this->exception;
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}
