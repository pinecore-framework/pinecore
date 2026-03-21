<?php

namespace Pronchev\Pinecore\Console;

final class ConsoleRouter
{
    /**
     * @var list<array{definition: ConsoleDefinition, regex: string, params: list<string>}>
     */
    private array $compiled = [];

    /** @param list<ConsoleDefinition> $commands */
    public function __construct(array $commands)
    {
        foreach ($commands as $command) {
            $this->compiled[] = $this->compile($command);
        }
    }

    public function match(string $commandStr): ConsoleMatch
    {
        foreach ($this->compiled as $entry) {
            if (!preg_match($entry['regex'], $commandStr, $m)) {
                continue;
            }

            $params = [];
            foreach ($entry['params'] as $name) {
                $params[$name] = $m[$name];
            }

            return ConsoleMatch::found($entry['definition'], $params);
        }

        return ConsoleMatch::notFound();
    }

    /** @return list<ConsoleDefinition> */
    public function all(): array
    {
        return array_column($this->compiled, 'definition');
    }

    /** @return array{definition: ConsoleDefinition, regex: string, params: list<string>} */
    private function compile(ConsoleDefinition $command): array
    {
        $params  = [];
        // Escape dots, then replace {param} with named capture groups
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '(?P<' . $m[1] . '>[^.:]+)';
            },
            str_replace('.', '\.', $command->signature),
        );

        return [
            'definition' => $command,
            'regex'      => '#^' . $pattern . '$#',
            'params'     => $params,
        ];
    }
}
