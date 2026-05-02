<?php

namespace Pronchev\Pinecore\Http;

use Pronchev\Pinecore\Auth\AuthException;
use Pronchev\Pinecore\Config;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class HttpApplication
{
    private const DEFAULT_MAX_BODY_BYTES = 8 * 1024 * 1024;

    private readonly int $maxBodyBytes;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly Router             $router,
        private readonly MiddlewarePipeline $pipeline,
        private readonly Config             $config,
        private readonly LoggerInterface    $logger,
        private readonly CorsPolicy         $cors,
    ) {
        $this->maxBodyBytes = (int) ($config->get('http.max_body_bytes') ?? self::DEFAULT_MAX_BODY_BYTES);
    }

    public function handleRequest(
        array $get,
        array $post,
        array $cookie,
        array $files,
        array $server,
        ?string $rawBody = null,
    ): void {
        $method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $origin = isset($server['HTTP_ORIGIN']) ? (string) $server['HTTP_ORIGIN'] : null;

        // OPTIONS preflight — respond immediately with CORS headers, no routing or body parsing.
        if ($method === 'OPTIONS') {
            foreach ($this->cors->preflightHeaders($origin) as $name => $value) {
                $this->sendHeader($name . ': ' . $value);
            }
            http_response_code(204);
            return;
        }

        $corsHeaders = $this->cors->simpleHeaders($origin);

        $declaredLength = (int) ($server['CONTENT_LENGTH'] ?? 0);
        if ($this->maxBodyBytes > 0 && $declaredLength > $this->maxBodyBytes) {
            Response::error('Payload Too Large', 413)
                ->withHeaders($corsHeaders)
                ->emit();
            return;
        }

        $rawBody ??= $this->readRawBody();
        if ($this->maxBodyBytes > 0 && strlen($rawBody) > $this->maxBodyBytes) {
            Response::error('Payload Too Large', 413)
                ->withHeaders($corsHeaders)
                ->emit();
            return;
        }

        $response = $this->dispatch($get, $post, $cookie, $files, $server, $rawBody);
        $response = $response->withHeaders($corsHeaders);
        $response->emit();
    }

    protected function readRawBody(): string
    {
        return (string) file_get_contents('php://input');
    }

    /**
     * Hook for emitting raw header lines (preflight path). Tests override
     * this to capture headers — `header()` is not observable in CLI.
     */
    protected function sendHeader(string $line): void
    {
        header($line);
    }

    private function dispatch(
        array $get, array $post, array $cookie, array $files, array $server, string $rawBody
    ): Response {
        $request = null;
        try {
            $request = Request::fromGlobals($get, $post, $cookie, $files, $server, $rawBody);

            $match = $this->router->match($request->method, $request->path);

            if ($match->methodNotAllowed) {
                return Response::error('Method Not Allowed', 405)
                    ->withHeader('Allow', implode(', ', $match->allowedMethods));
            }

            if (!$match->found) {
                return Response::error('Not Found', 404);
            }

            $request    = $request->withPathParams($match->pathParams);
            $request    = $this->pipeline->run($request, $match->definition->middleware);
            $controller = $this->container->get($match->definition->controller);

            return ($controller)($request);

        } catch (AuthException $e) {
            return Response::error($e->getMessage(), 401);
        } catch (HttpException $e) {
            return Response::error($e->getMessage(), $e->getCode());
        } catch (\Throwable $e) {
            $this->logger->error('Unhandled exception during dispatch', [
                'exception' => $e,
                'method'    => $request?->method ?? ($server['REQUEST_METHOD'] ?? 'UNKNOWN'),
                'path'      => $request?->path   ?? ($server['REQUEST_URI']    ?? 'UNKNOWN'),
            ]);
            return Response::error('Internal Server Error', 500);
        }
    }

    public function terminate(): void
    {
        // Завершение работы приложения. Закрытие соединений, ресурсов и.т.п.
    }
}
