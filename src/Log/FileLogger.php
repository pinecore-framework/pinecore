<?php

namespace Pronchev\Pinecore\Log;

use Pronchev\Pinecore\Config;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

final class FileLogger extends AbstractLogger
{
    private readonly int    $minLevel;
    private readonly string $channel;
    /** @var resource */
    private readonly mixed  $handle;

    public function __construct(Config $config)
    {
        $this->minLevel = self::levelValue($config->get('log.level', 'debug'));
        $this->channel  = $config->get('log.channel', 'app');
        $this->handle   = fopen($config->get('log.file'), 'a');
    }

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        if (self::levelValue((string) $level) < $this->minLevel) {
            return;
        }

        $entry = [
            'ts'      => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z'),
            'level'   => $level,
            'channel' => $this->channel,
            'message' => self::interpolate((string) $message, $context),
        ];

        if ($context !== []) {
            $entry['context'] = self::serializeContext($context);
        }

        fwrite($this->handle, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    private static function interpolate(string $message, array $context): string
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (!is_array($value) && (!is_object($value) || method_exists($value, '__toString'))) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }
        return strtr($message, $replacements);
    }

    private static function serializeContext(array $context): array
    {
        $result = [];
        foreach ($context as $key => $value) {
            $result[$key] = $value instanceof \Throwable ? self::serializeThrowable($value) : $value;
        }
        return $result;
    }

    private static function serializeThrowable(\Throwable $e): array
    {
        return [
            'class'   => $e::class,
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => $e->getTraceAsString(),
        ];
    }

    private static function levelValue(string $level): int
    {
        return match ($level) {
            LogLevel::DEBUG     => 0,
            LogLevel::INFO      => 1,
            LogLevel::NOTICE    => 2,
            LogLevel::WARNING   => 3,
            LogLevel::ERROR     => 4,
            LogLevel::CRITICAL  => 5,
            LogLevel::ALERT     => 6,
            LogLevel::EMERGENCY => 7,
            default             => 0,
        };
    }
}
