<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Model\MongoEntity;
use Pinecore\Pinecore\Orm\Attributes\Collection;
use Pinecore\Pinecore\Orm\Attributes\Field;
use Pinecore\Pinecore\Orm\Attributes\Id;
use Pinecore\Pinecore\Orm\Attributes\Index;

#[Collection(name: 'indexed_entities')]
final class IndexedEntity implements MongoEntity
{
    public function __construct(
        #[Id]
        public readonly string $id,

        #[Field]
        #[Index(unique: true, name: 'uniq_email')]
        public readonly string $email,

        #[Field(name: 'created_at')]
        #[Index(direction: -1, expireAfterSeconds: 3600)]
        public readonly int $createdAt,

        #[Field]
        #[Index(sparse: true)]
        public readonly ?string $referralCode = null,

        // No #[Index] on this property — should not appear in indexes
        #[Field]
        public readonly ?string $name = null,
    ) {}
}
