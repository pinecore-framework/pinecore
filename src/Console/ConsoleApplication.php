<?php

namespace Pronchev\Pinecore\Console;

use Psr\Container\ContainerInterface;

final class ConsoleApplication
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ConsoleRouter      $router,
    ) {}

    public function run(array $argv): int
    {
        $commandStr = $argv[1] ?? null;

        if ($commandStr === null || $commandStr === 'help') {
            $this->printHelp($argv[2] ?? null);
            return 0;
        }

        $match = $this->router->match($commandStr);

        if (!$match->found) {
            fwrite(STDERR, "Unknown command: {$commandStr}" . PHP_EOL . PHP_EOL);
            $this->printHelp();
            return 1;
        }

        $input  = ConsoleInput::parse(
            $commandStr,
            $match->pathParams,
            array_slice($argv, 2),
            $match->definition->options,
        );
        $output  = new ConsoleOutput();
        $handler = $this->container->get($match->definition->handler);

        ($handler)($input, $output);

        return $output->getExitCode();
    }

    private function printHelp(?string $signature = null): void
    {
        if ($signature !== null) {
            $this->printCommandHelp($signature);
            return;
        }

        $commands = $this->router->all();

        echo 'Available commands:' . PHP_EOL . PHP_EOL;

        $maxLen = max(array_map(static fn($c) => strlen($c->signature), $commands));

        foreach ($commands as $cmd) {
            $opts   = $this->formatOptionsHint($cmd->options);
            $suffix = $opts !== '' ? "  {$opts}" : '';
            printf("  %-{$maxLen}s  %s%s%s", $cmd->signature, $cmd->description, $suffix, PHP_EOL);
        }

        echo PHP_EOL;
        echo 'Usage: bin/console <command> [options]' . PHP_EOL;
        echo '       bin/console help <command>' . PHP_EOL;
    }

    private function printCommandHelp(string $signature): void
    {
        $match = $this->router->match($signature);

        // Try matching the signature pattern itself (without values)
        $found = null;
        foreach ($this->router->all() as $cmd) {
            if ($cmd->signature === $signature) {
                $found = $cmd;
                break;
            }
        }

        if ($found === null && $match->found) {
            $found = $match->definition;
        }

        if ($found === null) {
            fwrite(STDERR, "Unknown command: {$signature}" . PHP_EOL);
            return;
        }

        echo PHP_EOL;
        echo "  {$found->signature} — {$found->description}" . PHP_EOL;

        // Show path params extracted from signature
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $found->signature, $m);
        if ($m[1] !== []) {
            echo PHP_EOL . '  Path params:' . PHP_EOL;
            foreach ($m[1] as $param) {
                printf("    %-16s(part of command)%s", $param, PHP_EOL);
            }
        }

        if ($found->options !== []) {
            echo PHP_EOL . '  Options:' . PHP_EOL;
            foreach ($found->options as $opt) {
                $isFlag    = $opt->default === false;
                $nameStr   = $isFlag ? "--{$opt->name}" : "--{$opt->name}=<...>";
                $defaultStr = $opt->default === false
                    ? ''
                    : ($opt->default === null ? ' (default: none)' : " (default: {$opt->default})");
                printf("    %-22s%s%s%s", $nameStr, $opt->description, $defaultStr, PHP_EOL);
            }
        }

        echo PHP_EOL;
    }

    /** @param list<OptionDefinition> $options */
    private function formatOptionsHint(array $options): string
    {
        $parts = [];
        foreach ($options as $opt) {
            $parts[] = $opt->default === false
                ? "[--{$opt->name}]"
                : "[--{$opt->name}=<{$opt->name}>]";
        }
        return implode(' ', $parts);
    }
}
