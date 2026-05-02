<?php

namespace Pinecore\Pinecore\Console;

final class ConsoleOutput
{
    private int $exitCode = 0;

    public function write(string $text): void
    {
        echo $text;
    }

    public function writeln(string $line = ''): void
    {
        echo $line . PHP_EOL;
    }

    public function error(string $line): void
    {
        fwrite(STDERR, $line . PHP_EOL);
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
