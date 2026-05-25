<?php

namespace Pinecore\Pinecore\Console;

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
                // A missing optional placeholder leaves its named group unset.
                $params[$name] = $m[$name] ?? '';
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
        // Split the signature into ordered tokens: each is either a literal run
        // or a {name} / {name?} placeholder. A token list (rather than a single
        // preg_replace_callback pass) lets us look ahead: when an optional
        // placeholder follows a literal, its leading whitespace must move into
        // the optional group so `cmd` (no arg) still matches.
        preg_match_all(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(\??)\}|([^{]+)/',
            $command->signature,
            $sets,
            PREG_SET_ORDER,
        );

        $tokens = [];
        foreach ($sets as $set) {
            if (($set[1] ?? '') !== '') {
                $tokens[] = ['arg' => $set[1], 'optional' => ($set[2] ?? '') === '?'];
            } else {
                $tokens[] = ['lit' => $set[3]];
            }
        }

        $params  = [];
        $pattern = '';
        $count   = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (isset($token['arg'])) {
                $params[] = $token['arg'];
                $value    = '(?P<' . $token['arg'] . '>[^\s]+)';
                // Optional arg absorbs its own leading whitespace, so the
                // preceding literal's trailing space (stripped below) is not
                // left dangling and required.
                $pattern .= $token['optional'] ? '(?:\s+' . $value . ')?' : $value;
                continue;
            }

            $literal = $token['lit'];
            if (isset($tokens[$i + 1]['optional']) && $tokens[$i + 1]['optional'] === true) {
                $literal = rtrim($literal);
            }
            $pattern .= preg_quote($literal, '#');
        }

        return [
            'definition' => $command,
            'regex'      => '#^' . $pattern . '$#',
            'params'     => $params,
        ];
    }
}
