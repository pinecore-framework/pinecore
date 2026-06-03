<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Model\SqlEntity;
use Pinecore\Pinecore\Orm\Attributes\Id;

/** SqlEntity without the required #[Table] attribute — EntityMap must reject it. */
final class SqlEntityMissingTable implements SqlEntity
{
    public function __construct(
        #[Id] public readonly string $id,
    ) {}
}
