<?php
declare(strict_types=1);

namespace App\Models;

use App\Database\Database;

class UserSettings
{
    private int $id;
    private int $userId;
    private ?string $tmdbApiKey;
    private ?string $tmdbAccessToken;
    private string $createdAt;
    private string $updatedAt;
    
    // Display preferences
    private bool $showOverview = true;
    private bool $showCastCrew = true;
    private bool $showSimilar = true;
    private bool $showMoreInfo = true;
    private bool $showWatch = true;
    private bool $showVideos = true;
    private bool $showImages = true;
    private bool $showRecommendations = true;
    private bool $showReviews = true;
    private bool $showExternalIds = true;
    
    /**
     * Get settings for a user
     */
    public static function getByUserId(int $userId): ?UserSettings
    {
        $db = Database::getInstance();
        
        $stmt = $db->prepare("SELECT * FROM user_settings WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        $settingsData = $stmt->fetch();
        
        if (!$settingsData) {
            return null;
        }
        
        return self::createFromArray($settingsData);
    }
    
    /**
     * Update TMDb API key for a user
     */
    public static function updateTmdbApiKey(int $userId, ?string $apiKey): bool
    {
        $db = Database::getInstance();
        
        $stmt = $db->prepare("
            UPDATE user_settings 
            SET tmdb_api_key = ?, updated_at = CURRENT_TIMESTAMP
            WHERE user_id = ?
        ");
        
        return $stmt->execute([$apiKey, $userId]);
    }
    
    /**
     * Update TMDb access token for a user
     */
    public static function updateTmdbAccessToken(int $userId, ?string $accessToken): bool
    {
        $db = Database::getInstance();
        
        $stmt = $db->prepare("
            UPDATE user_settings 
            SET tmdb_access_token = ?, updated_at = CURRENT_TIMESTAMP
            WHERE user_id = ?
        ");
        
        return $stmt->execute([$accessToken, $userId]);
    }
    
    /**
     * Update display preferences for a user
     */
    public static function updateDisplayPreferences(int $userId, array $preferences): bool
    {
        $db = Database::getInstance();
        
        // Convert preferences array to JSON
        $preferencesJson = json_encode($preferences);
        
        $sql = "
            UPDATE user_settings 
            SET display_preferences = ?, updated_at = CURRENT_TIMESTAMP
            WHERE user_id = ?
        ";
        
        $stmt = $db->prepare($sql);
        
        return $stmt->execute([$preferencesJson, $userId]);
    }
    
    /**
     * Create a UserSettings object from database row
     */
    private static function createFromArray(array $data): UserSettings
    {
        $settings = new UserSettings();
        $settings->id = (int) $data['id'];
        $settings->userId = (int) $data['user_id'];
        $settings->tmdbApiKey = $data['tmdb_api_key'];
        $settings->tmdbAccessToken = $data['tmdb_access_token'];
        $settings->createdAt = $data['created_at'];
        $settings->updatedAt = $data['updated_at'];
        
        // Parse display preferences from JSON
        if (isset($data['display_preferences']) && !empty($data['display_preferences'])) {
            $preferences = json_decode($data['display_preferences'], true);
            
            if (is_array($preferences)) {
                // Set properties from preferences
                if (isset($preferences['overview'])) {
                    $settings->showOverview = (bool) $preferences['overview'];
                }
                if (isset($preferences['cast_crew'])) {
                    $settings->showCastCrew = (bool) $preferences['cast_crew'];
                }
                if (isset($preferences['similar'])) {
                    $settings->showSimilar = (bool) $preferences['similar'];
                }
                if (isset($preferences['more_info'])) {
                    $settings->showMoreInfo = (bool) $preferences['more_info'];
                }
                if (isset($preferences['watch'])) {
                    $settings->showWatch = (bool) $preferences['watch'];
                }
                if (isset($preferences['videos'])) {
                    $settings->showVideos = (bool) $preferences['videos'];
                }
                if (isset($preferences['images'])) {
                    $settings->showImages = (bool) $preferences['images'];
                }
                if (isset($preferences['recommendations'])) {
                    $settings->showRecommendations = (bool) $preferences['recommendations'];
                }
                if (isset($preferences['reviews'])) {
                    $settings->showReviews = (bool) $preferences['reviews'];
                }
                if (isset($preferences['externalIds'])) {
                    $settings->showExternalIds = (bool) $preferences['externalIds'];
                }
            }
        }
        
        return $settings;
    }
    
    /**
     * Get display preferences as an associative array
     */
    public function getDisplayPreferences(): array
    {
        return [
            'overview' => $this->showOverview,
            'cast_crew' => $this->showCastCrew,
            'similar' => $this->showSimilar,
            'more_info' => $this->showMoreInfo,
            'watch' => $this->showWatch,
            'videos' => $this->showVideos,
            'images' => $this->showImages,
            'recommendations' => $this->showRecommendations,
            'reviews' => $this->showReviews,
            'externalIds' => $this->showExternalIds
        ];
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
    
    public function getTmdbApiKey(): ?string
    {
        return $this->tmdbApiKey;
    }
    
    public function getTmdbAccessToken(): ?string
    {
        return $this->tmdbAccessToken;
    }
    
    public function hasValidTmdbCredentials(): bool
    {
        // At least one credential must be present
        return !empty($this->tmdbApiKey) || !empty($this->tmdbAccessToken);
    }
    
    // Display preference getters
    public function getShowOverview(): bool
    {
        return $this->showOverview;
    }
    
    public function getShowCastCrew(): bool
    {
        return $this->showCastCrew;
    }
    
    public function getShowSimilar(): bool
    {
        return $this->showSimilar;
    }
    
    public function getShowMoreInfo(): bool
    {
        return $this->showMoreInfo;
    }
    
    public function getShowWatch(): bool
    {
        return $this->showWatch;
    }
    
    public function getShowVideos(): bool
    {
        return $this->showVideos;
    }
    
    public function getShowImages(): bool
    {
        return $this->showImages;
    }
    
    public function getShowRecommendations(): bool
    {
        return $this->showRecommendations;
    }
    
    public function getShowReviews(): bool
    {
        return $this->showReviews;
    }
    
    public function getShowExternalIds(): bool
    {
        return $this->showExternalIds;
    }
} 