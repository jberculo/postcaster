<?php

namespace Justbee\PostCaster\Support\Migrations;

use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

final class MigrationRunner
{
    public const STATE_OPTION = 'justbee_postcaster_migrations';
    public const LOCK_OPTION = 'justbee_postcaster_migrations_lock';
    private const LOCK_TTL = 300;

    public static function runAll(): bool
    {
        return self::runMigrations(MigrationRegistry::all());
    }

    /**
     * @param MigrationInterface[] $migrations
     */
    public static function runMigrations(array $migrations): bool
    {
        if (self::hasNoPendingMigrations($migrations)) {
            return true;
        }

        if (!self::acquireLock()) {
            return false;
        }

        try {
            $state = self::getState();

            foreach ($migrations as $migration) {
                $id = $migration->id();
                if (isset($state['completed'][$id])) {
                    continue;
                }

                $state['current'] = $id;
                $state['updated_at'] = gmdate('c');
                self::saveState($state);

                try {
                    $migration->migrate();
                } catch (Throwable $error) {
                    $state = self::getState();
                    $state['current'] = null;
                    $state['failed'][$id] = [
                        'failed_at' => gmdate('c'),
                        'message' => $error->getMessage(),
                    ];
                    $state['updated_at'] = gmdate('c');
                    self::saveState($state);

                    return false;
                }

                $state = self::getState();
                $state['current'] = null;
                $state['completed'][$id] = gmdate('c');
                unset($state['failed'][$id]);
                $state['updated_at'] = gmdate('c');
                self::saveState($state);
            }
        } finally {
            self::releaseLock();
        }

        return true;
    }

    public static function getState(): array
    {
        $state = get_option(self::STATE_OPTION, []);
        if (!is_array($state)) {
            $state = [];
        }

        return [
            'completed' => isset($state['completed']) && is_array($state['completed']) ? $state['completed'] : [],
            'failed' => isset($state['failed']) && is_array($state['failed']) ? $state['failed'] : [],
            'current' => isset($state['current']) && is_string($state['current']) ? $state['current'] : null,
            'updated_at' => isset($state['updated_at']) && is_string($state['updated_at']) ? $state['updated_at'] : null,
        ];
    }

    /**
     * @param MigrationInterface[] $migrations
     */
    private static function hasNoPendingMigrations(array $migrations): bool
    {
        $completed = self::getState()['completed'];

        foreach ($migrations as $migration) {
            if (!isset($completed[$migration->id()])) {
                return false;
            }
        }

        return true;
    }

    private static function acquireLock(): bool
    {
        $payload = [
            'acquired_at' => time(),
        ];

        if (add_option(self::LOCK_OPTION, $payload, '', 'no')) {
            return true;
        }

        $existing = get_option(self::LOCK_OPTION, []);
        if (!is_array($existing) || self::isStaleLock($existing)) {
            update_option(self::LOCK_OPTION, $payload, false);
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $lock
     */
    private static function isStaleLock(array $lock): bool
    {
        $acquiredAt = isset($lock['acquired_at']) ? (int) $lock['acquired_at'] : 0;

        return $acquiredAt <= 0 || ($acquiredAt + self::LOCK_TTL) < time();
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function saveState(array $state): void
    {
        update_option(self::STATE_OPTION, $state, false);
    }

    private static function releaseLock(): void
    {
        delete_option(self::LOCK_OPTION);
    }
}
