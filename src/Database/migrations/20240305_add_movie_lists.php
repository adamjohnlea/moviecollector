<?php
declare(strict_types=1);

namespace App\Database\Migrations;

class Migration20240305_2
{
    public function up(\PDO $db): void
    {
        // Check if movies table exists
        $moviesTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='movies'")->fetchColumn();
        
        if ($moviesTableExists) {
            // Check if list_type column already exists
            $hasListType = false;
            $columnsStmt = $db->query("PRAGMA table_info(movies)");
            $columns = $columnsStmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($columns as $column) {
                if ($column['name'] === 'list_type') {
                    $hasListType = true;
                    break;
                }
            }
            
            if (!$hasListType) {
                // Add list_type column to movies table
                $db->exec("ALTER TABLE movies ADD COLUMN list_type VARCHAR(20) NOT NULL DEFAULT 'collection'");
                
                // Create index for faster list filtering
                $db->exec("CREATE INDEX IF NOT EXISTS idx_movies_list_type ON movies (user_id, list_type)");
                
                // Update existing movies to be in 'collection' list
                $db->exec("UPDATE movies SET list_type = 'collection'");
            }
        }
    }

    public function down(\PDO $db): void
    {
        // Check if movies table exists
        $moviesTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='movies'")->fetchColumn();
        
        if ($moviesTableExists) {
            // Check if list_type column exists
            $hasListType = false;
            $columnsStmt = $db->query("PRAGMA table_info(movies)");
            $columns = $columnsStmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($columns as $column) {
                if ($column['name'] === 'list_type') {
                    $hasListType = true;
                    break;
                }
            }
            
            if ($hasListType) {
                $db->exec("DROP INDEX IF EXISTS idx_movies_list_type");
                $db->exec("ALTER TABLE movies DROP COLUMN list_type");
            }
        }
    }
} 