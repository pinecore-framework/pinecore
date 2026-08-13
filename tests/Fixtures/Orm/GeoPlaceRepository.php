<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Orm\AbstractSqlRepository;
use Pinecore\Pinecore\Orm\Attributes\ForEntity;

#[ForEntity(GeoPlace::class)]
final class GeoPlaceRepository extends AbstractSqlRepository
{
    public function find(string $id): ?GeoPlace
    {
        return $this->findById($id);
    }

    public function bySource(string $source): ?GeoPlace
    {
        return $this->findOneWhere(['source' => $source]);
    }
}
