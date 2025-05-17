<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Migration;
use App\Database\Database;
use App\Services\LoggerService as Logger;

class Migration20240307_6 extends Migration
{
    public function up(\PDO $db): void
    {
        Logger::info("Running migration: AddExtendedFields - up");
        
        try {
            // Create new movies table with additional columns
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
                    certifications TEXT,
                    keywords TEXT,
                    watch_providers TEXT,
                    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                    UNIQUE(user_id, tmdb_id)
                )
            ");
            
            // Copy data from old table to new table
            $db->exec("
                INSERT INTO movies_new (
                    id, user_id, tmdb_id, title, original_title, tagline, status,
                    poster_path, backdrop_path, overview, release_date, added_at,
                    list_type, genres, runtime, vote_average, vote_count,
                    production_companies, production_countries, spoken_languages,
                    budget, revenue, homepage, imdb_id, original_language,
                    popularity, adult, video, local_poster_path, local_backdrop_path,
                    last_updated_at
                )
                SELECT 
                    id, user_id, tmdb_id, title, original_title, tagline, status,
                    poster_path, backdrop_path, overview, release_date, added_at,
                    list_type, genres, runtime, vote_average, vote_count,
                    production_companies, production_countries, spoken_languages,
                    budget, revenue, homepage, imdb_id, original_language,
                    popularity, adult, video, local_poster_path, local_backdrop_path,
                    last_updated_at
                FROM movies
            ");
            
            // Drop old table
            $db->exec("DROP TABLE movies");
            
            // Rename new table to movies
            $db->exec("ALTER TABLE movies_new RENAME TO movies");
            
            // Recreate indexes
            $db->exec("CREATE INDEX idx_movies_user_id ON movies(user_id)");
            $db->exec("CREATE INDEX idx_movies_tmdb_id ON movies(tmdb_id)");
            
            Logger::info("Successfully added extended fields");
        } catch (\Exception $e) {
            Logger::error("Failed to add extended fields", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    
    public function down(\PDO $db): void
    {
        Logger::info("Running migration: AddExtendedFields - down");
        
        try {
            // Create new movies table without the new columns
            $db->exec("
                CREATE TABLE movies_old (
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
            
            // Copy data from current table to old table structure
            $db->exec("
                INSERT INTO movies_old (
                    id, user_id, tmdb_id, title, original_title, tagline, status,
                    poster_path, backdrop_path, overview, release_date, added_at,
                    list_type, genres, runtime, vote_average, vote_count,
                    production_companies, production_countries, spoken_languages,
                    budget, revenue, homepage, imdb_id, original_language,
                    popularity, adult, video, local_poster_path, local_backdrop_path,
                    last_updated_at
                )
                SELECT 
                    id, user_id, tmdb_id, title, original_title, tagline, status,
                    poster_path, backdrop_path, overview, release_date, added_at,
                    list_type, genres, runtime, vote_average, vote_count,
                    production_companies, production_countries, spoken_languages,
                    budget, revenue, homepage, imdb_id, original_language,
                    popularity, adult, video, local_poster_path, local_backdrop_path,
                    last_updated_at
                FROM movies
            ");
            
            // Drop current table
            $db->exec("DROP TABLE movies");
            
            // Rename old table to movies
            $db->exec("ALTER TABLE movies_old RENAME TO movies");
            
            // Recreate indexes
            $db->exec("CREATE INDEX idx_movies_user_id ON movies(user_id)");
            $db->exec("CREATE INDEX idx_movies_tmdb_id ON movies(tmdb_id)");
            
            Logger::info("Successfully removed extended fields");
        } catch (\Exception $e) {
            Logger::error("Failed to remove extended fields", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
} 