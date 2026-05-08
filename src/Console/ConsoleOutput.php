<?php

namespace Pinecore\Pinecore\Console;

use League\CLImate\CLImate;
use League\CLImate\TerminalObject\Dynamic\Progress;

final class ConsoleOutput
{
    private int $exitCode = 0;

    public function __construct(public readonly CLImate $climate = new CLImate())
    {
    }

    public function write(string $text): void
    {
        $this->climate->inline($text);
    }

    public function writeln(string $line = ''): void
    {
        $this->climate->out($line);
    }

    public function success(string $line): void
    {
        $this->climate->out("<green>✓</green> {$line}");
    }

    public function info(string $line): void
    {
        $this->climate->out("<info>{$line}</info>");
    }

    public function warn(string $line): void
    {
        $this->climate->out("<yellow>⚠</yellow> {$line}");
    }

    public function comment(string $line): void
    {
        $this->climate->out("<comment>{$line}</comment>");
    }

    public function error(string $line): void
    {
        $this->climate->to('error')->out("<error>{$line}</error>");
    }

    public function progress(int $total): Progress
    {
        return $this->climate->progress()->total($total);
    }

    /** @param list<array<string, mixed>> $rows */
    public function table(array $rows): void
    {
        $this->climate->table($rows);
    }

    public function setExitCode(int $code): void
    {
        $this->exitCode = $code;
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }
}
