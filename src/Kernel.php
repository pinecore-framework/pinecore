<?php

namespace Pronchev\Pinecore;

use DI\Container;

class Kernel
{
    private static ?Container $container = null;

    public static function boot(string $basePath): Container
    {
        $env = Environment::detect();
        $config = Config::load($basePath . '/config', $env);
        $container = ContainerFactory::build($env, $config, $basePath);

        self::$container = $container;

        return $container;
    }

    public static function container(): Container
    {
        if (self::$container === null) {
            throw new \RuntimeException('Kernel has not been booted. Call Kernel::boot() first.');
        }

        return self::$container;
    }
}
