<?php
declare(strict_types=1);

namespace App\Services;

use App\Services\LoggerService as Logger;

class ImageCache
{
    private const POSTER_DIR = __DIR__ . '/../../public/uploads/posters';
    private const BACKDROP_DIR = __DIR__ . '/../../public/uploads/backdrops';
    
    /**
     * Cache an image from a URL
     */
    public static function cacheImage(string $url, string $type = 'poster'): ?string
    {
        try {
            Logger::info("Starting image caching process", [
                'url' => $url,
                'type' => $type,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time')
            ]);
            
            // Generate a unique filename based on the URL
            $filename = md5($url) . '.jpg';
            $directory = $type === 'poster' ? self::POSTER_DIR : self::BACKDROP_DIR;
            $directory = realpath($directory) ?: $directory;
            $localPath = $directory . DIRECTORY_SEPARATOR . $filename;
            
            Logger::info("Generated paths", [
                'directory' => $directory,
                'directory_exists' => is_dir($directory) ? 'yes' : 'no',
                'directory_writable' => is_writable($directory) ? 'yes' : 'no',
                'localPath' => $localPath,
                'filename' => $filename
            ]);
            
            // If file already exists and is readable, return its path
            if (is_readable($localPath)) {
                Logger::info("Image already cached", [
                    'path' => $localPath,
                    'size' => filesize($localPath)
                ]);
                return '/uploads/' . ($type === 'poster' ? 'posters/' : 'backdrops/') . $filename;
            }
            
            // Download and save the image
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'user_agent' => 'MovieCollector/1.0',
                    'follow_location' => true
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]);
            
            Logger::info("Attempting to download image", [
                'url' => $url,
                'context' => stream_context_get_options($context)
            ]);
            
            $imageData = @file_get_contents($url, false, $context);
            
            if ($imageData === false) {
                $error = error_get_last();
                Logger::error("Failed to download image", [
                    'url' => $url,
                    'error_message' => $error['message'] ?? 'Unknown error',
                    'error_type' => $error['type'] ?? 'Unknown type',
                    'error_file' => $error['file'] ?? 'Unknown file',
                    'error_line' => $error['line'] ?? 'Unknown line'
                ]);
                return null;
            }
            
            Logger::info("Successfully downloaded image", [
                'url' => $url,
                'size' => strlen($imageData),
                'mime_type' => mime_content_type('data://application/octet-stream;base64,' . base64_encode($imageData))
            ]);
            
            // Ensure directory exists with proper permissions
            if (!is_dir($directory)) {
                Logger::info("Creating directory", [
                    'path' => $directory,
                    'permissions' => '0755'
                ]);
                if (!mkdir($directory, 0755, true)) {
                    $error = error_get_last();
                    Logger::error("Failed to create directory", [
                        'path' => $directory,
                        'error_message' => $error['message'] ?? 'Unknown error',
                        'error_type' => $error['type'] ?? 'Unknown type'
                    ]);
                    return null;
                }
            }
            
            // Save the image
            Logger::info("Attempting to save image", [
                'path' => $localPath,
                'size' => strlen($imageData)
            ]);
            
            $bytesWritten = @file_put_contents($localPath, $imageData);
            if ($bytesWritten === false) {
                $error = error_get_last();
                Logger::error("Failed to save image", [
                    'path' => $localPath,
                    'error_message' => $error['message'] ?? 'Unknown error',
                    'error_type' => $error['type'] ?? 'Unknown type',
                    'directory_exists' => is_dir($directory) ? 'yes' : 'no',
                    'directory_writable' => is_writable($directory) ? 'yes' : 'no'
                ]);
                return null;
            }
            
            Logger::info("Successfully saved image", [
                'path' => $localPath,
                'bytes_written' => $bytesWritten,
                'file_exists' => file_exists($localPath) ? 'yes' : 'no',
                'file_size' => file_exists($localPath) ? filesize($localPath) : 0
            ]);
            
            return '/uploads/' . ($type === 'poster' ? 'posters/' : 'backdrops/') . $filename;
        } catch (\Exception $e) {
            Logger::error("Exception while caching image", [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }
    
    /**
     * Remove a cached image
     */
    public static function removeImage(?string $localPath): bool
    {
        if (!$localPath) {
            return true;
        }
        
        try {
            $fullPath = __DIR__ . '/../../public' . $localPath;
            $fullPath = realpath($fullPath) ?: $fullPath;
            
            Logger::info("Attempting to remove image", [
                'path' => $fullPath,
                'file_exists' => file_exists($fullPath) ? 'yes' : 'no',
                'is_file' => is_file($fullPath) ? 'yes' : 'no',
                'is_writable' => is_writable($fullPath) ? 'yes' : 'no'
            ]);
            
            if (file_exists($fullPath)) {
                $result = unlink($fullPath);
                Logger::info($result ? "Successfully removed image" : "Failed to remove image", [
                    'path' => $fullPath,
                    'result' => $result ? 'success' : 'failure'
                ]);
                return $result;
            }
            
            Logger::info("Image file does not exist", ['path' => $fullPath]);
            return true;
        } catch (\Exception $e) {
            Logger::error("Error removing cached image", [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'path' => $localPath
            ]);
            return false;
        }
    }
} 