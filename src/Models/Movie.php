<?php
declare(strict_types=1);

namespace App\Models;

use App\Database\Database;
use App\Services\LoggerService as Logger;

class Movie
{
    public const LIST_COLLECTION = 'collection';
    public const LIST_TO_WATCH = 'to_watch';
    public const LIST_TO_BUY = 'to_buy';
    
    private int $id;
    private int $userId;
    private int $tmdbId;
    private string $title;
    private ?string $posterPath;
    private ?string $backdropPath;
    private ?string $overview;
    private ?string $releaseDate;
    private string $addedAt;
    private string $listType;
    private ?string $genres;
    private ?int $runtime;
    private ?float $voteAverage;
    private ?int $voteCount;
    private ?string $productionCompanies;
    private ?string $localPosterPath;
    private ?string $localBackdropPath;
    private string $lastUpdatedAt;
    private ?string $originalTitle;
    private ?string $tagline;
    private ?string $status;
    private ?string $productionCountries;
    private ?string $spokenLanguages;
    private ?int $budget;
    private ?int $revenue;
    private ?string $homepage;
    private ?string $imdbId;
    private ?string $originalLanguage;
    private ?float $popularity;
    private bool $adult;
    private bool $video;
    private ?string $certifications;
    private ?string $keywords;
    private ?string $watchProviders;
    private ?string $credits;
    private ?string $similar;
    private ?string $recommendations;
    private ?string $videos;
    private ?string $images;
    private ?string $reviews;
    private ?string $externalIds;
    
    /**
     * Add a movie to a user's list
     */
    public static function addToList(
        int $userId, 
        int $tmdbId, 
        string $title,
        string $listType = self::LIST_COLLECTION,
        ?string $posterPath = null,
        ?string $backdropPath = null,
        ?string $overview = null,
        ?string $releaseDate = null,
        ?string $genres = null,
        ?int $runtime = null,
        ?float $voteAverage = null,
        ?int $voteCount = null,
        ?string $productionCompanies = null,
        ?string $localPosterPath = null,
        ?string $localBackdropPath = null,
        ?string $originalTitle = null,
        ?string $tagline = null,
        ?string $status = null,
        ?string $productionCountries = null,
        ?string $spokenLanguages = null,
        ?int $budget = null,
        ?int $revenue = null,
        ?string $homepage = null,
        ?string $imdbId = null,
        ?string $originalLanguage = null,
        ?float $popularity = null,
        bool $adult = false,
        bool $video = false,
        ?string $certifications = null,
        ?string $keywords = null,
        ?string $watchProviders = null,
        ?string $credits = null,
        ?string $similar = null,
        ?string $recommendations = null,
        ?string $videos = null,
        ?string $images = null,
        ?string $reviews = null,
        ?string $externalIds = null
    ): ?Movie {
        if (!in_array($listType, [self::LIST_COLLECTION, self::LIST_TO_WATCH, self::LIST_TO_BUY])) {
            throw new \InvalidArgumentException('Invalid list type');
        }
        
        $db = Database::getInstance();
        
        try {
            // Ensure JSON fields are properly encoded
            $genres = is_array($genres) ? json_encode($genres) : $genres;
            $productionCompanies = is_array($productionCompanies) ? json_encode($productionCompanies) : $productionCompanies;
            $productionCountries = is_array($productionCountries) ? json_encode($productionCountries) : $productionCountries;
            $spokenLanguages = is_array($spokenLanguages) ? json_encode($spokenLanguages) : $spokenLanguages;

            Logger::info("Attempting to add movie to list", [
                'title' => $title,
                'tmdb_id' => $tmdbId,
                'list_type' => $listType
            ]);
            
            $stmt = $db->prepare("
                INSERT INTO movies (
                    user_id, tmdb_id, title, list_type, poster_path,
                    backdrop_path, overview, release_date, genres,
                    runtime, vote_average, vote_count, production_companies,
                    local_poster_path, local_backdrop_path, original_title,
                    tagline, status, production_countries, spoken_languages,
                    budget, revenue, homepage, imdb_id, original_language,
                    popularity, adult, video, certifications, keywords, watch_providers, 
                    credits, similar, recommendations, videos, images, reviews, external_ids, last_updated_at
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ");
            
            $result = $stmt->execute([
                $userId, $tmdbId, $title, $listType, $posterPath,
                $backdropPath, $overview, $releaseDate, $genres,
                $runtime, $voteAverage, $voteCount, $productionCompanies,
                $localPosterPath, $localBackdropPath, $originalTitle,
                $tagline, $status, $productionCountries, $spokenLanguages,
                $budget, $revenue, $homepage, $imdbId, $originalLanguage,
                $popularity, $adult ? 1 : 0, $video ? 1 : 0, $certifications, $keywords, $watchProviders,
                $credits, $similar, $recommendations, $videos, $images, $reviews, $externalIds
            ]);

            if (!$result) {
                Logger::error("Failed to execute insert statement", [
                    'error' => $stmt->errorInfo(),
                    'title' => $title,
                    'tmdb_id' => $tmdbId
                ]);
                return null;
            }
            
            $movieId = (int) $db->lastInsertId();
            Logger::info("Successfully added movie to list", [
                'movie_id' => $movieId,
                'title' => $title,
                'tmdb_id' => $tmdbId
            ]);
            
            return self::findById($movieId);
        } catch (\PDOException $e) {
            Logger::error("Database error while adding movie", [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'title' => $title,
                'tmdb_id' => $tmdbId
            ]);
            return null;
        }
    }
    
    /**
     * Move a movie to a different list
     */
    public static function moveToList(int $userId, int $tmdbId, string $newListType): bool
    {
        if (!in_array($newListType, [self::LIST_COLLECTION, self::LIST_TO_WATCH, self::LIST_TO_BUY])) {
            throw new \InvalidArgumentException('Invalid list type');
        }
        
        $db = Database::getInstance();
        
        $stmt = $db->prepare("
            UPDATE movies
            SET list_type = ?
            WHERE user_id = ? AND tmdb_id = ?
        ");
        
        $stmt->execute([$newListType, $userId, $tmdbId]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Remove a movie from any user list
     */
    public static function removeFromList(int $userId, int $tmdbId): bool
    {
        $db = Database::getInstance();
        
        Logger::info("Attempting to remove movie from database", [
            'tmdb_id' => $tmdbId,
            'user_id' => $userId
        ]);
        
        $stmt = $db->prepare("
            DELETE FROM movies
            WHERE user_id = ? AND tmdb_id = ?
        ");
        
        try {
            $stmt->execute([$userId, $tmdbId]);
            $rowCount = $stmt->rowCount();
            
            if ($rowCount > 0) {
                Logger::info("Successfully removed movie from database", [
                    'tmdb_id' => $tmdbId,
                    'user_id' => $userId,
                    'rows_affected' => $rowCount
                ]);
            } else {
                Logger::warning("No movie found to remove in database", [
                    'tmdb_id' => $tmdbId,
                    'user_id' => $userId
                ]);
            }
            
            return $rowCount > 0;
        } catch (\PDOException $e) {
            Logger::error("Database error while removing movie", [
                'tmdb_id' => $tmdbId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
            return false;
        }
    }
    
    /**
     * Check if a movie is in any of the user's lists
     */
    public static function isInAnyList(int $userId, int $tmdbId): bool
    {
        $db = Database::getInstance();
        
        $stmt = $db->prepare("
            SELECT list_type FROM movies
            WHERE user_id = ? AND tmdb_id = ?
            LIMIT 1
        ");
        
        $stmt->execute([$userId, $tmdbId]);
        return $stmt->fetchColumn() !== false;
    }
    
    /**
     * Get which list a movie is in for a user
     */
    public static function getListType(int $userId, int $tmdbId): ?string
    {
        $db = Database::getInstance();
        
        $stmt = $db->prepare("
            SELECT list_type FROM movies
            WHERE user_id = ? AND tmdb_id = ?
            LIMIT 1
        ");
        
        $stmt->execute([$userId, $tmdbId]);
        return $stmt->fetchColumn() ?: null;
    }
    
    /**
     * Get all movies in a specific user list
     */
    public static function getUserList(int $userId, string $listType): array
    {
        if (!in_array($listType, [self::LIST_COLLECTION, self::LIST_TO_WATCH, self::LIST_TO_BUY])) {
            throw new \InvalidArgumentException('Invalid list type');
        }
        
        $db = Database::getInstance();
        
        $stmt = $db->prepare("
            SELECT * FROM movies
            WHERE user_id = ? AND list_type = ?
            ORDER BY title ASC
        ");
        
        $stmt->execute([$userId, $listType]);
        $moviesData = $stmt->fetchAll();
        
        $movies = [];
        foreach ($moviesData as $movieData) {
            $movies[] = self::createFromArray($movieData);
        }
        
        return $movies;
    }
    
    /**
     * Find a movie by ID
     */
    public static function findById(int $id): ?Movie
    {
        $db = Database::getInstance();
        
        $stmt = $db->prepare("SELECT * FROM movies WHERE id = ?");
        $stmt->execute([$id]);
        
        $movieData = $stmt->fetch();
        
        if (!$movieData) {
            return null;
        }
        
        return self::createFromArray($movieData);
    }
    
    /**
     * Create a Movie object from database row
     */
    public static function createFromArray(array $data): Movie
    {
        $movie = new Movie();
        $movie->id = (int) $data['id'];
        $movie->userId = (int) $data['user_id'];
        $movie->tmdbId = (int) $data['tmdb_id'];
        $movie->title = $data['title'];
        $movie->posterPath = $data['poster_path'];
        $movie->backdropPath = $data['backdrop_path'];
        $movie->overview = $data['overview'];
        $movie->releaseDate = $data['release_date'];
        $movie->addedAt = $data['added_at'];
        $movie->listType = $data['list_type'];
        $movie->genres = $data['genres'] ?? null;
        $movie->runtime = isset($data['runtime']) ? (int) $data['runtime'] : null;
        $movie->voteAverage = isset($data['vote_average']) ? (float) $data['vote_average'] : null;
        $movie->voteCount = isset($data['vote_count']) ? (int) $data['vote_count'] : null;
        $movie->productionCompanies = $data['production_companies'] ?? null;
        $movie->localPosterPath = $data['local_poster_path'] ?? null;
        $movie->localBackdropPath = $data['local_backdrop_path'] ?? null;
        $movie->lastUpdatedAt = $data['last_updated_at'];
        $movie->originalTitle = $data['original_title'] ?? null;
        $movie->tagline = $data['tagline'] ?? null;
        $movie->status = $data['status'] ?? null;
        $movie->productionCountries = $data['production_countries'] ?? null;
        $movie->spokenLanguages = $data['spoken_languages'] ?? null;
        $movie->budget = isset($data['budget']) ? (int) $data['budget'] : null;
        $movie->revenue = isset($data['revenue']) ? (int) $data['revenue'] : null;
        $movie->homepage = $data['homepage'] ?? null;
        $movie->imdbId = $data['imdb_id'] ?? null;
        $movie->originalLanguage = $data['original_language'] ?? null;
        $movie->popularity = isset($data['popularity']) ? (float) $data['popularity'] : null;
        $movie->adult = isset($data['adult']) ? (bool) $data['adult'] : false;
        $movie->video = isset($data['video']) ? (bool) $data['video'] : false;
        $movie->certifications = $data['certifications'] ?? null;
        $movie->keywords = $data['keywords'] ?? null;
        $movie->watchProviders = $data['watch_providers'] ?? null;
        $movie->credits = $data['credits'] ?? null;
        $movie->similar = $data['similar'] ?? null;
        $movie->recommendations = $data['recommendations'] ?? null;
        $movie->videos = $data['videos'] ?? null;
        $movie->images = $data['images'] ?? null;
        $movie->reviews = $data['reviews'] ?? null;
        $movie->externalIds = $data['external_ids'] ?? null;
        
        return $movie;
    }
    
    // Getters
    public function getId(): int
    {
        return $this->id;
    }
    
    public function getUserId(): int
    {
        return $this->userId;
    }
    
    public function getTmdbId(): int
    {
        return $this->tmdbId;
    }
    
    public function getTitle(): string
    {
        return $this->title;
    }
    
    public function getPosterPath(): ?string
    {
        return $this->posterPath;
    }
    
    public function getBackdropPath(): ?string
    {
        return $this->backdropPath;
    }
    
    public function getOverview(): ?string
    {
        return $this->overview;
    }
    
    public function getReleaseDate(): ?string
    {
        return $this->releaseDate;
    }
    
    public function getAddedAt(): string
    {
        return $this->addedAt;
    }
    
    public function getCurrentListType(): string
    {
        return $this->listType;
    }
    
    public function getGenres(): ?array
    {
        return $this->genres ? json_decode($this->genres, true) : null;
    }
    
    public function getRuntime(): ?int
    {
        return $this->runtime;
    }
    
    public function getVoteAverage(): ?float
    {
        return $this->voteAverage;
    }
    
    public function getVoteCount(): ?int
    {
        return $this->voteCount;
    }
    
    public function getProductionCompanies(): ?array
    {
        return $this->productionCompanies ? json_decode($this->productionCompanies, true) : null;
    }
    
    public function getLocalPosterPath(): ?string
    {
        return $this->localPosterPath;
    }
    
    public function getLocalBackdropPath(): ?string
    {
        return $this->localBackdropPath;
    }
    
    public function getLastUpdatedAt(): string
    {
        return $this->lastUpdatedAt;
    }
    
    public function getOriginalTitle(): ?string
    {
        return $this->originalTitle;
    }
    
    public function getTagline(): ?string
    {
        return $this->tagline;
    }
    
    public function getStatus(): ?string
    {
        return $this->status;
    }
    
    public function getProductionCountries(): ?array
    {
        return $this->productionCountries ? json_decode($this->productionCountries, true) : null;
    }
    
    public function getSpokenLanguages(): ?array
    {
        return $this->spokenLanguages ? json_decode($this->spokenLanguages, true) : null;
    }
    
    public function getBudget(): ?int
    {
        return $this->budget;
    }
    
    public function getRevenue(): ?int
    {
        return $this->revenue;
    }
    
    public function getHomepage(): ?string
    {
        return $this->homepage;
    }
    
    public function getImdbId(): ?string
    {
        return $this->imdbId;
    }
    
    public function getOriginalLanguage(): ?string
    {
        return $this->originalLanguage;
    }
    
    public function getPopularity(): ?float
    {
        return $this->popularity;
    }
    
    public function isAdult(): bool
    {
        return $this->adult;
    }
    
    public function hasVideo(): bool
    {
        return $this->video;
    }
    
    public function getCertifications(): ?array
    {
        return $this->certifications ? json_decode($this->certifications, true) : null;
    }
    
    public function getKeywords(): ?array
    {
        return $this->keywords ? json_decode($this->keywords, true) : null;
    }
    
    public function getWatchProviders(): ?array
    {
        return $this->watchProviders ? json_decode($this->watchProviders, true) : null;
    }
    
    /**
     * Get the credits data (cast and crew)
     */
    public function getCredits(): ?string
    {
        return $this->credits ?? null;
    }
    
    /**
     * Get similar movies data
     */
    public function getSimilar(): ?string
    {
        return $this->similar ?? null;
    }
    
    /**
     * Get movie recommendations
     */
    public function getRecommendations(): ?string
    {
        return $this->recommendations ?? null;
    }
    
    /**
     * Get movie videos (trailers, teasers)
     */
    public function getVideos(): ?string
    {
        return $this->videos ?? null;
    }
    
    /**
     * Get movie images (posters, backdrops)
     */
    public function getImages(): ?string
    {
        return $this->images ?? null;
    }
    
    /**
     * Get movie reviews
     */
    public function getReviews(): ?string
    {
        return $this->reviews ?? null;
    }
    
    /**
     * Get external IDs (IMDB, Facebook, etc.)
     */
    public function getExternalIds(): ?string
    {
        return $this->externalIds ?? null;
    }
    
    /**
     * Update cached movie data
     */
    public function updateCachedData(array $data): bool
    {
        $db = Database::getInstance();
        
        try {
            $updates = [];
            $values = [];
            
            // Only include fields that are provided in the data array
            if (isset($data['title'])) {
                $updates[] = 'title = ?';
                $values[] = $data['title'];
            }
            if (isset($data['poster_path'])) {
                $updates[] = 'poster_path = ?';
                $values[] = $data['poster_path'];
            }
            if (isset($data['backdrop_path'])) {
                $updates[] = 'backdrop_path = ?';
                $values[] = $data['backdrop_path'];
            }
            if (isset($data['overview'])) {
                $updates[] = 'overview = ?';
                $values[] = $data['overview'];
            }
            if (isset($data['release_date'])) {
                $updates[] = 'release_date = ?';
                $values[] = $data['release_date'];
            }
            if (isset($data['genres'])) {
                $updates[] = 'genres = ?';
                $values[] = $data['genres'];
            }
            if (isset($data['runtime'])) {
                $updates[] = 'runtime = ?';
                $values[] = $data['runtime'];
            }
            if (isset($data['vote_average'])) {
                $updates[] = 'vote_average = ?';
                $values[] = $data['vote_average'];
            }
            if (isset($data['vote_count'])) {
                $updates[] = 'vote_count = ?';
                $values[] = $data['vote_count'];
            }
            if (isset($data['production_companies'])) {
                $updates[] = 'production_companies = ?';
                $values[] = $data['production_companies'];
            }
            if (isset($data['local_poster_path'])) {
                $updates[] = 'local_poster_path = ?';
                $values[] = $data['local_poster_path'];
            }
            if (isset($data['local_backdrop_path'])) {
                $updates[] = 'local_backdrop_path = ?';
                $values[] = $data['local_backdrop_path'];
            }
            if (isset($data['original_title'])) {
                $updates[] = 'original_title = ?';
                $values[] = $data['original_title'];
            }
            if (isset($data['tagline'])) {
                $updates[] = 'tagline = ?';
                $values[] = $data['tagline'];
            }
            if (isset($data['status'])) {
                $updates[] = 'status = ?';
                $values[] = $data['status'];
            }
            if (isset($data['production_countries'])) {
                $updates[] = 'production_countries = ?';
                $values[] = $data['production_countries'];
            }
            if (isset($data['spoken_languages'])) {
                $updates[] = 'spoken_languages = ?';
                $values[] = $data['spoken_languages'];
            }
            if (isset($data['budget'])) {
                $updates[] = 'budget = ?';
                $values[] = $data['budget'];
            }
            if (isset($data['revenue'])) {
                $updates[] = 'revenue = ?';
                $values[] = $data['revenue'];
            }
            if (isset($data['homepage'])) {
                $updates[] = 'homepage = ?';
                $values[] = $data['homepage'];
            }
            if (isset($data['imdb_id'])) {
                $updates[] = 'imdb_id = ?';
                $values[] = $data['imdb_id'];
            }
            if (isset($data['original_language'])) {
                $updates[] = 'original_language = ?';
                $values[] = $data['original_language'];
            }
            if (isset($data['popularity'])) {
                $updates[] = 'popularity = ?';
                $values[] = $data['popularity'];
            }
            if (isset($data['adult'])) {
                $updates[] = 'adult = ?';
                $values[] = $data['adult'] ? 1 : 0;
            }
            if (isset($data['video'])) {
                $updates[] = 'video = ?';
                $values[] = $data['video'] ? 1 : 0;
            }
            if (isset($data['certifications'])) {
                $updates[] = 'certifications = ?';
                $values[] = $data['certifications'];
            }
            if (isset($data['keywords'])) {
                $updates[] = 'keywords = ?';
                $values[] = $data['keywords'];
            }
            if (isset($data['watch_providers'])) {
                $updates[] = 'watch_providers = ?';
                $values[] = $data['watch_providers'];
            }
            
            // Always update last_updated_at
            $updates[] = 'last_updated_at = CURRENT_TIMESTAMP';
            
            // If no fields to update, return true (no changes needed)
            if (empty($updates)) {
                return true;
            }
            
            // Add the ID for the WHERE clause
            $values[] = $this->id;
            
            $sql = "UPDATE movies SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            
            return $stmt->execute($values);
        } catch (\PDOException $e) {
            Logger::error("Failed to update cached data", [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return false;
        }
    }
    
    /**
     * Check if cached data is stale (older than 24 hours)
     */
    public function isCacheStale(): bool
    {
        $lastUpdate = strtotime($this->lastUpdatedAt);
        $now = time();
        $dayInSeconds = 24 * 60 * 60;
        
        return ($now - $lastUpdate) > $dayInSeconds;
    }
} 