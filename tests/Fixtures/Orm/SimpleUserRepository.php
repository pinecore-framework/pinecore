<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Orm\AbstractMongoRepository;
use Pinecore\Pinecore\Orm\Attributes\ForEntity;

#[ForEntity(SimpleUser::class)]
final class SimpleUserRepository extends AbstractMongoRepository
{
    public function save(SimpleUser $user): SimpleUser
    {
        return $this->persist($user);
    }

    public function find(string $id): ?SimpleUser
    {
        return $this->findById($id);
    }
}
