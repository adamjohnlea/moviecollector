<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use App\Services\LoggerService as Logger;

class Migration20251206_3
{
    public function up(\PDO $db): void
    {
        Logger::info('Running migration: AddAutoRemoveSetting - up');

        // Ensure user_settings table exists
        $exists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='user_settings'")
            ->fetchColumn();
        if (!$exists) {
            Logger::warning('user_settings table does not exist; skipping auto-remove setting migration');
            return;
        }

        // Check if column already exists
        $cols = $db->query("PRAGMA table_info(user_settings)")->fetchAll(\PDO::FETCH_ASSOC);
        $hasCol = false;
        foreach ($cols as $col) {
            if (strcasecmp($col['name'] ?? '', 'remove_from_to_watch_on_watched') === 0) { $hasCol = true; break; }
        }
        if (!$hasCol) {
            $db->exec("ALTER TABLE user_settings ADD COLUMN remove_from_to_watch_on_watched INTEGER DEFAULT 0");
            Logger::info('Added remove_from_to_watch_on_watched column to user_settings');
        } else {
            Logger::info('remove_from_to_watch_on_watched already exists on user_settings');
        }
    }

    public function down(\PDO $db): void
    {
        // SQLite cannot drop columns easily; leave as-is.
        Logger::info('Down migration for AddAutoRemoveSetting is a no-op');
    }
}
