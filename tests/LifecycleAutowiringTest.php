<?php

namespace Tests\Pinecore\Pinecore;

use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Config;
use Pinecore\Pinecore\ContainerFactory;
use Pinecore\Pinecore\Environment;
use Pinecore\Pinecore\Orm\Sql\SqlHealthCheck;
use Pinecore\Pinecore\Worker\ProcessLoop;
use Pinecore\Pinecore\WorkerLifecycle;
use Tests\Pinecore\Pinecore\Fixtures\Orm\DocumentRepository;

/**
 * The connection-loss guard only works if a repository holds the same WorkerLifecycle
 * the ProcessLoop reads. PHP-DI's reflection autowiring skips optional constructor
 * parameters, so while the parameter was `?WorkerLifecycle = null` every autowired
 * repository silently got null and the guard was off (tillit TIL-24). This boots the
 * real ContainerFactory with an application-style repository subclass and checks the
 * singleton reaches it without any per-repository definition.
 */
final class LifecycleAutowiringTest extends TestCase
{
    private string $basePath;
    private string|false $previousEnv;

    protected function setUp(): void
    {
        $this->previousEnv = getenv('APP_ENV');
        putenv('APP_ENV=dev');
        $this->basePath = sys_get_temp_dir() . '/pinecore-autowire-' . bin2hex(random_bytes(4));
        mkdir($this->basePath . '/config', 0755, true);

        file_put_contents($this->basePath . '/config/services.php', <<<'PHP'
            <?php
            use DI\ContainerBuilder;
            use Pinecore\Pinecore\Orm\Sql\PostgresDialect;
            use Pinecore\Pinecore\Orm\Sql\SqlDialect;
            return function (ContainerBuilder $builder): void {
                $builder->addDefinitions([
                    PDO::class        => fn() => new PDO('sqlite::memory:'),
                    SqlDialect::class => fn() => new PostgresDialect(),
                ]);
            };
            PHP);
    }

    protected function tearDown(): void
    {
        putenv($this->previousEnv === false ? 'APP_ENV' : "APP_ENV={$this->previousEnv}");
        exec('rm -rf ' . escapeshellarg($this->basePath));
    }

    #[Test]
    public function autowiredRepositoryAndHealthCheckShareTheLifecycleProcessLoopReads(): void
    {
        $env       = Environment::detect();
        $config    = Config::load($this->basePath . '/config', $env);
        $container = ContainerFactory::build($env, $config, $this->basePath);

        $lifecycle = $container->get(WorkerLifecycle::class);

        self::assertSame($lifecycle, $this->lifecycleOf($container->get(ProcessLoop::class)));
        self::assertSame($lifecycle, $this->lifecycleOf($container->get(DocumentRepository::class)));
        self::assertSame($lifecycle, $this->lifecycleOf($container->get(SqlHealthCheck::class)));
    }

    private function lifecycleOf(object $service): ?WorkerLifecycle
    {
        $class = new \ReflectionClass($service);
        while (!$class->hasProperty('lifecycle')) {
            $class = $class->getParentClass();
        }

        return $class->getProperty('lifecycle')->getValue($service);
    }
}
