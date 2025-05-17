<?php
declare(strict_types=1);

namespace App\Database\Migrations;

class Migration20240401_1
{
    public function up(\PDO $db): void
    {
        echo "Running migration: AddDisplayPreferences - up\n";
        
        try {
            // Check if user_settings table exists
            $userSettingsTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='user_settings'")->fetchColumn();
            
            // Create user_settings table if it doesn't exist
            if (!$userSettingsTableExists) {
                // First check if users table exists, create it if not
                $usersTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
                
                if (!$usersTableExists) {
                    $db->exec("
                        CREATE TABLE IF NOT EXISTS users (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            username TEXT NOT NULL UNIQUE,
                            email TEXT NOT NULL UNIQUE,
                            password_hash TEXT NOT NULL,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        )
                    ");
                }
                
                $db->exec("
                    CREATE TABLE IF NOT EXISTS user_settings (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        tmdb_api_key TEXT,
                        tmdb_access_token TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
                    )
                ");
            }
            
            // Check if display_preferences already exists
            if ($userSettingsTableExists) {
                $hasColumn = false;
                $columnsStmt = $db->query("PRAGMA table_info(user_settings)");
                $columns = $columnsStmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($columns as $column) {
                    if ($column['name'] === 'display_preferences') {
                        $hasColumn = true;
                        break;
                    }
                }
                
                if (!$hasColumn) {
                    $db->exec("ALTER TABLE user_settings ADD COLUMN display_preferences TEXT");
                }
            }
            
            echo "Successfully added display_preferences column\n";
        } catch (\Exception $e) {
            echo "Failed to add display_preferences column: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    public function down(\PDO $db): void
    {
        echo "Running migration: AddDisplayPreferences - down\n";
        
        try {
            // Check if user_settings table exists
            $userSettingsTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='user_settings'")->fetchColumn();
            
            if ($userSettingsTableExists) {
                // Check if display_preferences exists
                $hasColumn = false;
                $columnsStmt = $db->query("PRAGMA table_info(user_settings)");
                $columns = $columnsStmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($columns as $column) {
                    if ($column['name'] === 'display_preferences') {
                        $hasColumn = true;
                        break;
                    }
                }
                
                if ($hasColumn) {
                    // SQLite doesn't support DROP COLUMN directly, so we need to create a new table
                    $db->exec("
                        CREATE TABLE user_settings_new (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            user_id INTEGER NOT NULL,
                            tmdb_api_key TEXT,
                            tmdb_access_token TEXT,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
                        )
                    ");
                    
                    // Copy data without the display_preferences column
                    $db->exec("
                        INSERT INTO user_settings_new (id, user_id, tmdb_api_key, tmdb_access_token, created_at, updated_at)
                        SELECT id, user_id, tmdb_api_key, tmdb_access_token, created_at, updated_at FROM user_settings
                    ");
                    
                    // Drop old table and rename new one
                    $db->exec("DROP TABLE user_settings");
                    $db->exec("ALTER TABLE user_settings_new RENAME TO user_settings");
                }
            }
            
            echo "Successfully removed display_preferences column\n";
        } catch (\Exception $e) {
            echo "Failed to remove display_preferences column: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
} 