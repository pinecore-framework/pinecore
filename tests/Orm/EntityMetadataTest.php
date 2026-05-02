<?php

namespace Tests\Pinecore\Pinecore\Orm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Orm\EntityMetadata;

final class EntityMetadataTest extends TestCase
{
    #[Test]
    #[DataProvider('isNewIdCases')]
    public function isNewIdRecognisesEmptyAndNull(mixed $value, bool $expected): void
    {
        self::assertSame($expected, EntityMetadata::isNewId($value));
    }

    public static function isNewIdCases(): array
    {
        return [
            'empty string' => ['', true],
            'null'         => [null, true],
            'string id'    => ['abc', false],
            'zero string'  => ['0', false],
            'integer zero' => [0, false],
            'whitespace'   => [' ', false],
        ];
    }
}
