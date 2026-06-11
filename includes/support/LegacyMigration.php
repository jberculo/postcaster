<?php

namespace Justbee\PostCaster\Support;

use Justbee\PostCaster\Support\Migrations\MigrationRunner;

if (!defined('ABSPATH')) {
    exit;
}

final class LegacyMigration
{
    public static function run(): void
    {
        MigrationRunner::runAll();
    }
}
