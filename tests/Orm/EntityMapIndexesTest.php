<?php

namespace Tests\Pinecore\Pinecore\Orm;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Orm\EntityMap;
use Pinecore\Pinecore\Orm\IndexMetadata;
use Tests\Pinecore\Pinecore\Fixtures\Orm\IndexedEntity;
use Tests\Pinecore\Pinecore\Fixtures\Orm\IndexedEntityNoField;
use Tests\Pinecore\Pinecore\Fixtures\Orm\SimpleUser;

final class EntityMapIndexesTest extends TestCase
{
    #[Test]
    public function entityWithoutIndexAttributesHasEmptyIndexes(): void
    {
        $map = EntityMap::of(SimpleUser::class);

        self::assertSame([], $map->indexes);
    }

    #[Test]
    public function uniqueIndexParsesAllOptions(): void
    {
        $map = EntityMap::of(IndexedEntity::class);

        $email = self::findIndex($map->indexes, 'email');
        self::assertNotNull($email);
        self::assertSame(1, $email->direction);
        self::assertTrue($email->unique);
        self::assertFalse($email->sparse);
        self::assertSame('uniq_email', $email->name);
        self::assertNull($email->expireAfterSeconds);
    }

    #[Test]
    public function fieldRenameIsHonouredInIndexFieldName(): void
    {
        $map = EntityMap::of(IndexedEntity::class);

        // #[Field(name: 'created_at')] — index must use BSON name, not property name
        $createdAt = self::findIndex($map->indexes, 'created_at');
        self::assertNotNull($createdAt);
        self::assertSame(-1, $createdAt->direction);
        self::assertSame(3600, $createdAt->expireAfterSeconds);
    }

    #[Test]
    public function sparseFlagIsParsed(): void
    {
        $map = EntityMap::of(IndexedEntity::class);

        $referral = self::findIndex($map->indexes, 'referralCode');
        self::assertNotNull($referral);
        self::assertTrue($referral->sparse);
        self::assertFalse($referral->unique);
    }

    #[Test]
    public function indexCountMatchesDeclaredAttributes(): void
    {
        $map = EntityMap::of(IndexedEntity::class);

        // 3 indexed properties: email, createdAt (=created_at), referralCode
        // The 'name' field has no #[Index] and must be absent
        self::assertCount(3, $map->indexes);
        self::assertNull(self::findIndex($map->indexes, 'name'));
    }

    #[Test]
    public function indexWithoutFieldOrIdThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/#\[Index\] on .+::\$orphan requires #\[Field\] or #\[Id\]/');

        EntityMap::of(IndexedEntityNoField::class);
    }

    /**
     * @param list<IndexMetadata> $indexes
     */
    private static function findIndex(array $indexes, string $field): ?IndexMetadata
    {
        foreach ($indexes as $idx) {
            if ($idx->field === $field) {
                return $idx;
            }
        }
        return null;
    }
}
