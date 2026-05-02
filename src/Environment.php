<?php

namespace Pinecore\Pinecore;

class Environment
{
    private string $name;

    private function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function detect(): self
    {
        return new self(getenv('APP_ENV') ?: 'dev');
    }

    public function name(): string
    {
        return $this->name;
    }

    public function is(string $name): bool
    {
        return $this->name === $name;
    }

    public function isProd(): bool
    {
        return $this->name === 'prod';
    }
}
