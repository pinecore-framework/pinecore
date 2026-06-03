<?php

namespace Pinecore\Pinecore\Orm;

/**
 * Strategy for generating string primary keys assigned by repositories when an
 * entity is persisted with an empty #[Id]. Bind an implementation in the app's
 * services.php to override the default (IdGenerator).
 */
interface IdGeneratorInterface
{
    public function generate(): string;
}
