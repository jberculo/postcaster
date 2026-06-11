<?php

namespace Justbee\PostCaster\Support\Migrations;

if (!defined('ABSPATH')) {
    exit;
}

final class MigrationRegistry
{
    /**
     * @return MigrationInterface[]
     */
    public static function all(): array
    {
        return [
            new MasterPrefixMigration(),
        ];
    }
}
