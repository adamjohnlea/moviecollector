<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Migration;
use App\Services\LoggerService as Logger;

class Migration20251206_1 extends Migration
{
    public function up(\PDO $db): void
    {
        Logger::info('Running migration: AddWatchedLogs - up');

        // Create watched_logs table
        $db->exec("
            CREATE TABLE IF NOT EXISTS watched_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                tmdb_id INTEGER NOT NULL,
                watched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                source TEXT,
                format_id INTEGER,
                location TEXT,
                runtime_minutes INTEGER,
                rating INTEGER,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            )
        ");

        // Indexes to speed up queries
        $db->exec("CREATE INDEX IF NOT EXISTS idx_watched_logs_user_tmdb ON watched_logs(user_id, tmdb_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_watched_logs_user_date ON watched_logs(user_id, watched_at DESC)");

        // Add roll-up columns to movies table if not present
        // SQLite supports ADD COLUMN idempotently when guarded
        $columns = $db->query("PRAGMA table_info(movies)")->fetchAll(\PDO::FETCH_ASSOC);
        $hasWatchedCount = false;
        $hasLastWatchedAt = false;
        foreach ($columns as $col) {
            if (strcasecmp($col['name'], 'watched_count') === 0) { $hasWatchedCount = true; }
            if (strcasecmp($col['name'], 'last_watched_at') === 0) { $hasLastWatchedAt = true; }
        }
        if (!$hasWatchedCount) {
            $db->exec("ALTER TABLE movies ADD COLUMN watched_count INTEGER DEFAULT 0");
        }
        if (!$hasLastWatchedAt) {
            $db->exec("ALTER TABLE movies ADD COLUMN last_watched_at TIMESTAMP NULL");
        }

        Logger::info('Successfully added watched_logs and movie roll-up columns');
    }

    public function down(\PDO $db): void
    {
        Logger::info('Running migration: AddWatchedLogs - down');

        // Drop watched_logs table
        $db->exec("DROP TABLE IF EXISTS watched_logs");

        // Note: We will not drop columns from movies to avoid data loss and complex table copy operations.
    }
}
