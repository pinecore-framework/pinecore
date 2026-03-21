<?php

namespace Pronchev\Pinecore;

use DI\Container;
use DI\ContainerBuilder;

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

        $servicesFile = $basePath . '/config/services.php';
        if (file_exists($servicesFile)) {
            $definitions = require $servicesFile;
            $definitions($builder, $config, $basePath);
        }

        return $builder->build();
    }
}
