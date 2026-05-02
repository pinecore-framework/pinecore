<?php

namespace Tests\Pronchev\Pinecore;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pronchev\Pinecore\Config;

final class ConfigTest extends TestCase
{
    // -------- requireString --------

    #[Test]
    public function requireStringReturnsValueWhenPresent(): void
    {
        $config = new Config(['jwt' => ['secret' => 'abcd']]);

        self::assertSame('abcd', $config->requireString('jwt.secret'));
    }

    #[Test]
    public function requireStringThrowsWhenMissing(): void
    {
        $config = new Config([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing required key "jwt.secret"');

        $config->requireString('jwt.secret');
    }

    #[Test]
    public function requireStringThrowsOnWrongType(): void
    {
        $config = new Config(['jwt' => ['secret' => 42]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"jwt.secret" must be string, got int');

        $config->requireString('jwt.secret');
    }

    // -------- requireInt --------

    #[Test]
    public function requireIntReturnsValueWhenPresent(): void
    {
        $config = new Config(['jwt' => ['ttl' => 900]]);

        self::assertSame(900, $config->requireInt('jwt.ttl'));
    }

    #[Test]
    public function requireIntRejectsNumericString(): void
    {
        // No silent coercion: "900" is not int.
        $config = new Config(['jwt' => ['ttl' => '900']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"jwt.ttl" must be int, got string');

        $config->requireInt('jwt.ttl');
    }

    // -------- requireBool --------

    #[Test]
    public function requireBoolReturnsValueWhenPresent(): void
    {
        $config = new Config(['flag' => true]);

        self::assertTrue($config->requireBool('flag'));
    }

    #[Test]
    public function requireBoolRejectsTruthyString(): void
    {
        $config = new Config(['flag' => 'yes']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"flag" must be bool, got string');

        $config->requireBool('flag');
    }

    // -------- requireArray --------

    #[Test]
    public function requireArrayReturnsValueWhenPresent(): void
    {
        $config = new Config(['cors' => ['origins' => ['*']]]);

        self::assertSame(['origins' => ['*']], $config->requireArray('cors'));
    }

    #[Test]
    public function requireArrayThrowsOnNull(): void
    {
        $config = new Config(['cors' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"cors" must be array, got null');

        $config->requireArray('cors');
    }

    // -------- requireStringList --------

    #[Test]
    public function requireStringListReturnsListOfStrings(): void
    {
        $config = new Config(['origins' => ['a', 'b', 'c']]);

        self::assertSame(['a', 'b', 'c'], $config->requireStringList('origins'));
    }

    #[Test]
    public function requireStringListRejectsAssociativeArray(): void
    {
        $config = new Config(['origins' => ['x' => 'a', 'y' => 'b']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"origins" must be a list of strings, got associative array');

        $config->requireStringList('origins');
    }

    #[Test]
    public function requireStringListRejectsNonStringElement(): void
    {
        $config = new Config(['origins' => ['a', 42, 'c']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"origins" must be a list of strings, got int at index 1');

        $config->requireStringList('origins');
    }

    // -------- getString --------

    #[Test]
    public function getStringReturnsDefaultWhenMissing(): void
    {
        $config = new Config([]);

        self::assertSame('debug', $config->getString('log.level', 'debug'));
    }

    #[Test]
    public function getStringReturnsValueWhenPresent(): void
    {
        $config = new Config(['log' => ['level' => 'warning']]);

        self::assertSame('warning', $config->getString('log.level', 'debug'));
    }

    #[Test]
    public function getStringThrowsOnWrongTypeEvenWithDefault(): void
    {
        $config = new Config(['log' => ['level' => ['debug']]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"log.level" must be string, got array');

        $config->getString('log.level', 'debug');
    }

    // -------- getInt --------

    #[Test]
    public function getIntReturnsDefaultWhenMissing(): void
    {
        $config = new Config([]);

        self::assertSame(8388608, $config->getInt('http.max_body_bytes', 8388608));
    }

    #[Test]
    public function getIntDoesNotSilentlyCastString(): void
    {
        // Old (int) cast turned "8mb" into 8 — that's the regression we want.
        $config = new Config(['http' => ['max_body_bytes' => '8mb']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"http.max_body_bytes" must be int, got string');

        $config->getInt('http.max_body_bytes', 1024);
    }

    // -------- getBool --------

    #[Test]
    public function getBoolReturnsDefaultWhenMissing(): void
    {
        $config = new Config([]);

        self::assertFalse($config->getBool('app.cors.allow_credentials', false));
    }

    #[Test]
    public function getBoolRejectsTruthyString(): void
    {
        $config = new Config(['app' => ['cors' => ['allow_credentials' => 'yes']]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"app.cors.allow_credentials" must be bool, got string');

        $config->getBool('app.cors.allow_credentials', false);
    }

    // -------- getArray --------

    #[Test]
    public function getArrayReturnsDefaultWhenMissing(): void
    {
        $config = new Config([]);

        self::assertSame([], $config->getArray('orm.entities', []));
    }

    #[Test]
    public function getArrayRejectsScalar(): void
    {
        // Old (array) cast wrapped 'Foo' into ['Foo'] — silent and misleading.
        $config = new Config(['orm' => ['entities' => 'App\\User']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"orm.entities" must be array, got string');

        $config->getArray('orm.entities', []);
    }

    // -------- legacy get() compatibility --------

    #[Test]
    public function legacyGetStillWorksForMixedAccess(): void
    {
        $config = new Config(['anything' => ['nested' => 42]]);

        self::assertSame(42, $config->get('anything.nested'));
        self::assertNull($config->get('missing'));
        self::assertSame('fallback', $config->get('missing', 'fallback'));
    }
}
