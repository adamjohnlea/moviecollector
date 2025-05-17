<?php
declare(strict_types=1);

namespace App\Services;

use App\Services\LoggerService as Logger;

/**
 * Simple file-based cache service
 */
class CacheService
{
    private string $cacheDir;
    
    public function __construct(string $cacheDir = null)
    {
        // Default cache directory is in the project's cache folder
        $this->cacheDir = $cacheDir ?? dirname(__DIR__, 2) . '/cache';
        
        // Ensure cache directory exists
        if (!is_dir($this->cacheDir)) {
            $created = mkdir($this->cacheDir, 0755, true);
            if (!$created) {
                Logger::error("Failed to create cache directory", [
                    'dir' => $this->cacheDir
                ]);
            }
        }
    }
    
    /**
     * Get a value from cache
     */
    public function get(string $key): mixed
    {
        $filename = $this->getCacheFilename($key);
        
        if (!file_exists($filename)) {
            return null;
        }
        
        $data = file_get_contents($filename);
        if ($data === false) {
            Logger::error("Failed to read cache file", ['file' => $filename]);
            return null;
        }
        
        $cacheData = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::error("Failed to decode cache data", [
                'error' => json_last_error_msg(),
                'file' => $filename
            ]);
            return null;
        }
        
        // Check if cache has expired
        if (isset($cacheData['expires']) && time() > $cacheData['expires']) {
            $this->delete($key);
            return null;
        }
        
        return $cacheData['data'] ?? null;
    }
    
    /**
     * Store a value in cache
     */
    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        $filename = $this->getCacheFilename($key);
        
        $cacheData = [
            'expires' => time() + $ttl,
            'data' => $value
        ];
        
        $json = json_encode($cacheData, JSON_PRETTY_PRINT);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::error("Failed to encode cache data", [
                'error' => json_last_error_msg(),
                'key' => $key
            ]);
            return false;
        }
        
        $result = file_put_contents($filename, $json, LOCK_EX);
        if ($result === false) {
            Logger::error("Failed to write cache file", ['file' => $filename]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Delete a value from cache
     */
    public function delete(string $key): bool
    {
        $filename = $this->getCacheFilename($key);
        
        if (file_exists($filename)) {
            return unlink($filename);
        }
        
        return true;
    }
    
    /**
     * Clear all cache entries
     */
    public function clear(): bool
    {
        $files = glob($this->cacheDir . '/*.cache');
        if ($files === false) {
            return false;
        }
        
        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        
        return true;
    }
    
    /**
     * Get the filename for a cache key
     */
    private function getCacheFilename(string $key): string
    {
        // Create a safe filename from the key
        $safeKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
        return $this->cacheDir . '/' . $safeKey . '.cache';
    }
} 