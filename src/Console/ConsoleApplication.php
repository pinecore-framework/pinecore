<?php

namespace Pinecore\Pinecore\Console;

use League\CLImate\CLImate;
use Psr\Container\ContainerInterface;

final class ConsoleApplication
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ConsoleRouter      $router,
        private readonly CLImate            $climate = new CLImate(),
    ) {}

    public function run(array $argv): int
    {
        if (!isset($argv[1]) || $argv[1] === 'help') {
            $this->printHelp($argv[2] ?? null);
            return 0;
        }

        // Classify each token after the script name independently: tokens prefixed with "--"
        // are options, everything else is positional. Positional tokens (in their original
        // order) form the command string matched against signatures, so options and
        // positional args may be freely interleaved on the command line.
        $positional   = [];
        $optionTokens = [];
        for ($i = 1, $n = count($argv); $i < $n; $i++) {
            $tok = $argv[$i];
            if (str_starts_with($tok, '--')) {
                $optionTokens[] = $tok;
            } else {
                $positional[] = $tok;
            }
        }

        $commandStr = implode(' ', $positional);
        $match = $this->router->match($commandStr);

        if (!$match->found) {
            $this->climate->to('error')->out("<error>Unknown command:</error> {$commandStr}");
            $this->climate->to('error')->out('');
            $this->printHelp();
            return 1;
        }

        $input  = ConsoleInput::parse(
            $commandStr,
            $match->pathParams,
            $optionTokens,
            $match->definition->options,
        );
        $output  = new ConsoleOutput($this->climate);
        $handler = $this->container->get($match->definition->handler);

        // A non-zero int returned by the handler is the exit code; otherwise (void, 0) fall back to
        // whatever the handler set on the output.
        $code = ($handler)($input, $output);

        return is_int($code) && $code !== 0 ? $code : $output->getExitCode();
    }

    private function printHelp(?string $target = null): void
    {
        if ($target !== null) {
            $this->printScopedHelp($target);
            return;
        }

        $this->printHeader();

        $commands = $this->router->all();
        if ($commands === []) {
            $this->climate->out('  <comment>No commands registered.</comment>');
            $this->climate->out('');
            return;
        }

        $this->printUsage();
        $this->printCommandsList($commands);
    }

    private function printScopedHelp(string $target): void
    {
        $exact = null;

        // 1. exact signature
        foreach ($this->router->all() as $cmd) {
            if ($cmd->signature === $target) {
                $exact = $cmd;
                break;
            }
        }

        // 2. user typed the bare command without supplying path params
        if ($exact === null) {
            foreach ($this->router->all() as $cmd) {
                if (str_starts_with($cmd->signature, $target . ' ')) {
                    $exact = $cmd;
                    break;
                }
            }
        }

        // 3. user typed the command with values — let the router resolve it
        if ($exact === null) {
            $match = $this->router->match($target);
            if ($match->found) {
                $exact = $match->definition;
            }
        }

        if ($exact !== null) {
            $this->printCommandHelp($exact);
            return;
        }

        // Maybe it's a namespace prefix (e.g. "mongo")
        $namespace = $this->stripTrailingColon($target);
        $matches   = $this->commandsInNamespace($namespace);

        if ($matches !== []) {
            $this->printHeader();
            $this->climate->out("  <bold><yellow>COMMANDS</yellow></bold> in <info>{$namespace}</info>");
            $this->climate->out('');
            $this->printGroup($matches);
            return;
        }

        $this->climate->to('error')->out("<error>Unknown command or namespace:</error> {$target}");
    }

    private function printHeader(): void
    {
        $this->climate->out('');
        $this->climate->out('  <bold><cyan>Pinecore Console</cyan></bold>');
        $this->climate->out('');
    }

    private function printUsage(): void
    {
        $this->climate->out('  <bold><yellow>USAGE</yellow></bold>');
        $this->climate->out('    bin/console <info><command></info> [<comment>options</comment>]');
        $this->climate->out('    bin/console help [<info><command></info>|<info><namespace></info>]');
        $this->climate->out('');
    }

    /** @param list<ConsoleDefinition> $commands */
    private function printCommandsList(array $commands): void
    {
        $this->climate->out('  <bold><yellow>COMMANDS</yellow></bold>');

        $groups = $this->groupByNamespace($commands);
        ksort($groups);

        foreach ($groups as $namespace => $group) {
            $this->climate->out('   <bold><cyan>' . $namespace . '</cyan></bold>');
            $this->printGroup($group);
        }
    }

    /** @param list<ConsoleDefinition> $group */
    private function printGroup(array $group): void
    {
        $maxLen = 0;
        foreach ($group as $cmd) {
            $maxLen = max($maxLen, strlen($cmd->signature));
        }

        foreach ($group as $cmd) {
            $pad = str_repeat(' ', $maxLen - strlen($cmd->signature));
            $this->climate->out("    <info>{$cmd->signature}</info>{$pad}  {$cmd->description}");
        }
        $this->climate->out('');
    }

    private function printCommandHelp(ConsoleDefinition $cmd): void
    {
        $this->printHeader();

        $coloredSignature = $this->colorizeSignature($cmd->signature);
        $this->climate->out("  <bold>{$coloredSignature}</bold>");
        $this->climate->out("  <whisper>{$cmd->description}</whisper>");
        $this->climate->out('');

        $this->climate->out('  <bold><yellow>USAGE</yellow></bold>');
        $this->climate->out('    bin/console ' . $this->buildUsageLine($cmd));
        $this->climate->out('');

        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $cmd->signature, $m);
        $params = $m[1];
        if ($params !== []) {
            $this->climate->out('  <bold><yellow>ARGUMENTS</yellow></bold>');
            $width = max(array_map('strlen', $params));
            foreach ($params as $param) {
                $pad = str_repeat(' ', $width - strlen($param));
                $this->climate->out("    <comment>{$param}</comment>{$pad}  <whisper>part of command</whisper>");
            }
            $this->climate->out('');
        }

        if ($cmd->options !== []) {
            $this->climate->out('  <bold><yellow>OPTIONS</yellow></bold>');
            $labels = [];
            foreach ($cmd->options as $opt) {
                $labels[$opt->name] = $opt->default === false ? "--{$opt->name}" : "--{$opt->name}=<value>";
            }
            $width = max(array_map('strlen', $labels));
            foreach ($cmd->options as $opt) {
                $label  = $labels[$opt->name];
                $pad    = str_repeat(' ', $width - strlen($label));
                $suffix = $this->formatDefaultSuffix($opt);
                $this->climate->out("    <info>{$label}</info>{$pad}  {$opt->description}{$suffix}");
            }
            $this->climate->out('');
        }
    }

    private function buildUsageLine(ConsoleDefinition $cmd): string
    {
        $sig = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn(array $m): string => "<comment><{$m[1]}></comment>",
            $cmd->signature,
        );

        $opts = [];
        foreach ($cmd->options as $opt) {
            $opts[] = $opt->default === false
                ? "[<info>--{$opt->name}</info>]"
                : "[<info>--{$opt->name}</info>=<comment><value></comment>]";
        }

        return $opts === [] ? $sig : $sig . ' ' . implode(' ', $opts);
    }

    private function colorizeSignature(string $signature): string
    {
        return preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn(array $m): string => "<comment>{{$m[1]}}</comment>",
            $signature,
        );
    }

    private function formatDefaultSuffix(OptionDefinition $opt): string
    {
        if ($opt->default === false || $opt->default === null) {
            return '';
        }
        return " <whisper>(default: {$opt->default})</whisper>";
    }

    /**
     * @param  list<ConsoleDefinition>           $commands
     * @return array<string, list<ConsoleDefinition>>
     */
    private function groupByNamespace(array $commands): array
    {
        $groups = [];
        foreach ($commands as $cmd) {
            $ns = str_contains($cmd->signature, ':')
                ? strstr($cmd->signature, ':', true)
                : '_';
            $groups[$ns][] = $cmd;
        }
        return $groups;
    }

    /** @return list<ConsoleDefinition> */
    private function commandsInNamespace(string $namespace): array
    {
        $prefix = $namespace . ':';
        $hits   = [];
        foreach ($this->router->all() as $cmd) {
            if (str_starts_with($cmd->signature, $prefix)) {
                $hits[] = $cmd;
            }
        }
        return $hits;
    }

    private function stripTrailingColon(string $s): string
    {
        return rtrim($s, ':');
    }
}
