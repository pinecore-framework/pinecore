<?php

namespace Pronchev\Pinecore;

use DI\Container;
use DI\ContainerBuilder;
use Pronchev\Pinecore\Http\Router;

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

        $builder->useAutowiring(true);

        $builder->addDefinitions([
            Config::class      => $config,
            Environment::class => $env,
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
}
