<?php

namespace Tests\Pronchev\Pinecore\Fixtures\Orm;

use Pronchev\Pinecore\Model\MongoEntity;
use Pronchev\Pinecore\Orm\Attributes\Collection;
use Pronchev\Pinecore\Orm\Attributes\Field;
use Pronchev\Pinecore\Orm\Attributes\Id;
use Pronchev\Pinecore\Orm\Attributes\Index;

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
