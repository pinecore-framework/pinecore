<?php

namespace Tests\Pinecore\Pinecore;

use DI\CompiledContainer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Config;
use Pinecore\Pinecore\Console\ConsoleRouter;
use Pinecore\Pinecore\ContainerFactory;
use Pinecore\Pinecore\Environment;
use Pinecore\Pinecore\Http\Router;
use Pinecore\Pinecore\Log\CompositeLogger;
use Pinecore\Pinecore\Worker\ProcessLoop;
use Psr\Log\LoggerInterface;

/**
 * Prod boots a compiled container. PHP-DI refuses to compile closures that
 * capture variables (`use`, arrow-function implicit capture, self::), and the
 * failure only surfaces at boot with APP_ENV=prod — this test boots that way
 * so a captured variable in ContainerFactory fails here, not on a server.
 *
 * Kept to a single test: PHP-DI loads the generated class under one fixed
 * name (CompiledContainer) per process, so a second compilation in the same
 * run would silently reuse the first class.
 */
final class ContainerCompilationTest extends TestCase
{
    private string $basePath;
    private string|false $previousEnv;

    protected function setUp(): void
    {
        $this->previousEnv = getenv('APP_ENV');
        $this->basePath    = sys_get_temp_dir() . '/pinecore-compile-' . bin2hex(random_bytes(4));
        mkdir($this->basePath . '/config', 0755, true);

        file_put_contents($this->basePath . '/config/routes.php', <<<'PHP'
            <?php
            use Pinecore\Pinecore\Http\RouteDefinition;
            return [new RouteDefinition('GET', '/ping', 'PingController')];
            PHP);
        file_put_contents($this->basePath . '/config/commands.php', <<<'PHP'
            <?php
            use Pinecore\Pinecore\Console\ConsoleDefinition;
            return [new ConsoleDefinition('hello', 'HelloCommand', 'Says hello')];
            PHP);
        file_put_contents($this->basePath . '/config/log.php', <<<'PHP'
            <?php
            return ['file' => __DIR__ . '/../app.log'];
            PHP);
    }

    protected function tearDown(): void
    {
        putenv($this->previousEnv === false ? 'APP_ENV' : "APP_ENV={$this->previousEnv}");
        exec('rm -rf ' . escapeshellarg($this->basePath));
    }

    #[Test]
    public function prodBootCompilesTheContainerAndFrameworkFactoriesStillResolve(): void
    {
        putenv('APP_ENV=prod');
        $env    = Environment::detect();
        $config = Config::load($this->basePath . '/config', $env);

        $container = ContainerFactory::build($env, $config, $this->basePath);

        self::assertInstanceOf(CompiledContainer::class, $container);
        self::assertFileExists($this->basePath . '/var/cache/prod/CompiledContainer.php');

        self::assertSame($this->basePath, $container->get(ContainerFactory::BASE_PATH));
        self::assertSame($config, $container->get(Config::class));

        // Every factory that used to capture $config / $routes / $commands.
        self::assertInstanceOf(CompositeLogger::class, $container->get(LoggerInterface::class));
        self::assertInstanceOf(ProcessLoop::class, $container->get(ProcessLoop::class));

        $match = $container->get(Router::class)->match('GET', '/ping');
        self::assertTrue($match->found);
        self::assertSame('PingController', $match->definition->controller);

        $commands = $container->get(ConsoleRouter::class)->all();
        self::assertCount(1, $commands);
        self::assertSame('hello', $commands[0]->signature);
    }
}
