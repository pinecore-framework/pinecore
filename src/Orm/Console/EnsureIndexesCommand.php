<?php

namespace Pinecore\Pinecore\Orm\Console;

use MongoDB\Database;
use Pinecore\Pinecore\Config;
use Pinecore\Pinecore\Console\ConsoleInput;
use Pinecore\Pinecore\Console\ConsoleOutput;
use Pinecore\Pinecore\Orm\EntityMap;
use Pinecore\Pinecore\Orm\IndexMetadata;

final class EnsureIndexesCommand
{
    public function __construct(
        private readonly Config   $config,
        private readonly Database $db,
    ) {}

    public function __invoke(ConsoleInput $input, ConsoleOutput $output): void
    {
        $entities = $this->config->getArray('orm.entities', []);
        if ($entities === []) {
            $output->error('No entities configured (set orm.entities in config)');
            $output->setExitCode(1);
            return;
        }

        $dryRun = (bool) ($input->options['dry-run'] ?? false);

        foreach ($entities as $class) {
            $map = EntityMap::of($class);
            if ($map->collectionName === null) {
                continue;
            }

            foreach ($map->indexes as $idx) {
                $keys = [$idx->field => $idx->direction];
                $opts = self::buildOptions($idx);

                if ($dryRun) {
                    $output->comment(
                        "[dry-run] {$map->collectionName}.{$idx->field} "
                        . json_encode(['keys' => $keys, 'options' => $opts])
                    );
                    continue;
                }

                $this->db->selectCollection($map->collectionName)->createIndex($keys, $opts);
                $label = $idx->name ?? 'auto';
                $output->success("{$map->collectionName}.{$idx->field} ({$label})");
            }
        }
    }

    /** @return array<string, mixed> */
    private static function buildOptions(IndexMetadata $idx): array
    {
        $opts = [];
        if ($idx->unique) {
            $opts['unique'] = true;
        }
        if ($idx->sparse) {
            $opts['sparse'] = true;
        }
        if ($idx->name !== null) {
            $opts['name'] = $idx->name;
        }
        if ($idx->expireAfterSeconds !== null) {
            $opts['expireAfterSeconds'] = $idx->expireAfterSeconds;
        }
        return $opts;
    }
}
