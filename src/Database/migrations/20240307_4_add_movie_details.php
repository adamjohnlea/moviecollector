<?php
declare(strict_types=1);

namespace App\Database\Migrations;

class Migration20240307_4
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
        
        // Drop movies_new table if it already exists (from a previous failed migration)
        $db->exec("DROP TABLE IF EXISTS movies_new");
        
        // Create a new table with additional columns
        $db->exec("
            CREATE TABLE movies_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                tmdb_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                original_title TEXT,
                tagline TEXT,
                status TEXT,
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
                production_countries TEXT,
                spoken_languages TEXT,
                budget BIGINT,
                revenue BIGINT,
                homepage TEXT,
                imdb_id TEXT,
                original_language TEXT,
                popularity REAL,
                adult BOOLEAN DEFAULT 0,
                video BOOLEAN DEFAULT 0,
                local_poster_path TEXT,
                local_backdrop_path TEXT,
                last_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                UNIQUE(user_id, tmdb_id)
            )
        ");
        
        // Check if movies table exists before copying data
        $moviesTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='movies'")->fetchColumn();
        
        if ($moviesTableExists) {
            // Copy existing data
            $db->exec("
                INSERT INTO movies_new (
                    id, user_id, tmdb_id, title, poster_path, backdrop_path,
                    overview, release_date, added_at, list_type, genres,
                    runtime, vote_average, vote_count, production_companies,
                    local_poster_path, local_backdrop_path, last_updated_at
                )
                SELECT 
                    id, user_id, tmdb_id, title, poster_path, backdrop_path,
                    overview, release_date, added_at, list_type, genres,
                    runtime, vote_average, vote_count, production_companies,
                    local_poster_path, local_backdrop_path, last_updated_at
                FROM movies
            ");
            
            // Drop the old table
            $db->exec("DROP TABLE movies");
        }
        
        // Rename the new table
        $db->exec("ALTER TABLE movies_new RENAME TO movies");
        
        // Recreate the index
        $db->exec("CREATE INDEX idx_movies_list_type ON movies (user_id, list_type)");
    }

    public function down(\PDO $db): void
    {
        // Drop movies_old table if it already exists
        $db->exec("DROP TABLE IF EXISTS movies_old");
        
        // Check if movies table exists before proceeding
        $moviesTableExists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='movies'")->fetchColumn();
        
        if ($moviesTableExists) {
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
            
            // Copy existing data
            $db->exec("
                INSERT INTO movies_old (
                    id, user_id, tmdb_id, title, poster_path, backdrop_path,
                    overview, release_date, added_at, list_type, genres,
                    runtime, vote_average, vote_count, production_companies,
                    local_poster_path, local_backdrop_path, last_updated_at
                )
                SELECT 
                    id, user_id, tmdb_id, title, poster_path, backdrop_path,
                    overview, release_date, added_at, list_type, genres,
                    runtime, vote_average, vote_count, production_companies,
                    local_poster_path, local_backdrop_path, last_updated_at
                FROM movies
            ");
            
            // Drop the new table
            $db->exec("DROP TABLE movies");
            
            // Rename the old table
            $db->exec("ALTER TABLE movies_old RENAME TO movies");
            
            // Recreate the index
            $db->exec("CREATE INDEX idx_movies_list_type ON movies (user_id, list_type)");
        }
    }
} 