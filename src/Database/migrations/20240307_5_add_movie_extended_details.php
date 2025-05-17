<?php
declare(strict_types=1);

namespace App\Database\Migrations;

class Migration20240307_5b
{
    public function up(\PDO $db): void
    {
        // Check if users table exists, create it if not
        $usersTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
        
        if (!$usersTableExists) {
            // Create users table
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
        
        // Check if movies table exists
        $moviesTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='movies'")->fetchColumn();
        
        if ($moviesTableExists) {
            echo "Running migration: AddMovieExtendedDetails - up\n";
            
            try {
                // Check if the columns already exist
                $hasColumns = [];
                $columnsStmt = $db->query("PRAGMA table_info(movies)");
                $columns = $columnsStmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($columns as $column) {
                    $hasColumns[$column['name']] = true;
                }
                
                // Add columns only if they don't exist
                if (!isset($hasColumns['certifications'])) {
                    $db->exec("ALTER TABLE movies ADD COLUMN certifications TEXT");
                }
                
                if (!isset($hasColumns['keywords'])) {
                    $db->exec("ALTER TABLE movies ADD COLUMN keywords TEXT");
                }
                
                if (!isset($hasColumns['watch_providers'])) {
                    $db->exec("ALTER TABLE movies ADD COLUMN watch_providers TEXT");
                }
                
                echo "Successfully added extended movie details columns\n";
            } catch (\Exception $e) {
                echo "Failed to add extended movie details columns: " . $e->getMessage() . "\n";
                throw $e;
            }
        }
    }

    public function down(\PDO $db): void
    {
        // Check if movies table exists
        $moviesTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='movies'")->fetchColumn();
        
        if ($moviesTableExists) {
            echo "Running migration: AddMovieExtendedDetails - down\n";
            
            try {
                // Check if the columns exist
                $hasColumns = [];
                $columnsStmt = $db->query("PRAGMA table_info(movies)");
                $columns = $columnsStmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($columns as $column) {
                    $hasColumns[$column['name']] = true;
                }
                
                // Only try to remove columns if they exist
                if (isset($hasColumns['certifications']) || isset($hasColumns['keywords']) || isset($hasColumns['watch_providers'])) {
                    // Drop movies_old table if it already exists
                    $db->exec("DROP TABLE IF EXISTS movies_old");
                    
                    // SQLite doesn't support DROP COLUMN, so we need to create a new table
                    // Create new movies table without the new columns
                    $db->exec("
                        CREATE TABLE movies_old (
                            id INTEGER PRIMARY KEY AUTOINCREMENT,
                            user_id INTEGER NOT NULL,
                            tmdb_id INTEGER NOT NULL,
                            list_type TEXT NOT NULL,
                            title TEXT NOT NULL,
                            overview TEXT,
                            release_date TEXT,
                            genres TEXT,
                            runtime INTEGER,
                            vote_average REAL,
                            vote_count INTEGER,
                            production_companies TEXT,
                            poster_path TEXT,
                            backdrop_path TEXT,
                            local_poster_path TEXT,
                            local_backdrop_path TEXT,
                            original_title TEXT,
                            tagline TEXT,
                            status TEXT,
                            production_countries TEXT,
                            spoken_languages TEXT,
                            budget INTEGER,
                            revenue INTEGER,
                            homepage TEXT,
                            imdb_id TEXT,
                            original_language TEXT,
                            popularity REAL,
                            adult INTEGER DEFAULT 0,
                            video INTEGER DEFAULT 0,
                            added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            last_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            UNIQUE(user_id, tmdb_id)
                        )
                    ");
                    
                    // Copy data from current table to old table structure
                    $db->exec("
                        INSERT INTO movies_old (
                            id, user_id, tmdb_id, list_type, title, overview, release_date,
                            genres, runtime, vote_average, vote_count, production_companies,
                            poster_path, backdrop_path, local_poster_path, local_backdrop_path,
                            original_title, tagline, status, production_countries, spoken_languages,
                            budget, revenue, homepage, imdb_id, original_language, popularity,
                            adult, video, added_at, last_updated_at
                        )
                        SELECT 
                            id, user_id, tmdb_id, list_type, title, overview, release_date,
                            genres, runtime, vote_average, vote_count, production_companies,
                            poster_path, backdrop_path, local_poster_path, local_backdrop_path,
                            original_title, tagline, status, production_countries, spoken_languages,
                            budget, revenue, homepage, imdb_id, original_language, popularity,
                            adult, video, added_at, last_updated_at
                        FROM movies
                    ");
                    
                    // Drop current table
                    $db->exec("DROP TABLE movies");
                    
                    // Rename old table to movies
                    $db->exec("ALTER TABLE movies_old RENAME TO movies");
                    
                    // Recreate indexes
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_movies_user_id ON movies(user_id)");
                    $db->exec("CREATE INDEX IF NOT EXISTS idx_movies_tmdb_id ON movies(tmdb_id)");
                    
                    echo "Successfully removed extended movie details columns\n";
                } else {
                    echo "No extended movie details columns to remove\n";
                }
            } catch (\Exception $e) {
                echo "Failed to remove extended movie details columns: " . $e->getMessage() . "\n";
                throw $e;
            }
        }
    }
} 