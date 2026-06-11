<?php

namespace Justbee\PostCaster\Support;

if (!defined('ABSPATH')) {
    exit;
}

trait NormalizesTemplate
{
    protected function normalizeTemplate(string $template): string
    {
        return str_replace(["\r\n", "\r"], "\n", $template);
    }
}
