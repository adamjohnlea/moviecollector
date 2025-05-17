<?php
declare(strict_types=1);

namespace App\Database\Migrations;

class Migration20240307_5
{
    public function up(\PDO $db): void
    {
        // Check if movies table exists
        $moviesTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='movies'")->fetchColumn();
        
        if ($moviesTableExists) {
            // Check if last_updated_at column already exists
            $hasLastUpdatedAt = false;
            $columnsStmt = $db->query("PRAGMA table_info(movies)");
            $columns = $columnsStmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($columns as $column) {
                if ($column['name'] === 'last_updated_at') {
                    $hasLastUpdatedAt = true;
                    break;
                }
            }
            
            if (!$hasLastUpdatedAt) {
                // Add last_updated_at column
                $db->exec("ALTER TABLE movies ADD COLUMN last_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
                
                // Update existing rows to have last_updated_at equal to added_at
                $db->exec("UPDATE movies SET last_updated_at = added_at");
            }
        }
    }

    public function down(\PDO $db): void
    {
        // Check if movies table exists
        $moviesTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='movies'")->fetchColumn();
        
        if ($moviesTableExists) {
            // Check if last_updated_at column exists
            $hasLastUpdatedAt = false;
            $columnsStmt = $db->query("PRAGMA table_info(movies)");
            $columns = $columnsStmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($columns as $column) {
                if ($column['name'] === 'last_updated_at') {
                    $hasLastUpdatedAt = true;
                    break;
                }
            }
            
            if ($hasLastUpdatedAt) {
                $db->exec("ALTER TABLE movies DROP COLUMN last_updated_at");
            }
        }
    }
} 