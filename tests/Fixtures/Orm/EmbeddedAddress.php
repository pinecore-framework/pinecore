<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use Pinecore\Pinecore\Orm\Attributes\Field;

final class EmbeddedAddress
{
    public function __construct(
        #[Field(name: 'street_name')] public readonly string $streetName,
        #[Field]                      public readonly string $city,
    ) {}
}
