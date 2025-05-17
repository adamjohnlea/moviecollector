<?php
declare(strict_types=1);

namespace App\Models;

use App\Database\Database;

class MovieFormat
{
    private int $id;
    private string $name;
    private ?string $description;
    private string $category;
    private string $createdAt;
    
    /**
     * Get all available movie formats
     */
    public static function getAllFormats(): array
    {
        $db = Database::getInstance();
        
        $stmt = $db->query("SELECT * FROM movie_formats ORDER BY category, name");
        
        $formats = [];
        while ($formatData = $stmt->fetch()) {
            $formats[] = self::createFromArray($formatData);
        }
        
        return $formats;
    }
    
    /**
     * Get formats grouped by category
     */
    public static function getFormatsByCategory(): array
    {
        $formats = self::getAllFormats();
        
        $categorized = [];
        foreach ($formats as $format) {
            $category = $format->getCategory();
            if (!isset($categorized[$category])) {
                $categorized[$category] = [];
            }
            $categorized[$category][] = $format;
        }
        
        return $categorized;
    }
    
    /**
     * Get format by ID
     */
    public static function getById(int $id): ?MovieFormat
    {
        $db = Database::getInstance();
        
        $stmt = $db->prepare("SELECT * FROM movie_formats WHERE id = ?");
        $stmt->execute([$id]);
        
        $formatData = $stmt->fetch();
        
        if (!$formatData) {
            return null;
        }
        
        return self::createFromArray($formatData);
    }
    
    /**
     * Get formats for a user's movie
     */
    public static function getFormatsForUserMovie(int $userId, int $movieId): array
    {
        $db = Database::getInstance();
        
        $stmt = $db->prepare("
            SELECT f.* 
            FROM movie_formats f
            JOIN user_movie_formats umf ON f.id = umf.format_id
            WHERE umf.user_id = ? AND umf.movie_id = ?
            ORDER BY f.category, f.name
        ");
        $stmt->execute([$userId, $movieId]);
        
        $formats = [];
        while ($formatData = $stmt->fetch()) {
            $formats[] = self::createFromArray($formatData);
        }
        
        return $formats;
    }
    
    /**
     * Save user movie formats
     */
    public static function saveUserMovieFormats(int $userId, int $movieId, array $formatIds): bool
    {
        $db = Database::getInstance();
        
        // Begin transaction
        $db->beginTransaction();
        
        try {
            // Delete existing format relationships
            $deleteStmt = $db->prepare("
                DELETE FROM user_movie_formats 
                WHERE user_id = ? AND movie_id = ?
            ");
            $deleteStmt->execute([$userId, $movieId]);
            
            // Add new format relationships
            if (!empty($formatIds)) {
                $insertStmt = $db->prepare("
                    INSERT INTO user_movie_formats (user_id, movie_id, format_id)
                    VALUES (?, ?, ?)
                ");
                
                foreach ($formatIds as $formatId) {
                    $insertStmt->execute([$userId, $movieId, $formatId]);
                }
            }
            
            // Commit transaction
            $db->commit();
            return true;
        } catch (\Exception $e) {
            // Rollback transaction on error
            $db->rollBack();
            return false;
        }
    }
    
    /**
     * Create a MovieFormat object from database row
     */
    private static function createFromArray(array $data): MovieFormat
    {
        $format = new MovieFormat();
        $format->id = (int) $data['id'];
        $format->name = $data['name'];
        $format->description = $data['description'];
        $format->category = $data['category'];
        $format->createdAt = $data['created_at'];
        
        return $format;
    }
    
    // Getters
    
    public function getId(): int
    {
        return $this->id;
    }
    
    public function getName(): string
    {
        return $this->name;
    }
    
    public function getDescription(): ?string
    {
        return $this->description;
    }
    
    public function getCategory(): string
    {
        return $this->category;
    }
    
    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }
    
    /**
     * Get formats for multiple movies for a specific user
     * Returns array with movie_id as key and array of format abbreviations as value
     */
    public static function getFormatsForMultipleMovies(int $userId, array $movieIds): array
    {
        if (empty($movieIds)) {
            return [];
        }
        
        $db = Database::getInstance();
        
        // Create placeholders for the IN clause
        $placeholders = implode(',', array_fill(0, count($movieIds), '?'));
        
        $sql = "
            SELECT umf.movie_id, f.name, f.category 
            FROM user_movie_formats umf
            JOIN movie_formats f ON umf.format_id = f.id
            WHERE umf.user_id = ? AND umf.movie_id IN ($placeholders)
            ORDER BY f.category, f.name
        ";
        
        // Prepare parameters with user ID first, followed by movie IDs
        $params = array_merge([$userId], $movieIds);
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        $results = [];
        while ($row = $stmt->fetch()) {
            $movieId = $row['movie_id'];
            $formatName = $row['name'];
            $formatCategory = $row['category'];
            
            // Create abbreviation based on format name
            $abbreviation = self::getFormatAbbreviation($formatName, $formatCategory);
            
            if (!isset($results[$movieId])) {
                $results[$movieId] = [];
            }
            
            $results[$movieId][] = $abbreviation;
        }
        
        return $results;
    }
    
    /**
     * Get abbreviation for a format
     */
    private static function getFormatAbbreviation(string $formatName, string $category): string
    {
        // Standard common format abbreviations
        $abbreviations = [
            'VHS' => 'VHS',
            'Betamax' => 'Beta',
            'LaserDisc' => 'LD',
            'DVD' => 'DVD',
            'VCD' => 'VCD',
            'CED' => 'CED',
            'MiniDVD' => 'mDVD',
            'UMD' => 'UMD',
            'Blu-ray' => 'BD',
            '4K Ultra HD Blu-ray' => '4K',
            'HD DVD' => 'HD-DVD',
            'Digital Download (DRM-Free)' => 'DIG',
            'Digital Download (DRM-Protected)' => 'DIG+'
        ];
        
        if (isset($abbreviations[$formatName])) {
            return $abbreviations[$formatName];
        }
        
        // Fallback to first letters if format name not in the map
        $words = explode(' ', $formatName);
        $abbreviation = '';
        foreach ($words as $word) {
            $abbreviation .= strtoupper(substr($word, 0, 1));
        }
        
        return $abbreviation;
    }
}
