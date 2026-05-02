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

        // OPTIONS preflight — respond immediately with CORS headers, no routing or body parsing
        if ($method === 'OPTIONS') {
            $this->emitCorsHeaders();
            http_response_code(204);
            return;
        }

        $declaredLength = (int) ($server['CONTENT_LENGTH'] ?? 0);
        if ($this->maxBodyBytes > 0 && $declaredLength > $this->maxBodyBytes) {
            Response::error('Payload Too Large', 413)
                ->withHeaders($this->corsHeaders())
                ->emit();
            return;
        }

        $rawBody ??= $this->readRawBody();
        if ($this->maxBodyBytes > 0 && strlen($rawBody) > $this->maxBodyBytes) {
            Response::error('Payload Too Large', 413)
                ->withHeaders($this->corsHeaders())
                ->emit();
            return;
        }

        $response = $this->dispatch($get, $post, $cookie, $files, $server, $rawBody);
        $response = $response->withHeaders($this->corsHeaders());
        $response->emit();
    }

    protected function readRawBody(): string
    {
        return (string) file_get_contents('php://input');
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

    /** @return array<string, string> */
    private function corsHeaders(): array
    {
        $cors = $this->corsConfig();
        return [
            'Access-Control-Allow-Origin'  => $cors['origins'],
            'Access-Control-Allow-Methods' => $cors['methods'],
            'Access-Control-Allow-Headers' => $cors['headers'],
        ];
    }

    private function emitCorsHeaders(): void
    {
        $cors = $this->corsConfig();
        header('Access-Control-Allow-Origin: '  . $cors['origins']);
        header('Access-Control-Allow-Methods: ' . $cors['methods']);
        header('Access-Control-Allow-Headers: ' . $cors['headers']);
        header('Access-Control-Max-Age: '       . $cors['max_age']);
    }

    /** @return array<string, mixed> */
    private function corsConfig(): array
    {
        $cors = $this->config->get('app.cors');
        if (!is_array($cors)) {
            throw new \RuntimeException('app.cors config is missing');
        }
        return $cors;
    }
}
