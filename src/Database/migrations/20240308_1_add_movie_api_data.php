<?php
declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Migration;
use App\Database\Database;
use App\Services\LoggerService as Logger;

class Migration20240308_1 extends Migration
{
    public function up(\PDO $db): void
    {
        Logger::info("Running migration: AddMovieApiData - up");
        
        try {
            // Add columns directly to the existing table
            $db->exec("ALTER TABLE movies ADD COLUMN credits TEXT");
            $db->exec("ALTER TABLE movies ADD COLUMN similar TEXT");
            
            Logger::info("Successfully added API data columns");
        } catch (\Exception $e) {
            Logger::error("Failed to add API data columns", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    
    public function down(\PDO $db): void
    {
        Logger::info("Running migration: AddMovieApiData - down");
        
        try {
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
                    certifications TEXT,
                    keywords TEXT,
                    watch_providers TEXT,
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
                    adult, video, certifications, keywords, watch_providers, added_at, last_updated_at
                )
                SELECT 
                    id, user_id, tmdb_id, list_type, title, overview, release_date,
                    genres, runtime, vote_average, vote_count, production_companies,
                    poster_path, backdrop_path, local_poster_path, local_backdrop_path,
                    original_title, tagline, status, production_countries, spoken_languages,
                    budget, revenue, homepage, imdb_id, original_language, popularity,
                    adult, video, certifications, keywords, watch_providers, added_at, last_updated_at
                FROM movies
            ");
            
            // Drop current table
            $db->exec("DROP TABLE movies");
            
            // Rename old table to movies
            $db->exec("ALTER TABLE movies_old RENAME TO movies");
            
            // Recreate indexes
            $db->exec("CREATE INDEX idx_movies_user_id ON movies(user_id)");
            $db->exec("CREATE INDEX idx_movies_tmdb_id ON movies(tmdb_id)");
            
            Logger::info("Successfully removed API data columns");
        } catch (\Exception $e) {
            Logger::error("Failed to remove API data columns", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
} 