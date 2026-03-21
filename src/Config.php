<?php

namespace Pronchev\Pinecore;

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
        $parts = explode('.', $key);
        $value = $this->data;

        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
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
