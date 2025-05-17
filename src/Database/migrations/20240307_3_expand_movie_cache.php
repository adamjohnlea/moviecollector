<?php
declare(strict_types=1);

namespace App\Database\Migrations;

class Migration20240307_3
{
    public function up(\PDO $db): void
    {
        // Drop movies_new table if it already exists (from a previous failed migration)
        $db->exec("DROP TABLE IF EXISTS movies_new");
        
        // Create a new table with all columns
        $db->exec("
            CREATE TABLE movies_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                tmdb_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                poster_path TEXT,
                backdrop_path TEXT,
                overview TEXT,
                release_date TEXT,
                added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                list_type VARCHAR(20) NOT NULL DEFAULT 'collection',
                genres TEXT,
                runtime INTEGER,
                vote_average REAL,
                vote_count INTEGER,
                production_companies TEXT,
                local_poster_path TEXT,
                local_backdrop_path TEXT,
                last_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                UNIQUE(user_id, tmdb_id)
            )
        ");
        
        // Check if movies table exists before copying data
        $tableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='movies'")->fetchColumn();
        
        if ($tableExists) {
            // Check if list_type column exists in the movies table
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
                // If list_type exists, include it in the copy
                $db->exec("
                    INSERT INTO movies_new (
                        id, user_id, tmdb_id, title, poster_path, backdrop_path,
                        overview, release_date, added_at, list_type
                    )
                    SELECT 
                        id, user_id, tmdb_id, title, poster_path, backdrop_path,
                        overview, release_date, added_at, list_type
                    FROM movies
                ");
            } else {
                // If list_type doesn't exist, set a default value of 'collection'
                $db->exec("
                    INSERT INTO movies_new (
                        id, user_id, tmdb_id, title, poster_path, backdrop_path,
                        overview, release_date, added_at, list_type
                    )
                    SELECT 
                        id, user_id, tmdb_id, title, poster_path, backdrop_path,
                        overview, release_date, added_at, 'collection'
                    FROM movies
                ");
                
                // Apply list_type migration on the fly
                $db->exec("CREATE INDEX IF NOT EXISTS idx_movies_list_type ON movies_new (user_id, list_type)");
            }
            
            // Drop the old table
            $db->exec("DROP TABLE movies");
        }
        
        // Rename the new table
        $db->exec("ALTER TABLE movies_new RENAME TO movies");
        
        // Recreate the index
        $db->exec("CREATE INDEX IF NOT EXISTS idx_movies_list_type ON movies (user_id, list_type)");
        
        // Create directory structure for image caching
        $uploadsDir = __DIR__ . '/../../../public/uploads';
        $dirs = [
            $uploadsDir . '/posters',
            $uploadsDir . '/backdrops'
        ];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    public function down(\PDO $db): void
    {
        // Drop movies_old table if it already exists
        $db->exec("DROP TABLE IF EXISTS movies_old");
        
        // Check if movies table exists before proceeding
        $tableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='movies'")->fetchColumn();
        
        if ($tableExists) {
            // Create a new table without the new columns
            $db->exec("
                CREATE TABLE movies_old (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    tmdb_id INTEGER NOT NULL,
                    title TEXT NOT NULL,
                    poster_path TEXT,
                    backdrop_path TEXT,
                    overview TEXT,
                    release_date TEXT,
                    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    list_type VARCHAR(20) NOT NULL DEFAULT 'collection',
                    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                    UNIQUE(user_id, tmdb_id)
                )
            ");
            
            // Check if list_type column exists in the movies table
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
                // If list_type exists, include it in the copy
                $db->exec("
                    INSERT INTO movies_old (
                        id, user_id, tmdb_id, title, poster_path, backdrop_path,
                        overview, release_date, added_at, list_type
                    )
                    SELECT 
                        id, user_id, tmdb_id, title, poster_path, backdrop_path,
                        overview, release_date, added_at, list_type
                    FROM movies
                ");
            } else {
                // If list_type doesn't exist, set a default value of 'collection'
                $db->exec("
                    INSERT INTO movies_old (
                        id, user_id, tmdb_id, title, poster_path, backdrop_path,
                        overview, release_date, added_at, list_type
                    )
                    SELECT 
                        id, user_id, tmdb_id, title, poster_path, backdrop_path,
                        overview, release_date, added_at, 'collection'
                    FROM movies
                ");
            }
            
            // Drop the new table
            $db->exec("DROP TABLE movies");
            
            // Rename the old table
            $db->exec("ALTER TABLE movies_old RENAME TO movies");
            
            // Recreate the index
            $db->exec("CREATE INDEX IF NOT EXISTS idx_movies_list_type ON movies (user_id, list_type)");
        }
    }
} 