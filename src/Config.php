<?php

namespace Pinecore\Pinecore;

class Config
{
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function load(string $configDir, Environment $env): self
    {
        $base = self::loadDir($configDir);
        $envOverride = self::loadEnvOverride($configDir . '/env', $env->name());

        return new self(self::merge($base, $envOverride));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->find($key, $found) ? $found : $default;
    }

    public function requireString(string $key): string
    {
        $value = $this->requireKey($key);
        return is_string($value) ? $value : $this->typeError($key, 'string', $value);
    }

    public function requireInt(string $key): int
    {
        $value = $this->requireKey($key);
        return is_int($value) ? $value : $this->typeError($key, 'int', $value);
    }

    public function requireBool(string $key): bool
    {
        $value = $this->requireKey($key);
        return is_bool($value) ? $value : $this->typeError($key, 'bool', $value);
    }

    /** @return array<int|string, mixed> */
    public function requireArray(string $key): array
    {
        $value = $this->requireKey($key);
        return is_array($value) ? $value : $this->typeError($key, 'array', $value);
    }

    /** @return list<string> */
    public function requireStringList(string $key): array
    {
        $value = $this->requireArray($key);
        if (array_values($value) !== $value) {
            throw new \RuntimeException(
                "Config: key \"{$key}\" must be a list of strings, got associative array"
            );
        }
        foreach ($value as $i => $item) {
            if (!is_string($item)) {
                $type = get_debug_type($item);
                throw new \RuntimeException(
                    "Config: key \"{$key}\" must be a list of strings, got {$type} at index {$i}"
                );
            }
        }
        return $value;
    }

    public function getString(string $key, string $default): string
    {
        if (!$this->find($key, $value)) return $default;
        return is_string($value) ? $value : $this->typeError($key, 'string', $value);
    }

    public function getInt(string $key, int $default): int
    {
        if (!$this->find($key, $value)) return $default;
        return is_int($value) ? $value : $this->typeError($key, 'int', $value);
    }

    public function getBool(string $key, bool $default): bool
    {
        if (!$this->find($key, $value)) return $default;
        return is_bool($value) ? $value : $this->typeError($key, 'bool', $value);
    }

    /**
     * @param  array<int|string, mixed> $default
     * @return array<int|string, mixed>
     */
    public function getArray(string $key, array $default): array
    {
        if (!$this->find($key, $value)) return $default;
        return is_array($value) ? $value : $this->typeError($key, 'array', $value);
    }

    /**
     * Deep-lookup by dot-notation. Returns true if the key exists; the value
     * is written to $value (out-param). Returns false if any path segment
     * is missing.
     */
    private function find(string $key, mixed &$value): bool
    {
        $parts = explode('.', $key);
        $cursor = $this->data;
        foreach ($parts as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return false;
            }
            $cursor = $cursor[$part];
        }
        $value = $cursor;
        return true;
    }

    private function requireKey(string $key): mixed
    {
        if (!$this->find($key, $value)) {
            throw new \RuntimeException("Config: missing required key \"{$key}\"");
        }
        return $value;
    }

    private function typeError(string $key, string $expected, mixed $actual): never
    {
        $type = get_debug_type($actual);
        throw new \RuntimeException("Config: key \"{$key}\" must be {$expected}, got {$type}");
    }

    private static function loadDir(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $result = [];
        foreach (glob($dir . '/*.php') as $file) {
            $key = basename($file, '.php');
            $result[$key] = require $file;
        }

        return $result;
    }

    private static function loadEnvOverride(string $envDir, string $envName): array
    {
        $files = [];

        $envFile = $envDir . '/' . $envName . '.php';
        if (file_exists($envFile)) {
            $files[] = require $envFile;
        }

        $localFile = $envDir . '/local.php';
        if (file_exists($localFile)) {
            $files[] = require $localFile;
        }

        $result = [];
        foreach ($files as $override) {
            $result = self::merge($result, $override);
        }

        return $result;
    }

    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                is_array($value)
                && array_key_exists($key, $base)
                && is_array($base[$key])
                && !self::isList($value)
                && !self::isList($base[$key])
            ) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    private static function isList(array $arr): bool
    {
        return array_values($arr) === $arr;
    }
}
