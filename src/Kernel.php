<?php

namespace Pinecore\Pinecore;

use DI\Container;

final class Kernel
{
    public static function boot(string $basePath): Container
    {
        $env    = Environment::detect();
        $config = Config::load($basePath . '/config', $env);
        return ContainerFactory::build($env, $config, $basePath);
    }
}
