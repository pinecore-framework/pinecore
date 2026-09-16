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
use Pinecore\Pinecore\Orm\IdGenerator;
use Pinecore\Pinecore\Orm\IdGeneratorInterface;
use Pinecore\Pinecore\Worker\ProcessLoop;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

use function DI\factory;

/**
 * In prod the container is compiled (PHP-DI copies every closure's source into
 * a generated class), so no definition here may capture state: no `use (...)`,
 * no arrow-function implicit capture, no `self::`/`$this` inside a closure.
 * Everything a factory needs comes in as a parameter resolved from the
 * container (type-hinted class, or ContainerInterface) — see createLogger()
 * and friends. The same rule applies to an application's config/services.php.
 */
class ContainerFactory
{
    /** Container entry holding the application root passed to Kernel::boot(). */
    public const BASE_PATH = 'app.base_path';

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
            self::BASE_PATH    => $basePath,
            Config::class      => $config,
            Environment::class => $env,
            LoggerInterface::class      => factory([self::class, 'createLogger']),
            IdGeneratorInterface::class => fn() => new IdGenerator(),
            ProcessLoop::class => fn(WorkerLifecycle $lifecycle, Config $config) => new ProcessLoop(
                $lifecycle,
                $config->getInt('worker.max_requests', 0),
            ),
            WorkerRunner::class => fn(ContainerInterface $c) => new WorkerRunner(
                $c->get(HttpApplication::class),
                $c->get(ExceptionHandler::class),
                $c->get(ProcessLoop::class),
            ),
            ConsoleRouter::class => factory([self::class, 'createConsoleRouter']),
        ]);

        if (file_exists($basePath . '/config/routes.php')) {
            $builder->addDefinitions([
                Router::class => factory([self::class, 'createRouter']),
            ]);
        }

        $servicesFile = $basePath . '/config/services.php';
        if (file_exists($servicesFile)) {
            $definitions = require $servicesFile;
            $definitions($builder, $config, $basePath);
        }

        return $builder->build();
    }

    /** @internal DI factory — public only because PHP-DI invokes it. */
    public static function createLogger(Config $config): LoggerInterface
    {
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
    }

    /** @internal DI factory — registered only when config/routes.php exists. */
    public static function createRouter(ContainerInterface $c): Router
    {
        $routes = require $c->get(self::BASE_PATH) . '/config/routes.php';

        return new Router($routes);
    }

    /** @internal DI factory — an empty router when config/commands.php is absent. */
    public static function createConsoleRouter(ContainerInterface $c): ConsoleRouter
    {
        $commandsFile = $c->get(self::BASE_PATH) . '/config/commands.php';
        $commands     = file_exists($commandsFile) ? require $commandsFile : [];

        return new ConsoleRouter($commands);
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
