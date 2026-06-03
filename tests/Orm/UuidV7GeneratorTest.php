<?php

namespace Tests\Pinecore\Pinecore\Orm;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Orm\IdGenerator;
use Pinecore\Pinecore\Orm\IdGeneratorInterface;
use Pinecore\Pinecore\Orm\UuidV7Generator;

final class UuidV7GeneratorTest extends TestCase
{
    private UuidV7Generator $generator;

    protected function setUp(): void
    {
        $this->generator = new UuidV7Generator();
    }

    #[Test]
    public function bothGeneratorsImplementTheInterface(): void
    {
        self::assertInstanceOf(IdGeneratorInterface::class, new IdGenerator());
        self::assertInstanceOf(IdGeneratorInterface::class, new UuidV7Generator());
    }

    #[Test]
    public function producesCanonicalUuidV7WithCorrectVersionAndVariant(): void
    {
        // 3rd group starts with 7 (version), 4th group with 8/9/a/b (variant 10xx).
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

        for ($i = 0; $i < 100; $i++) {
            self::assertMatchesRegularExpression($pattern, $this->generator->generate());
        }
    }

    #[Test]
    public function isMonotonicAcrossManyIds(): void
    {
        $previous = '';
        for ($i = 0; $i < 5000; $i++) {
            $id = $this->generator->generate();
            self::assertGreaterThan($previous, $id, "id #{$i} not greater than previous");
            $previous = $id;
        }
    }

    #[Test]
    public function idsAreUnique(): void
    {
        $ids = [];
        for ($i = 0; $i < 5000; $i++) {
            $ids[] = $this->generator->generate();
        }

        self::assertCount(count($ids), array_unique($ids));
    }
}
