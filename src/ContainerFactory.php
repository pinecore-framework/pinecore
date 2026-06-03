<?php

namespace Pinecore\Pinecore;

use DI\Container;
use DI\ContainerBuilder;
use Pinecore\Pinecore\Console\ConsoleRouter;
use Pinecore\Pinecore\Http\HttpApplication;
use Pinecore\Pinecore\Http\Router;
use Pinecore\Pinecore\Http\WorkerRunner;
use Pinecore\Pinecore\Log\CompositeLogger;
use Pinecore\Pinecore\Log\JsonStreamLogger;
use Pinecore\Pinecore\Worker\ProcessLoop;
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
                $level   = JsonStreamLogger::levelValue($config->getString('log.level', 'debug'));
                $channel = $config->getString('log.channel', 'app');

                $loggers = [
                    new JsonStreamLogger(self::openStream('php://stdout', 'w'), $level, $channel),
                ];

                $logFile = $config->getString('log.file', '');
                if ($logFile !== '') {
                    $loggers[] = new JsonStreamLogger(self::openStream($logFile, 'a'), $level, $channel);
                }

                return count($loggers) === 1 ? $loggers[0] : new CompositeLogger($loggers);
            },
            ProcessLoop::class => fn($c) => new ProcessLoop(
                $c->get(WorkerLifecycle::class),
                $config->getInt('worker.max_requests', 0),
            ),
            WorkerRunner::class => fn($c) => new WorkerRunner(
                $c->get(HttpApplication::class),
                $c->get(ExceptionHandler::class),
                $c->get(ProcessLoop::class),
            ),
            ConsoleRouter::class => fn() => new ConsoleRouter([]),
        ]);

        $routesFile = $basePath . '/config/routes.php';
        if (file_exists($routesFile)) {
            $routes = require $routesFile;
            $builder->addDefinitions([
                Router::class => fn() => new Router($routes),
            ]);
        }

        $commandsFile = $basePath . '/config/commands.php';
        if (file_exists($commandsFile)) {
            $commands = require $commandsFile;
            $builder->addDefinitions([
                ConsoleRouter::class => fn() => new ConsoleRouter($commands),
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
