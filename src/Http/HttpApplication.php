<?php

namespace Pronchev\Pinecore\Http;

use Pronchev\Pinecore\Auth\AuthException;
use Pronchev\Pinecore\Config;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class HttpApplication
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly Router             $router,
        private readonly MiddlewarePipeline $pipeline,
        private readonly Config             $config,
        private readonly LoggerInterface    $logger,
    ) {}

    public function handleRequest(array $get, array $post, array $cookie, array $files, array $server): void
    {
        $rawBody = (string) file_get_contents('php://input');
        $request = Request::fromGlobals($get, $cookie, $files, $server, $rawBody);

        // OPTIONS preflight — respond immediately with CORS headers, no routing
        if ($request->method === 'OPTIONS') {
            $this->emitCorsHeaders();
            http_response_code(204);
            return;
        }

        $response = $this->dispatch($request);
        $response = $response->withHeaders($this->corsHeaders());
        $response->emit();
    }

    private function dispatch(Request $request): Response
    {
        try {
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
        } catch (\JsonException) {
            return Response::error('Invalid JSON body', 400);
        } catch (\Throwable $e) {
            $this->logger->error('Unhandled exception during dispatch', [
                'exception' => $e,
                'method'    => $request->method,
                'path'      => $request->path,
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
