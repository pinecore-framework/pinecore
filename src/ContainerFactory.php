<?php

namespace Pronchev\Pinecore;

use DI\Container;
use DI\ContainerBuilder;
use Pronchev\Pinecore\Http\HttpApplication;
use Pronchev\Pinecore\Http\Router;
use Pronchev\Pinecore\Http\WorkerRunner;
use Pronchev\Pinecore\Log\CompositeLogger;
use Pronchev\Pinecore\Log\JsonStreamLogger;
use Psr\Log\LoggerInterface;

class ContainerFactory
{
    public static function build(Environment $env, Config $config, string $basePath): Container
    {
        $builder = new ContainerBuilder();

        if ($env->isProd()) {
            $cacheDir = $basePath . '/var/cache/prod';
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }
            $builder->enableCompilation($cacheDir);
        }

        $builder->useAttributes(true);

        $builder->addDefinitions([
            Config::class      => $config,
            Environment::class => $env,
            LoggerInterface::class => function () use ($config) {
                $level   = JsonStreamLogger::levelValue((string) $config->get('log.level', 'debug'));
                $channel = (string) $config->get('log.channel', 'app');

                $loggers = [
                    new JsonStreamLogger(self::openStream('php://stdout', 'w'), $level, $channel),
                ];

                $logFile = $config->get('log.file');
                if (is_string($logFile) && $logFile !== '') {
                    $loggers[] = new JsonStreamLogger(self::openStream($logFile, 'a'), $level, $channel);
                }

                return count($loggers) === 1 ? $loggers[0] : new CompositeLogger($loggers);
            },
            WorkerRunner::class => fn($c) => new WorkerRunner(
                $c->get(HttpApplication::class),
                $c->get(ExceptionHandler::class),
                (int) ($config->get('worker.max_requests') ?? 0),
            ),
        ]);

        $routesFile = $basePath . '/config/routes.php';
        if (file_exists($routesFile)) {
            $routes = require $routesFile;
            $builder->addDefinitions([
                Router::class => fn() => new Router($routes),
            ]);
        }

        $servicesFile = $basePath . '/config/services.php';
        if (file_exists($servicesFile)) {
            $definitions = require $servicesFile;
            $definitions($builder, $config, $basePath);
        }

        return $builder->build();
    }

    /** @return resource */
    private static function openStream(string $path, string $mode): mixed
    {
        $stream = @fopen($path, $mode);
        if ($stream === false) {
            throw new \RuntimeException("Failed to open log stream: {$path}");
        }
        return $stream;
    }
}
