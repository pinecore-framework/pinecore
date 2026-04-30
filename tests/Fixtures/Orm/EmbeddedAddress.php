<?php

namespace Tests\Pronchev\Pinecore\Fixtures\Orm;

use Pronchev\Pinecore\Orm\Attributes\Field;

final class EmbeddedAddress
{
    public function __construct(
        #[Field(name: 'street_name')] public readonly string $streetName,
        #[Field]                      public readonly string $city,
    ) {}
}
