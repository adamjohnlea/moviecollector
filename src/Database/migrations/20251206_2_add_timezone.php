<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use App\Services\LoggerService as Logger;

class Migration20251206_2
{
    public function up(\PDO $db): void
    {
        Logger::info('Running migration: AddTimezoneToUserSettings - up');

        // Ensure user_settings table exists
        $exists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='user_settings'")
            ->fetchColumn();
        if (!$exists) {
            Logger::warning('user_settings table does not exist; skipping timezone migration');
            return;
        }

        // Check if timezone column already exists
        $cols = $db->query("PRAGMA table_info(user_settings)")->fetchAll(\PDO::FETCH_ASSOC);
        $hasTz = false;
        foreach ($cols as $col) {
            if (strcasecmp($col['name'] ?? '', 'timezone') === 0) { $hasTz = true; break; }
        }
        if (!$hasTz) {
            $db->exec("ALTER TABLE user_settings ADD COLUMN timezone TEXT DEFAULT 'UTC'");
            Logger::info('Added timezone column to user_settings');
        } else {
            Logger::info('Timezone column already exists on user_settings');
        }
    }

    public function down(\PDO $db): void
    {
        // SQLite cannot drop columns easily; leave as-is.
        Logger::info('Down migration for AddTimezoneToUserSettings is a no-op');
    }
}
