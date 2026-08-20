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

    /**
     * @param array<string, mixed>  $filter
     * @param array<string, string> $orderBy
     * @return list<SimpleUser>
     */
    public function listWhere(array $filter, array $orderBy = [], ?int $limit = null, ?int $offset = null): array
    {
        return $this->findManyWhere($filter, $orderBy, $limit, $offset);
    }
}
