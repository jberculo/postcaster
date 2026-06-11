<?php

namespace Justbee\PostCaster\Models;

if (!defined('ABSPATH')) {
    exit;
}

final class DebugLogModel
{
    private const OPTION_NAME = 'justbee_postcaster_debug_log';
    private const MAX_ENTRIES = 200;

    public function append(string $message): void
    {
        $entries = $this->getAll();
        $entries[] = [
            'timestamp' => gmdate('c'),
            'message' => $message,
        ];
        $entries = array_slice($entries, -self::MAX_ENTRIES);

        update_option(self::OPTION_NAME, $entries, false);
    }

    public function getAll(): array
    {
        $entries = get_option(self::OPTION_NAME, []);
        return is_array($entries) ? $entries : [];
    }

    public function clear(): void
    {
        delete_option(self::OPTION_NAME);
    }
}
