<?php

namespace Justbee\PostCaster\Views;

if (!defined('ABSPATH')) {
    exit;
}

final class ScriptRenderer
{
    public function renderTabScript(string $tabAttribute, string $panelAttribute, string $storageKey = ''): void
    {
        unset($tabAttribute, $panelAttribute, $storageKey);
    }
}

