<?php

namespace Justbee\PostCaster\Support\Migrations;

if (!defined('ABSPATH')) {
    exit;
}

interface MigrationInterface
{
    public function id(): string;

    public function migrate(): void;
}
