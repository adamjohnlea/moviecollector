<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\UserSettings;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use App\Services\LoggerService as Logger;

class TmdbService
{
    private const BASE_URL = 'https://api.themoviedb.org/3/';
    private Client $client;
    private ?string $apiKey;
    private ?string $accessToken;
    private ?array $configuration = null;
    
    /**
     * Create a new TMDb service instance
     */
    public function __construct(?UserSettings $userSettings = null)
    {
        $this->client = new Client([
            'base_uri' => self::BASE_URL,
            'timeout' => 5.0,
            'verify' => true,
            'http_errors' => false  // Don't throw exceptions for HTTP errors
        ]);
        
        if ($userSettings) {
            $this->apiKey = $userSettings->getTmdbApiKey();
            $this->accessToken = $userSettings->getTmdbAccessToken();
            
            // Load configuration immediately
            $this->configuration = $this->getConfiguration();
        } else {
            $this->apiKey = null;
            $this->accessToken = null;
            $this->configuration = null;
        }
    }
    
    /**
     * Search for movies by title
     */
    public function searchMovies(string $query, int $page = 1): ?array
    {
        if (empty($query)) {
            Logger::warning('TMDb search called with empty query');
            return null;
        }
        
        try {
            Logger::info('TMDb search', [
                'query' => $query,
                'using_api_key' => empty($this->apiKey) ? 'no' : 'yes',
                'using_access_token' => empty($this->accessToken) ? 'no' : 'yes',
                'page' => $page
            ]);
            
            $options = [
                'query' => [
                    'query' => $query,
                    'page' => $page,
                    'include_adult' => false,
                    'language' => 'en-US'
                ]
            ];
            
            Logger::debug('TMDb search request options', ['options' => $options]);
            $response = $this->request('GET', '/search/movie', $options);
            Logger::debug('TMDb search response received', [ 'has_response' => $response !== null ? 'yes' : 'no' ]);
            
            if (!$response) {
                Logger::warning('TMDb search: no response from API');
                return null;
            }
            
            if (isset($response['success']) && $response['success'] === false) {
                Logger::error('TMDb search: API returned error', [ 'status_message' => $response['status_message'] ?? 'Unknown error' ]);
                return null;
            }
            
            if (!isset($response['results'])) {
                Logger::error('TMDb search: response missing results array', ['response' => $response]);
                return null;
            }
            
            Logger::info('TMDb search successful', ['count' => count($response['results'])]);
            return $response;
            
        } catch (RequestException $e) {
            Logger::error('TMDb search failed', [
                'error' => $e->getMessage(),
                'url' => (string) $e->getRequest()->getUri()
            ]);
            if ($e->hasResponse()) {
                Logger::error('TMDb search response error', [
                    'status' => $e->getResponse()->getStatusCode(),
                    'body' => (string) $e->getResponse()->getBody()
                ]);
            }
            return null;
        } catch (\Exception $e) {
            Logger::error('TMDb unexpected error during search', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }
    
    /**
     * Get movie details including certifications, keywords, and watch providers
     */
    public function getMovie(int $movieId): ?array
    {
        Logger::info("Getting movie details from TMDb", ['movie_id' => $movieId]);
        
        try {
            // Get basic movie data
            $movieData = $this->request('GET', "movie/{$movieId}");
            if (!$movieData) {
                return null;
            }
            
            // Get release dates (for certifications)
            $releaseDates = $this->request('GET', "movie/{$movieId}/release_dates");
            if ($releaseDates && isset($releaseDates['results'])) {
                $movieData['certifications'] = json_encode($releaseDates['results']);
            }
            
            // Get keywords
            $keywords = $this->request('GET', "movie/{$movieId}/keywords");
            if ($keywords && isset($keywords['keywords'])) {
                $movieData['keywords'] = json_encode($keywords['keywords']);
            }
            
            // Get watch providers
            $watchProviders = $this->request('GET', "movie/{$movieId}/watch/providers");
            if ($watchProviders && isset($watchProviders['results'])) {
                $movieData['watch_providers'] = json_encode($watchProviders['results']);
            }
            
            return $movieData;
        } catch (\Exception $e) {
            Logger::error("Error getting movie details from TMDb", [
                'movie_id' => $movieId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Get popular movies
     */
    public function getPopularMovies(int $page = 1): ?array
    {
        try {
            $response = $this->request('GET', '/movie/popular', [
                'query' => ['page' => $page]
            ]);
            
            return $response;
        } catch (RequestException $e) {
            return null;
        }
    }
    
    /**
     * Get configuration data (image URLs, etc.)
     */
    public function getConfiguration(): ?array
    {
        if ($this->configuration !== null) {
            return $this->configuration;
        }

        try {
            $response = $this->request('GET', '/configuration');
            if ($response) {
                $this->configuration = $response;
            }
            return $response;
        } catch (RequestException $e) {
            Logger::error('TMDb getConfiguration failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
    
    /**
     * Get the full image URL
     */
    public function getImageUrl(?string $path, string $size = 'w500'): ?string
    {
        if (empty($path)) {
            Logger::info("Empty image path provided");
            return null;
        }
        
        try {
            Logger::info("Getting image URL", [
                'path' => $path,
                'size' => $size
            ]);
            
            // Get configuration, using cached version if available
            $config = $this->getConfiguration();
            if (!$config) {
                Logger::error("No TMDb configuration available");
                return null;
            }
            
            if (empty($config['images']['secure_base_url'])) {
                Logger::error("Configuration missing secure_base_url", [
                    'config' => $config
                ]);
                return null;
            }
            
            $baseUrl = $config['images']['secure_base_url'];
            $fullUrl = $baseUrl . $size . $path;
            
            Logger::info("Generated image URL", [
                'base_url' => $baseUrl,
                'size' => $size,
                'path' => $path,
                'full_url' => $fullUrl
            ]);
            
            return $fullUrl;
        } catch (\Exception $e) {
            Logger::error("Failed to get image URL", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }
    
    /**
     * Make an API request to TMDb
     */
    private function request(string $method, string $endpoint, array $options = []): ?array
    {
        // Prepare request options
        if (!isset($options['headers'])) {
            $options['headers'] = [];
        }
        
        // Add accept header for JSON
        $options['headers']['Accept'] = 'application/json';
        
        // Use Bearer token if available, otherwise API key
        if (!empty($this->accessToken)) {
            Logger::debug('TMDb auth mode', ['mode' => 'access_token']);
            $options['headers']['Authorization'] = 'Bearer ' . $this->accessToken;
            $options['headers']['Content-Type'] = 'application/json;charset=utf-8';
        } elseif (!empty($this->apiKey)) {
            Logger::debug('TMDb auth mode', ['mode' => 'api_key']);
            if (!isset($options['query'])) {
                $options['query'] = [];
            }
            $options['query']['api_key'] = $this->apiKey;
        } else {
            Logger::error('TMDb request called without credentials');
            throw new \RuntimeException('No TMDb API credentials available');
        }
        
        try {
            // Remove leading slash if present
            $endpoint = ltrim($endpoint, '/');
            
            $fullUrl = rtrim(self::BASE_URL, '/') . '/' . $endpoint;
            if (!empty($options['query'])) {
                $fullUrl .= '?' . http_build_query($options['query']);
            }
            Logger::debug('TMDb request', ['url' => $fullUrl, 'endpoint' => $endpoint, 'headers' => $options['headers']]);
            
            $response = $this->client->request($method, $endpoint, $options);
            $body = (string) $response->getBody();
            $statusCode = $response->getStatusCode();
            
            Logger::debug('TMDb response', ['status' => $statusCode]);
            
            if ($statusCode >= 400) {
                Logger::error('TMDb request failed', ['status' => $statusCode, 'body' => $body]);
                return null;
            }
            
            $data = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Logger::error('TMDb JSON decode error', ['error' => json_last_error_msg()]);
                return null;
            }
            
            return $data;
        } catch (\Exception $e) {
            Logger::error('TMDb request exception', [
                'error' => $e->getMessage()
            ]);
            if ($e instanceof RequestException && $e->hasResponse()) {
                $response = $e->getResponse();
                Logger::error('TMDb request exception response', [
                    'status' => $response->getStatusCode(),
                    'body' => (string) $response->getBody()
                ]);
            }
            return null;  // Return null instead of throwing
        }
    }

    /**
     * Get complete movie details from multiple endpoints in parallel
     */
    public function getCompleteMovieDetails(int $movieId): ?array
    {
        Logger::info("Getting complete movie details from TMDb", ['movie_id' => $movieId]);
        
        try {
            // Create an array of promises for parallel requests
            $promises = [
                'base' => $this->client->requestAsync('GET', "movie/{$movieId}", $this->prepareRequestOptions()),
                'credits' => $this->client->requestAsync('GET', "movie/{$movieId}/credits", $this->prepareRequestOptions()),
                'similar' => $this->client->requestAsync('GET', "movie/{$movieId}/similar", $this->prepareRequestOptions()),
                'recommendations' => $this->client->requestAsync('GET', "movie/{$movieId}/recommendations", $this->prepareRequestOptions()),
                'videos' => $this->client->requestAsync('GET', "movie/{$movieId}/videos", $this->prepareRequestOptions()),
                'images' => $this->client->requestAsync('GET', "movie/{$movieId}/images", $this->prepareRequestOptions([
                    'query' => ['include_image_language' => 'en,null']
                ])),
                'reviews' => $this->client->requestAsync('GET', "movie/{$movieId}/reviews", $this->prepareRequestOptions()),
                'external_ids' => $this->client->requestAsync('GET', "movie/{$movieId}/external_ids", $this->prepareRequestOptions()),
                'certifications' => $this->client->requestAsync('GET', "movie/{$movieId}/release_dates", $this->prepareRequestOptions()),
                'keywords' => $this->client->requestAsync('GET', "movie/{$movieId}/keywords", $this->prepareRequestOptions()),
                'watch_providers' => $this->client->requestAsync('GET', "movie/{$movieId}/watch/providers", $this->prepareRequestOptions())
            ];
            
            // Wait for all promises to complete
            $results = \GuzzleHttp\Promise\Utils::settle($promises)->wait();
            
            // Process results
            $movieData = $this->processApiResponse($results['base']);
            if (!$movieData) {
                Logger::error("Failed to get base movie data", ['movie_id' => $movieId]);
                return null;
            }
            
            // Process credits (cast and crew)
            if ($credits = $this->processApiResponse($results['credits'])) {
                $movieData['credits'] = json_encode($credits);
            }
            
            // Process similar movies
            if ($similar = $this->processApiResponse($results['similar'])) {
                $movieData['similar'] = json_encode($similar);
            }
            
            // Process recommendations
            if ($recommendations = $this->processApiResponse($results['recommendations'])) {
                $movieData['recommendations'] = json_encode($recommendations);
            }
            
            // Process videos (trailers, teasers, etc.)
            if ($videos = $this->processApiResponse($results['videos'])) {
                $movieData['videos'] = json_encode($videos);
            }
            
            // Process images (backdrops, posters)
            if ($images = $this->processApiResponse($results['images'])) {
                $movieData['images'] = json_encode($images);
            }
            
            // Process reviews
            if ($reviews = $this->processApiResponse($results['reviews'])) {
                $movieData['reviews'] = json_encode($reviews);
            }
            
            // Process external IDs (IMDB, Facebook, etc.)
            if ($externalIds = $this->processApiResponse($results['external_ids'])) {
                $movieData['external_ids'] = json_encode($externalIds);
            }
            
            // Process certifications
            if ($certifications = $this->processApiResponse($results['certifications'])) {
                $movieData['certifications'] = json_encode($certifications['results'] ?? []);
            }
            
            // Process keywords
            if ($keywords = $this->processApiResponse($results['keywords'])) {
                $movieData['keywords'] = json_encode($keywords['keywords'] ?? []);
            }
            
            // Process watch providers
            if ($watchProviders = $this->processApiResponse($results['watch_providers'])) {
                $movieData['watch_providers'] = json_encode($watchProviders['results'] ?? []);
            }
            
            return $movieData;
        } catch (\Exception $e) {
            Logger::error("Error getting complete movie details from TMDb", [
                'movie_id' => $movieId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Fallback to sequential method if parallel fails
            Logger::info("Falling back to sequential movie details retrieval", ['movie_id' => $movieId]);
            return $this->getMovie($movieId);
        }
    }

    /**
     * Process an API response from a promise result
     */
    private function processApiResponse($result): ?array
    {
        if ($result['state'] !== 'fulfilled') {
            Logger::error("API request failed", [
                'reason' => $result['reason'] ? $result['reason']->getMessage() : 'Unknown reason'
            ]);
            return null;
        }
        
        $response = $result['value'];
        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();
        
        if ($statusCode >= 400) {
            Logger::error("API request returned error status", [
                'status_code' => $statusCode,
                'body' => $body
            ]);
            return null;
        }
        
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::error("Failed to decode JSON response", [
                'error' => json_last_error_msg(),
                'body' => $body
            ]);
            return null;
        }
        
        return $data;
    }

    /**
     * Prepare common request options
     */
    private function prepareRequestOptions(): array
    {
        $options = [
            'headers' => [
                'Accept' => 'application/json'
            ]
        ];
        
        // Use Bearer token if available, otherwise API key
        if (!empty($this->accessToken)) {
            $options['headers']['Authorization'] = 'Bearer ' . $this->accessToken;
            $options['headers']['Content-Type'] = 'application/json;charset=utf-8';
        } elseif (!empty($this->apiKey)) {
            if (!isset($options['query'])) {
                $options['query'] = [];
            }
            $options['query']['api_key'] = $this->apiKey;
        } else {
            throw new \RuntimeException('No TMDb API credentials available');
        }
        
        return $options;
    }

    /**
     * Get movie details with caching support
     */
    public function getMovieWithCache(int $movieId, bool $forceRefresh = false): ?array
    {
        $cacheKey = "tmdb_movie_{$movieId}";
        $cache = new \App\Services\CacheService();
        
        // Check if we have cached data and it's not a forced refresh
        if (!$forceRefresh) {
            $cachedData = $cache->get($cacheKey);
            if ($cachedData) {
                Logger::info("Using cached movie data", ['movie_id' => $movieId]);
                return $cachedData;
            }
        }
        
        // No cached data or force refresh, get from API
        Logger::info("Fetching fresh movie data from TMDb API", ['movie_id' => $movieId]);
        $movieData = $this->getCompleteMovieDetails($movieId);
        
        if ($movieData) {
            // Cache the result for 24 hours (86400 seconds)
            $cache->set($cacheKey, $movieData, 86400);
            Logger::info("Cached movie data", ['movie_id' => $movieId]);
        }
        
        return $movieData;
    }
} 