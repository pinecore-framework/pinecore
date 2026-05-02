<?php

namespace Tests\Pronchev\Pinecore\Log;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pronchev\Pinecore\Log\JsonStreamLogger;
use Psr\Log\LogLevel;

final class JsonStreamLoggerTest extends TestCase
{
    #[Test]
    public function writesJsonLineWithExpectedFields(): void
    {
        $logger = self::makeLogger($stream);

        $logger->error('boom');

        $entry = self::readSingleEntry($stream);
        self::assertSame('error', $entry['level']);
        self::assertSame('app', $entry['channel']);
        self::assertSame('boom', $entry['message']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $entry['ts'],
        );
        self::assertArrayNotHasKey('context', $entry);
    }

    #[Test]
    public function belowMinLevelMessageIsDropped(): void
    {
        $logger = self::makeLogger($stream, minLevel: JsonStreamLogger::levelValue(LogLevel::WARNING));

        $logger->info('ignored');

        rewind($stream);
        self::assertSame('', stream_get_contents($stream));
    }

    #[Test]
    public function psrInterpolationReplacesPlaceholders(): void
    {
        $logger = self::makeLogger($stream);

        $logger->info('user {id} did {action}', ['id' => 42, 'action' => 'login']);

        $entry = self::readSingleEntry($stream);
        self::assertSame('user 42 did login', $entry['message']);
        self::assertSame(['id' => 42, 'action' => 'login'], $entry['context']);
    }

    #[Test]
    public function throwableInContextIsSerializedToFields(): void
    {
        $logger = self::makeLogger($stream);
        $error  = new \RuntimeException('kaboom');

        $logger->error('failure', ['exception' => $error]);

        $entry = self::readSingleEntry($stream);
        $exc   = $entry['context']['exception'];
        self::assertSame(\RuntimeException::class, $exc['class']);
        self::assertSame('kaboom', $exc['message']);
        self::assertArrayHasKey('file', $exc);
        self::assertArrayHasKey('line', $exc);
        self::assertArrayHasKey('trace', $exc);
    }

    #[Test]
    public function nonScalarContextValuesArePassedThroughAndNotInterpolated(): void
    {
        $logger = self::makeLogger($stream);

        $logger->info('payload {data}', ['data' => ['k' => 'v']]);

        $entry = self::readSingleEntry($stream);
        // {data} stays literal because the value is an array
        self::assertSame('payload {data}', $entry['message']);
        self::assertSame(['k' => 'v'], $entry['context']['data']);
    }

    #[Test]
    public function unknownLevelStringFallsBackToDebug(): void
    {
        self::assertSame(0, JsonStreamLogger::levelValue('not-a-level'));
        self::assertSame(0, JsonStreamLogger::levelValue(LogLevel::DEBUG));
    }

    #[Test]
    public function customChannelIsEmitted(): void
    {
        $logger = self::makeLogger($stream, channel: 'billing');

        $logger->warning('check');

        self::assertSame('billing', self::readSingleEntry($stream)['channel']);
    }

    #[Test]
    public function resourceInContextDoesNotCrashAndProducesFallbackEntry(): void
    {
        $logger = self::makeLogger($stream);
        $resource = fopen('php://memory', 'r');

        $logger->error('db down', ['conn' => $resource]);

        $entry = self::readSingleEntry($stream);
        self::assertSame('error', $entry['level']);
        self::assertSame('db down', $entry['message']);
        self::assertArrayHasKey('_logger_error', $entry);
        self::assertStringContainsString('context not encodable', $entry['_logger_error']);
        self::assertArrayNotHasKey('context', $entry);

        fclose($resource);
    }

    #[Test]
    public function circularReferenceInContextProducesFallbackEntry(): void
    {
        $logger = self::makeLogger($stream);
        $a = new \stdClass();
        $b = new \stdClass();
        $a->b = $b;
        $b->a = $a;

        $logger->error('cycle', ['node' => $a]);

        $entry = self::readSingleEntry($stream);
        self::assertSame('cycle', $entry['message']);
        self::assertArrayHasKey('_logger_error', $entry);
        self::assertArrayNotHasKey('context', $entry);
    }

    #[Test]
    public function invalidUtf8InContextStringIsSubstitutedNotFallback(): void
    {
        $logger = self::makeLogger($stream);
        $brokenUtf8 = "valid \xC3\x28 broken";

        $logger->info('payload', ['raw' => $brokenUtf8]);

        $entry = self::readSingleEntry($stream);
        self::assertSame('payload', $entry['message']);
        self::assertArrayHasKey('context', $entry);
        self::assertArrayNotHasKey('_logger_error', $entry);
        // U+FFFD substitution kicked in — no JsonException, full entry preserved
        self::assertStringContainsString('valid', $entry['context']['raw']);
        self::assertStringContainsString('broken', $entry['context']['raw']);
    }

    /**
     * @param resource|null $stream  out-param: opened php://memory stream
     */
    private static function makeLogger(
        &$stream,
        int    $minLevel = 0,
        string $channel = 'app',
    ): JsonStreamLogger {
        $stream = fopen('php://memory', 'w+b');
        return new JsonStreamLogger($stream, $minLevel, $channel);
    }

    /**
     * @param resource $stream
     * @return array<string, mixed>
     */
    private static function readSingleEntry(mixed $stream): array
    {
        rewind($stream);
        $raw = stream_get_contents($stream);
        $line = trim($raw);
        return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    }
}
