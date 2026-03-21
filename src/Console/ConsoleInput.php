<?php

namespace Pronchev\Pinecore\Console;

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
