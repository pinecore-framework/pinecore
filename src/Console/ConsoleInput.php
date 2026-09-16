<?php

namespace Pinecore\Pinecore\Console;

final class ConsoleInput
{
    /**
     * @param array<string, string> $pathParams  segments captured from command signature
     * @param list<string>          $arguments   positional arguments (non-option tokens)
     * @param array<string, mixed>  $options     --flag → true, --key=value → 'value'
     */
    public function __construct(
        public readonly string $command,
        public readonly array  $pathParams,
        public readonly array  $arguments,
        public readonly array  $options,
    ) {}

    /**
     * Option value by name: `true` for a bare `--flag`, the string for
     * `--key=value`, the OptionDefinition default when the option was declared
     * but not passed, and $default only for options nobody declared.
     * A declared default of null counts as "missing" here, so a caller's
     * $default still applies — same `??` semantics as reading the array.
     */
    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    /**
     * Parse remaining argv tokens into arguments and options,
     * applying defaults from the command definition.
     *
     * @param list<string>           $tokens  argv tokens after the command string
     * @param list<OptionDefinition> $optionDefs
     */
    public static function parse(
        string $command,
        array  $pathParams,
        array  $tokens,
        array  $optionDefs,
    ): self {
        $arguments = [];
        $options   = [];

        foreach ($tokens as $token) {
            if (str_starts_with($token, '--')) {
                $raw = substr($token, 2);
                if (str_contains($raw, '=')) {
                    [$key, $value]  = explode('=', $raw, 2);
                    $options[$key]  = $value;
                } else {
                    $options[$raw] = true;
                }
            } else {
                $arguments[] = $token;
            }
        }

        // Apply defaults for missing options
        foreach ($optionDefs as $def) {
            if (!array_key_exists($def->name, $options)) {
                $options[$def->name] = $def->default;
            }
        }

        return new self($command, $pathParams, $arguments, $options);
    }
}
