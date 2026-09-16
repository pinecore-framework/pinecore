<?php

namespace Tests\Pinecore\Pinecore\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Console\ConsoleInput;
use Pinecore\Pinecore\Console\OptionDefinition;

final class ConsoleInputTest extends TestCase
{
    #[Test]
    public function optionReturnsFlagValueAndDeclaredDefaults(): void
    {
        $input = ConsoleInput::parse('users:create', [], ['--dry-run', '--email=a@x', 'admin'], [
            new OptionDefinition('dry-run', 'Do not persist', false),
            new OptionDefinition('email', 'User email', null),
            new OptionDefinition('verbose', 'More output', false),
            new OptionDefinition('format', 'Output format', 'table'),
        ]);

        self::assertTrue($input->option('dry-run'));
        self::assertSame('a@x', $input->option('email'));
        self::assertFalse($input->option('verbose'), 'declared flag not passed → its default');
        self::assertSame('table', $input->option('format'), 'declared value option not passed → its default');
        self::assertSame(['admin'], $input->arguments);
    }

    #[Test]
    public function optionFallsBackToCallerDefaultForUndeclaredOrNullOptions(): void
    {
        $input = ConsoleInput::parse('sync', [], [], [
            new OptionDefinition('since', 'Lower bound', null),
        ]);

        self::assertNull($input->option('since'));
        self::assertSame('1970', $input->option('since', '1970'), 'declared default null → caller default');
        self::assertNull($input->option('unknown'));
        self::assertFalse($input->option('unknown', false));
    }

    #[Test]
    public function optionMatchesReadingTheArrayDirectly(): void
    {
        $input = new ConsoleInput('x', [], [], ['watch' => true, 'n' => '3']);

        self::assertSame($input->options['watch'] ?? false, $input->option('watch', false));
        self::assertSame($input->options['n'] ?? null, $input->option('n'));
    }
}
