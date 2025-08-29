<?php
declare(strict_types=1);

namespace App\Services;

use App\Services\LoggerService as Logger;

class ImageCache
{
    // Keep existing public uploads for minimal change, but we will validate strictly before writing.
    private const POSTER_DIR = __DIR__ . '/../../public/uploads/posters';
    private const BACKDROP_DIR = __DIR__ . '/../../public/uploads/backdrops';

    // Allowed remote hosts for images (TMDb secure image CDN)
    private const ALLOWED_HOSTS = ['image.tmdb.org'];

    // Allowed mime types and their preferred extensions
    private const MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    // Max download size in bytes (e.g., 5 MB)
    private const MAX_BYTES = 5_242_880; // 5 MB

    // HTTP timeouts
    private const TIMEOUT = 10.0; // seconds
    private const CONNECT_TIMEOUT = 5.0; // seconds
    
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

            // Validate type
            $type = ($type === 'backdrop') ? 'backdrop' : 'poster';

            // Parse and validate URL against allowlist
            $parts = parse_url($url);
            if (!$parts || !isset($parts['scheme'], $parts['host'])) {
                Logger::warning('Invalid URL for image cache', ['url' => $url]);
                return null;
            }
            $host = strtolower($parts['host']);
            $isAllowed = in_array($host, self::ALLOWED_HOSTS, true);
            if (!$isAllowed) {
                Logger::warning('Blocked image download due to host not in allowlist', [
                    'url' => $url, 'host' => $host
                ]);
                return null;
            }

            // Determine directories
            $baseDir = ($type === 'poster') ? self::POSTER_DIR : self::BACKDROP_DIR;
            $baseDir = realpath($baseDir) ?: $baseDir;

            // Ensure directory exists with secure perms
            if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true) && !is_dir($baseDir)) {
                Logger::error('Failed to create image cache directory', ['dir' => $baseDir]);
                return null;
            }

            // Create a temporary file for streaming download
            $tmpPath = tempnam(sys_get_temp_dir(), 'imgcache_');
            if ($tmpPath === false) {
                Logger::error('Failed to create temp file for image download');
                return null;
            }

            // Stream download via Guzzle with TLS verification and size cap
            $client = new \GuzzleHttp\Client([
                'verify' => true,
                'timeout' => self::TIMEOUT,
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'http_errors' => false,
                'headers' => [
                    'User-Agent' => 'MovieCollector/1.0'
                ],
            ]);

            $bytesReceived = 0;
            $mimeType = null;

            try {
                $response = $client->request('GET', $url, [
                    'stream' => true,
                    'on_headers' => function ($resp) use (&$mimeType) {
                        $ct = $resp->getHeaderLine('Content-Type');
                        $mimeType = $ct ? trim(explode(';', $ct, 2)[0]) : null;
                    },
                ]);
            } catch (\Throwable $e) {
                @unlink($tmpPath);
                Logger::error('HTTP error during image download', ['url' => $url, 'error' => $e->getMessage()]);
                return null;
            }

            $status = $response->getStatusCode();
            if ($status >= 400) {
                @unlink($tmpPath);
                Logger::warning('Image download returned error status', ['url' => $url, 'status' => $status]);
                return null;
            }

            // Open temp file handle
            $fh = fopen($tmpPath, 'wb');
            if ($fh === false) {
                @unlink($tmpPath);
                Logger::error('Failed to open temp file for writing image');
                return null;
            }

            // Stream body and enforce size limit
            $body = $response->getBody();
            while (!$body->eof()) {
                $chunk = $body->read(8192);
                if ($chunk === '') {
                    break;
                }
                $bytesReceived += strlen($chunk);
                if ($bytesReceived > self::MAX_BYTES) {
                    fclose($fh);
                    @unlink($tmpPath);
                    Logger::warning('Image exceeds maximum allowed size', [
                        'url' => $url, 'bytes' => $bytesReceived
                    ]);
                    return null;
                }
                fwrite($fh, $chunk);
            }
            fclose($fh);

            // Detect mime by magic bytes as primary, fallback to header
            $detected = self::detectMime($tmpPath);
            if ($detected) {
                $mimeType = $detected;
            }

            if (!$mimeType || !isset(self::MIME_EXT[$mimeType])) {
                @unlink($tmpPath);
                Logger::warning('Unsupported or undetected image MIME type', [
                    'url' => $url, 'mime' => $mimeType
                ]);
                return null;
            }

            $ext = self::MIME_EXT[$mimeType];

            // Build destination filename based on URL and MIME
            $hash = md5($url);
            $filename = $hash . '.' . $ext;
            $destPath = $baseDir . DIRECTORY_SEPARATOR . $filename;

            // If already exists, cleanup temp and return web path
            if (is_readable($destPath)) {
                @unlink($tmpPath);
                return '/uploads/' . ($type === 'poster' ? 'posters/' : 'backdrops/') . $filename;
            }

            // Move temp file to destination atomically
            if (!rename($tmpPath, $destPath)) {
                @unlink($tmpPath);
                Logger::error('Failed to move downloaded image to destination', [
                    'dest' => $destPath
                ]);
                return null;
            }

            // Set safe permissions
            @chmod($destPath, 0644);

            Logger::info('Image cached successfully', [
                'url' => $url,
                'dest' => $destPath,
                'bytes' => $bytesReceived,
                'mime' => $mimeType
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
    private static function detectMime(string $path): ?string
    {
        // Try finfo if available
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $path) ?: null;
                finfo_close($finfo);
                if ($mime) {
                    return $mime;
                }
            }
        }
        // Simple magic byte checks
        $fh = @fopen($path, 'rb');
        if ($fh) {
            $sig = fread($fh, 12);
            fclose($fh);
            if ($sig !== false) {
                // JPEG: FF D8 FF
                if (strncmp($sig, "\xFF\xD8\xFF", 3) === 0) {
                    return 'image/jpeg';
                }
                // PNG: 89 50 4E 47 0D 0A 1A 0A
                if (strncmp($sig, "\x89PNG\x0D\x0A\x1A\x0A", 8) === 0) {
                    return 'image/png';
                }
                // WebP: RIFF....WEBP
                if (strncmp($sig, "RIFF", 4) === 0 && substr($sig, 8, 4) === 'WEBP') {
                    return 'image/webp';
                }
            }
        }
        return null;
    }
    
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