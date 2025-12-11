<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\Movie;
use App\Models\UserSettings;
use App\Services\SessionService;
use App\Services\TmdbService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Services\ImageCache;
use App\Database\Database;
use App\Services\LoggerService as Logger;
use App\Services\LoggerService;
use App\Models\WatchedLog;

class MovieController extends Controller
{
    /**
     * Show search form
     */
    public function showSearchForm(Request $request): Response
    {
        // Check if user is logged in
        $redirect = $this->requireLogin();
        if ($redirect) {
            return $redirect;
        }
        
        $user = SessionService::getCurrentUser();
        $userSettings = UserSettings::getByUserId($user->getId());
        
        // Check if the user has TMDb credentials
        if (!$userSettings || !$userSettings->hasValidTmdbCredentials()) {
            return $this->renderResponse('movie/missing_credentials.twig');
        }
        
        return $this->renderResponse('movie/search.twig');
    }
    
    /**
     * Search for movies
     */
    public function search(Request $request): Response
    {
        // Check if user is logged in
        $redirect = $this->requireLogin();
        if ($redirect) {
            LoggerService::warning('[Movie] User not logged in');
            return $redirect;
        }
        
        $user = SessionService::getCurrentUser();
        Logger::info("[Movie Search] User ID: " . $user->getId());
        
        $userSettings = UserSettings::getByUserId($user->getId());
        Logger::info("[Movie Search] Has user settings: " . ($userSettings ? 'yes' : 'no'));
        Logger::info("[Movie Search] Has API key: " . (!empty($userSettings->getTmdbApiKey()) ? 'yes' : 'no'));
        Logger::info("[Movie Search] Has access token: " . (!empty($userSettings->getTmdbAccessToken()) ? 'yes' : 'no'));
        
        // Check if the user has TMDb credentials
        if (!$userSettings || !$userSettings->hasValidTmdbCredentials()) {
            Logger::info("[Movie Search] No valid TMDb credentials");
            return $this->renderResponse('movie/missing_credentials.twig');
        }
        
        $query = $request->query->get('query');
        Logger::info("[Movie Search] Search query: " . $query);
        
        if (empty($query)) {
            Logger::info("[Movie Search] Empty search query");
            return $this->renderResponse('movie/search.twig');
        }
        
        // Search TMDb for movies
        try {
            $tmdbService = new TmdbService($userSettings);
            Logger::info("[Movie Search] Created TMDb service");
            
            Logger::info("[Movie Search] About to call searchMoviesCached with query: " . $query);
            $searchResults = $tmdbService->searchMoviesCached($query, 1);
            Logger::info("[Movie Search] Raw search results: " . json_encode($searchResults));
            Logger::info("[Movie Search] Search completed. Has results: " . ($searchResults && isset($searchResults['results']) ? 'yes' : 'no'));
            
            if (!$searchResults) {
                Logger::info("[Movie Search] searchResults is null or false");
                return $this->renderResponse('movie/search.twig', [
                    'error' => 'An error occurred while searching for movies',
                    'query' => $query
                ]);
            }
            
            if (!isset($searchResults['results'])) {
                Logger::info("[Movie Search] searchResults missing 'results' key. Keys present: " . implode(', ', array_keys($searchResults)));
                return $this->renderResponse('movie/search.twig', [
                    'error' => 'An error occurred while searching for movies',
                    'query' => $query
                ]);
            }
            
            $movies = $searchResults['results'];
            Logger::info("[Movie Search] Found " . count($movies) . " movies");
            
            // Add full image URLs and check if movie is in collection
            foreach ($movies as &$movie) {
                Logger::info("[Movie Search] Processing movie: " . json_encode($movie));
                if (!empty($movie['poster_path'])) {
                    $posterUrl = $tmdbService->getImageUrl($movie['poster_path']);
                    Logger::info("[Movie Search] Got poster URL: " . ($posterUrl ?? 'null'));
                    
                    if ($posterUrl) {
                        // Try to get cached image
                        $localPath = ImageCache::cacheImage($posterUrl, 'poster');
                        Logger::info("[Movie Search] Caching result", [
                            'local_path' => $localPath,
                            'tmdb_url' => $posterUrl
                        ]);
                        
                        if ($localPath) {
                            $movie['poster_url'] = 'https://moviecollector.test' . $localPath;
                        } else {
                            $movie['poster_url'] = $posterUrl;
                        }
                    } else {
                        Logger::info("[Movie Search] No poster URL generated");
                        $movie['poster_url'] = null;
                    }
                } else {
                    Logger::info("[Movie Search] No poster path for movie");
                    $movie['poster_url'] = null;
                }
                
                if (isset($movie['id'])) {
                    $movie['in_collection'] = Movie::isInAnyList($user->getId(), $movie['id']);
                }
            }
            unset($movie); // Unset reference to avoid accidental modifications
            
            Logger::info("[Movie Search] Final processed movies: " . json_encode($movies));
            
            // Extract pagination data
            $pagination = [
                'current_page' => $searchResults['page'] ?? 1,
                'total_pages' => $searchResults['total_pages'] ?? 1,
                'total_results' => $searchResults['total_results'] ?? count($movies)
            ];
            
            return $this->renderResponse('movie/search.twig', [
                'movies' => $movies,
                'query' => $query,
                'pagination' => $pagination
            ]);
        } catch (\Exception $e) {
            Logger::info("[Movie Search] Exception: " . $e->getMessage());
            Logger::info("[Movie Search] Stack trace: " . $e->getTraceAsString());
            return $this->renderResponse('movie/search.twig', [
                'error' => 'An error occurred while searching for movies',
                'query' => $query
            ]);
        }
    }
    
    /**
     * Show movie details
     */
    public function show(Request $request, array $params): Response
    {
        // Check if user is logged in
        $redirect = $this->requireLogin();
        if ($redirect) {
            return $redirect;
        }
        
        $user = SessionService::getCurrentUser();
        $userSettings = UserSettings::getByUserId($user->getId());
        
        // Check if the user has TMDb credentials
        if (!$userSettings || !$userSettings->hasValidTmdbCredentials()) {
            return $this->renderResponse('movie/missing_credentials.twig');
        }
        
        $tmdbId = (int) ($params['id'] ?? 0);
        if ($tmdbId <= 0) {
            return $this->redirect('/movies/search');
        }
        
        $tmdbService = new TmdbService($userSettings);
        
        // Check if movie is in any list and get cached data
        $currentListType = Movie::getListType($user->getId(), $tmdbId);
        $inCollection = $currentListType !== null;
        $isOwned = $inCollection && $currentListType === Movie::LIST_COLLECTION;
        
        // Get movie data from cache or API
        $movieData = null;
        $cachedMovie = null;
        
        Logger::info("Checking movie cache status", [
            'tmdb_id' => $tmdbId,
            'in_collection' => $inCollection ? 'yes' : 'no',
            'list_type' => $currentListType
        ]);
        
        // Load formats list for the Format dropdown on the Watched panel (always needed)
        $userFormats = [];
        $allFormats = [];
        if (class_exists('\\App\\Models\\MovieFormat')) {
            try {
                // Load all available formats grouped by category
                $allFormats = \App\Models\MovieFormat::getFormatsByCategory();
                // Load user's selected formats for this movie (only meaningful when owned)
                if ($isOwned) {
                    $userFormats = \App\Models\MovieFormat::getFormatsForUserMovie($user->getId(), $tmdbId);
                }
            } catch (\Exception $e) {
                LoggerService::error('Error loading movie formats', [
                    'message' => $e->getMessage(),
                    'movie_id' => $tmdbId
                ]);
            }
        }
        
        if ($inCollection) {
            // Get movie from database
            $stmt = Database::getInstance()->prepare("
                SELECT * FROM movies 
                WHERE user_id = ? AND tmdb_id = ?
            ");
            $stmt->execute([$user->getId(), $tmdbId]);
            $result = $stmt->fetch();
            Logger::info("Retrieved movie from database", [
                'found' => $result ? 'yes' : 'no',
                'tmdb_id' => $tmdbId
            ]);
            
            if ($result) {
                $cachedMovie = Movie::createFromArray($result);
                Logger::info("Created Movie object from database row", [
                    'tmdb_id' => $tmdbId,
                    'title' => $cachedMovie->getTitle(),
                    'cache_stale' => $cachedMovie->isCacheStale() ? 'yes' : 'no'
                ]);
                
                // Check if cache is stale
                if (!$cachedMovie->isCacheStale()) {
                    Logger::info("Using cached movie data", [
                        'tmdb_id' => $tmdbId,
                        'title' => $cachedMovie->getTitle()
                    ]);
                    // Use cached data
                    $movieData = [
                        'id' => $tmdbId,
                        'title' => $cachedMovie->getTitle(),
                        'overview' => $cachedMovie->getOverview(),
                        'release_date' => $cachedMovie->getReleaseDate(),
                        'genres' => $cachedMovie->getGenres(),
                        'runtime' => $cachedMovie->getRuntime(),
                        'vote_average' => $cachedMovie->getVoteAverage(),
                        'vote_count' => $cachedMovie->getVoteCount(),
                        'production_companies' => $cachedMovie->getProductionCompanies(),
                        'poster_path' => $cachedMovie->getPosterPath(),
                        'backdrop_path' => $cachedMovie->getBackdropPath(),
                        'original_title' => $cachedMovie->getOriginalTitle(),
                        'tagline' => $cachedMovie->getTagline(),
                        'status' => $cachedMovie->getStatus(),
                        'production_countries' => $cachedMovie->getProductionCountries() ?? [],
                        'spoken_languages' => $cachedMovie->getSpokenLanguages() ?? [],
                        'budget' => $cachedMovie->getBudget(),
                        'revenue' => $cachedMovie->getRevenue(),
                        'homepage' => $cachedMovie->getHomepage(),
                        'imdb_id' => $cachedMovie->getImdbId(),
                        'original_language' => $cachedMovie->getOriginalLanguage(),
                        'popularity' => $cachedMovie->getPopularity(),
                        'adult' => $cachedMovie->isAdult(),
                        'video' => $cachedMovie->hasVideo(),
                        'certifications' => $cachedMovie->getCertifications(),
                        'keywords' => $cachedMovie->getKeywords(),
                        'watch_providers' => $cachedMovie->getWatchProviders(),
                        'credits' => $cachedMovie->getCredits(),
                        'similar' => $cachedMovie->getSimilar(),
                        'recommendations' => $cachedMovie->getRecommendations(),
                        'videos' => $cachedMovie->getVideos(),
                        'images' => $cachedMovie->getImages(),
                        'reviews' => $cachedMovie->getReviews(),
                        'external_ids' => $cachedMovie->getExternalIds(),
                        'last_updated_at' => $cachedMovie->getLastUpdatedAt(),
                        // Watched roll-ups
                        'watched_count' => method_exists($cachedMovie, 'getWatchedCount') ? $cachedMovie->getWatchedCount() : 0,
                        'last_watched_at' => method_exists($cachedMovie, 'getLastWatchedAt') ? $cachedMovie->getLastWatchedAt() : null,
                    ];

                    Logger::info("Checking cached image paths", [
                        'local_poster_path' => $cachedMovie->getLocalPosterPath(),
                        'local_backdrop_path' => $cachedMovie->getLocalBackdropPath(),
                        'poster_path' => $cachedMovie->getPosterPath(),
                        'backdrop_path' => $cachedMovie->getBackdropPath()
                    ]);

                    // If we have TMDb paths but no local paths, try to cache them
                    if (!$cachedMovie->getLocalPosterPath() && $cachedMovie->getPosterPath()) {
                        Logger::info("Attempting to cache missing poster image");
                        $posterUrl = $tmdbService->getImageUrl($cachedMovie->getPosterPath());
                        if ($posterUrl) {
                            $localPath = ImageCache::cacheImage($posterUrl, 'poster');
                            Logger::info("Poster caching result", [
                                'url' => $posterUrl,
                                'local_path' => $localPath,
                                'success' => $localPath !== null ? 'yes' : 'no'
                            ]);
                            if ($localPath) {
                                $cachedMovie->updateCachedData(['local_poster_path' => $localPath]);
                            }
                        }
                    }

                    if (!$cachedMovie->getLocalBackdropPath() && $cachedMovie->getBackdropPath()) {
                        Logger::info("Attempting to cache missing backdrop image");
                        $backdropUrl = $tmdbService->getImageUrl($cachedMovie->getBackdropPath(), 'original');
                        if ($backdropUrl) {
                            $localPath = ImageCache::cacheImage($backdropUrl, 'backdrop');
                            Logger::info("Backdrop caching result", [
                                'url' => $backdropUrl,
                                'local_path' => $localPath,
                                'success' => $localPath !== null ? 'yes' : 'no'
                            ]);
                            if ($localPath) {
                                $cachedMovie->updateCachedData(['local_backdrop_path' => $localPath]);
                            }
                        }
                    }

                    // Use local paths if available, fall back to TMDb URLs
                    $movieData['poster_url'] = $cachedMovie->getLocalPosterPath() ?? $tmdbService->getImageUrl($cachedMovie->getPosterPath());
                    $movieData['backdrop_url'] = $cachedMovie->getLocalBackdropPath() ?? $tmdbService->getImageUrl($cachedMovie->getBackdropPath(), 'original');

                    Logger::info("Final image URLs", [
                        'poster_url' => $movieData['poster_url'],
                        'backdrop_url' => $movieData['backdrop_url'],
                        'using_cached_poster' => $cachedMovie->getLocalPosterPath() !== null ? 'yes' : 'no',
                        'using_cached_backdrop' => $cachedMovie->getLocalBackdropPath() !== null ? 'yes' : 'no'
                    ]);
                } else {
                    Logger::info("Cache is stale, will fetch fresh data", [
                        'tmdb_id' => $tmdbId,
                        'title' => $cachedMovie->getTitle()
                    ]);
                }
            }
        }
        
        // If no cached data or cache is stale, fetch from TMDb
        if (!$movieData) {
            Logger::info("Fetching fresh movie data from TMDb", [
                'tmdb_id' => $tmdbId
            ]);
            
            // Use enhanced method to get complete movie details with parallel requests
            $movieData = $tmdbService->getCompleteMovieDetails($tmdbId);
            
            if (!$movieData) {
                return $this->renderResponse('movie/not_found.twig');
            }
            
            // Cache images if they exist and set URLs
            if (!empty($movieData['poster_path'])) {
                Logger::info("Processing poster image", [
                    'poster_path' => $movieData['poster_path'],
                    'movie_data' => array_intersect_key($movieData, array_flip(['id', 'title', 'poster_path']))
                ]);
                $posterUrl = $tmdbService->getImageUrl($movieData['poster_path']);
                Logger::info("Got poster URL from TMDb", [
                    'url' => $posterUrl,
                    'poster_path' => $movieData['poster_path']
                ]);
                if ($posterUrl) {
                    Logger::info("Calling ImageCache::cacheImage for poster", [
                        'url' => $posterUrl,
                        'type' => 'poster'
                    ]);
                    $localPath = ImageCache::cacheImage($posterUrl, 'poster');
                    Logger::info("ImageCache::cacheImage result for poster", [
                        'local_path' => $localPath,
                        'tmdb_url' => $posterUrl,
                        'success' => $localPath !== null ? 'yes' : 'no'
                    ]);
                    $movieData['poster_url'] = $localPath ?? $posterUrl;
                    Logger::info("Final poster URL set", [
                        'poster_url' => $movieData['poster_url'],
                        'is_cached' => $localPath !== null ? 'yes' : 'no'
                    ]);
                }
            }
            
            if (!empty($movieData['backdrop_path'])) {
                Logger::info("Processing backdrop image", [
                    'backdrop_path' => $movieData['backdrop_path'],
                    'movie_data' => array_intersect_key($movieData, array_flip(['id', 'title', 'backdrop_path']))
                ]);
                $backdropUrl = $tmdbService->getImageUrl($movieData['backdrop_path'], 'original');
                Logger::info("Got backdrop URL from TMDb", [
                    'url' => $backdropUrl,
                    'backdrop_path' => $movieData['backdrop_path']
                ]);
                if ($backdropUrl) {
                    Logger::info("Calling ImageCache::cacheImage for backdrop", [
                        'url' => $backdropUrl,
                        'type' => 'backdrop'
                    ]);
                    $localPath = ImageCache::cacheImage($backdropUrl, 'backdrop');
                    Logger::info("ImageCache::cacheImage result for backdrop", [
                        'local_path' => $localPath,
                        'tmdb_url' => $backdropUrl,
                        'success' => $localPath !== null ? 'yes' : 'no'
                    ]);
                    $movieData['backdrop_url'] = $localPath ?? $backdropUrl;
                    Logger::info("Final backdrop URL set", [
                        'backdrop_url' => $movieData['backdrop_url'],
                        'is_cached' => $localPath !== null ? 'yes' : 'no'
                    ]);
                }
            }
            
            // Update cached data if movie is in collection
            if ($cachedMovie) {
                $cachedMovie->updateCachedData([
                    'title' => $movieData['title'],
                    'overview' => $movieData['overview'],
                    'release_date' => $movieData['release_date'],
                    'genres' => json_encode($movieData['genres'] ?? []),
                    'runtime' => $movieData['runtime'] ?? null,
                    'vote_average' => $movieData['vote_average'] ?? null,
                    'vote_count' => $movieData['vote_count'] ?? null,
                    'production_companies' => json_encode($movieData['production_companies'] ?? []),
                    'poster_path' => $movieData['poster_path'] ?? null,
                    'backdrop_path' => $movieData['backdrop_path'] ?? null,
                    'local_poster_path' => $movieData['poster_url'] ?? null,
                    'local_backdrop_path' => $movieData['backdrop_url'] ?? null,
                    'original_title' => $movieData['original_title'] ?? null,
                    'tagline' => $movieData['tagline'] ?? null,
                    'status' => $movieData['status'] ?? null,
                    'production_countries' => json_encode($movieData['production_countries'] ?? []),
                    'spoken_languages' => json_encode($movieData['spoken_languages'] ?? []),
                    'budget' => $movieData['budget'] ?? null,
                    'revenue' => $movieData['revenue'] ?? null,
                    'homepage' => $movieData['homepage'] ?? null,
                    'imdb_id' => $movieData['imdb_id'] ?? null,
                    'original_language' => $movieData['original_language'] ?? null,
                    'popularity' => $movieData['popularity'] ?? null,
                    'adult' => $movieData['adult'] ?? false,
                    'video' => $movieData['video'] ?? false,
                    'certifications' => $movieData['certifications'] ?? null,
                    'keywords' => $movieData['keywords'] ?? null,
                    'watch_providers' => $movieData['watch_providers'] ?? null,
                    'credits' => $movieData['credits'] ?? null,
                    'similar' => $movieData['similar'] ?? null,
                    'recommendations' => $movieData['recommendations'] ?? null,
                    'videos' => $movieData['videos'] ?? null,
                    'images' => $movieData['images'] ?? null,
                    'reviews' => $movieData['reviews'] ?? null,
                    'external_ids' => $movieData['external_ids'] ?? null
                ]);
            }

            // Include watched roll-ups and last_updated_at if the movie exists in user's lists
            $stmt = Database::getInstance()->prepare("SELECT watched_count, last_watched_at, last_updated_at FROM movies WHERE user_id = ? AND tmdb_id = ? LIMIT 1");
            $stmt->execute([$user->getId(), $tmdbId]);
            $roll = $stmt->fetch();
            $movieData['watched_count'] = $roll ? (int)($roll['watched_count'] ?? 0) : 0;
            $movieData['last_watched_at'] = $roll['last_watched_at'] ?? null;
            $movieData['last_updated_at'] = $roll['last_updated_at'] ?? ($movieData['last_updated_at'] ?? null);

            // Fallback: when the movie isn't in any of the user's lists (no movies row),
            // compute roll-ups directly from watched_logs so the UI reflects watch history
            // for items added to the Watchlog from Search.
            if (!$roll) {
                try {
                    $db = Database::getInstance();
                    $r = $db->prepare("SELECT COUNT(*) AS cnt, MAX(watched_at) AS last_at FROM watched_logs WHERE user_id = ? AND tmdb_id = ?");
                    $r->execute([$user->getId(), $tmdbId]);
                    $agg = $r->fetch() ?: ['cnt' => 0, 'last_at' => null];
                    $movieData['watched_count'] = (int)($agg['cnt'] ?? 0);
                    $movieData['last_watched_at'] = $agg['last_at'] ?? null;
                } catch (\Throwable $e) {
                    // Best-effort; keep defaults on failure
                }
            }
        }
        
        // Define sections for the accordion UI
        $sections = [
            'overview' => [
                'title' => 'Overview & Details',
                'description' => 'Plot summary and basic information',
                'loaded' => true  // This section uses already loaded data
            ],
            'cast_crew' => [
                'title' => 'Cast & Crew',
                'description' => 'Actors, directors, and other crew members',
                'loaded' => isset($movieData['credits'])
            ],
            'videos' => [
                'title' => 'Trailers & Videos',
                'description' => 'Trailers, teasers, and other video content',
                'loaded' => isset($movieData['videos'])
            ],
            'images' => [
                'title' => 'Gallery',
                'description' => 'Movie posters and still images',
                'loaded' => isset($movieData['images'])
            ],
            'similar' => [
                'title' => 'Similar Movies',
                'description' => 'Movies you might also enjoy',
                'loaded' => isset($movieData['similar'])
            ],
            'recommendations' => [
                'title' => 'Recommended Movies',
                'description' => 'Recommended based on this movie',
                'loaded' => isset($movieData['recommendations'])
            ],
            'reviews' => [
                'title' => 'Reviews',
                'description' => 'User reviews and ratings',
                'loaded' => isset($movieData['reviews'])
            ],
            'more_info' => [
                'title' => 'Additional Information',
                'description' => 'Budget, revenue, status, and more',
                'loaded' => true  // This section uses already loaded data
            ],
            'watch' => [
                'title' => 'Where to Watch',
                'description' => 'Streaming and purchase options',
                'loaded' => isset($movieData['watch_providers'])
            ],
            'externalIds' => [
                'title' => 'External Links',
                'description' => 'Links to IMDb, social media, and other external sites',
                'loaded' => isset($movieData['external_ids'])
            ]
        ];
        
        // Apply display preferences if the movie is owned
        if ($isOwned && $userSettings) {
            LoggerService::info('Applying display preferences for owned movie', [
                'movie_id' => $tmdbId,
                'user_id' => $user->getId()
            ]);
            
            $displayPrefs = $userSettings->getDisplayPreferences();
            
            // Hide sections based on user preferences
            foreach ($displayPrefs as $section => $showSection) {
                if (isset($sections[$section]) && !$showSection) {
                    $sections[$section]['visible'] = false;
                    LoggerService::debug('Hiding section based on user preference', [
                        'section' => $section,
                        'movie_id' => $tmdbId
                    ]);
                }
            }
        }
        
        // Ensure all sections have a visibility flag
        foreach ($sections as $key => $section) {
            if (!isset($section['visible'])) {
                $sections[$key]['visible'] = true;
            }
        }
        
        return $this->renderResponse('movie/show.twig', [
            'movie' => $movieData,
            'in_collection' => $inCollection,
            'current_list_type' => $currentListType,
            'is_owned' => $isOwned,
            'sections' => $sections,
            'all_formats' => $allFormats,
            'user_formats' => $userFormats
        ]);
    }
    
    /**
     * View collection (owned media)
     */
    public function collection(Request $request): Response
    {
        // Check if user is logged in
        $redirect = $this->requireLogin();
        if ($redirect) {
            return $redirect;
        }
        
        $user = SessionService::getCurrentUser();
        $movies = Movie::getUserList($user->getId(), Movie::LIST_COLLECTION);
        
        // Get all movie IDs for efficient format retrieval
        $movieIds = array_map(function($movie) {
            return $movie->getTmdbId();
        }, $movies);
        
        // Fetch formats for all movies in one query for better performance
        $movieFormats = \App\Models\MovieFormat::getFormatsForMultipleMovies($user->getId(), $movieIds);
        
        return $this->renderResponse('movie/collection.twig', [
            'movies' => $movies,
            'movie_formats' => $movieFormats,
            'title' => 'Owned Media',
            'list_type' => Movie::LIST_COLLECTION
        ]);
    }
    
    /**
     * View to-watch list
     */
    public function toWatch(Request $request): Response
    {
        // Check if user is logged in
        $redirect = $this->requireLogin();
        if ($redirect) {
            return $redirect;
        }
        
        $user = SessionService::getCurrentUser();
        $movies = Movie::getUserList($user->getId(), Movie::LIST_TO_WATCH);
        
        // Get all movie IDs for efficient format retrieval
        $movieIds = array_map(function($movie) {
            return $movie->getTmdbId();
        }, $movies);
        
        // Fetch formats for all movies in one query for better performance
        $movieFormats = \App\Models\MovieFormat::getFormatsForMultipleMovies($user->getId(), $movieIds);
        
        return $this->renderResponse('movie/collection.twig', [
            'movies' => $movies,
            'movie_formats' => $movieFormats,
            'title' => 'Watch List',
            'list_type' => Movie::LIST_TO_WATCH
        ]);
    }
    
    /**
     * View to-buy list
     */
    public function toBuy(Request $request): Response
    {
        // Check if user is logged in
        $redirect = $this->requireLogin();
        if ($redirect) {
            return $redirect;
        }
        
        $user = SessionService::getCurrentUser();
        $movies = Movie::getUserList($user->getId(), Movie::LIST_TO_BUY);
        
        // Get all movie IDs for efficient format retrieval
        $movieIds = array_map(function($movie) {
            return $movie->getTmdbId();
        }, $movies);
        
        // Fetch formats for all movies in one query for better performance
        $movieFormats = \App\Models\MovieFormat::getFormatsForMultipleMovies($user->getId(), $movieIds);
        
        return $this->renderResponse('movie/collection.twig', [
            'movies' => $movies,
            'movie_formats' => $movieFormats,
            'title' => 'Buy List',
            'list_type' => Movie::LIST_TO_BUY
        ]);
    }
    
    /**
     * Add a movie to a specific list
     */
    public function addToList(Request $request, array $params): Response
    {
        $redirect = $this->requireLogin();
        if ($redirect) {
            return $redirect;
        }
        
        // Debug logging
        LoggerService::info('Request details for movie add', [
            'headers' => $request->headers->all(),
            'content' => $request->getContent(),
            'post_data' => $request->request->all(),
            'request_method' => $request->getMethod(),
        ]);
        
        // Check CSRF token from form submission
        $token = $request->request->get('csrf_token');
        
        if (!$this->verifyCsrfToken($token)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid request.'], 400);
        }
        
        // Get current user
        $user = SessionService::getCurrentUser();
        $userSettings = UserSettings::getByUserId($user->getId());
        
        $movieId = (int) ($params['id'] ?? 0);
        if ($movieId <= 0) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid movie ID.'], 400);
        }
        
        $listType = $request->request->get('list_type', Movie::LIST_COLLECTION);
        
        if (!in_array($listType, [Movie::LIST_COLLECTION, Movie::LIST_TO_WATCH, Movie::LIST_TO_BUY])) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid list type.'], 400);
        }
        
        // Check if already in any list
        $currentList = Movie::getListType($user->getId(), $movieId);
        if ($currentList !== null) {
            if ($currentList === $listType) {
                return new JsonResponse(['success' => true, 'message' => 'Movie already in this list.']);
            }
            
            // Move to new list
            $success = Movie::moveToList($user->getId(), $movieId, $listType);
            return new JsonResponse([
                'success' => $success,
                'message' => $success ? 'Movie moved to new list.' : 'Failed to move movie.'
            ]);
        }
        
        // Get movie details from TMDb
        $tmdbService = new TmdbService($userSettings);
        $movieData = $tmdbService->getCompleteMovieDetails($movieId);
        
        if (!$movieData) {
            return new JsonResponse(['success' => false, 'error' => 'Movie not found.'], 404);
        }
        
        // Cache images
        $localPosterPath = null;
        $localBackdropPath = null;
        
        if (isset($movieData['poster_path'])) {
            $posterUrl = $tmdbService->getImageUrl($movieData['poster_path']);
            $localPosterPath = ImageCache::cacheImage($posterUrl, 'poster');
        }
        
        if (isset($movieData['backdrop_path'])) {
            $backdropUrl = $tmdbService->getImageUrl($movieData['backdrop_path'], 'original');
            $localBackdropPath = ImageCache::cacheImage($backdropUrl, 'backdrop');
        }

        // Log the raw movie data for debugging
        Logger::info("Raw movie data from TMDb", [
            'movie_data' => array_intersect_key($movieData, array_flip([
                'title', 'original_title', 'tagline', 'status', 'genres',
                'production_countries', 'spoken_languages', 'budget', 'revenue',
                'certifications', 'keywords', 'watch_providers'
            ]))
        ]);
        
        // Add to list with cached data
        $movie = Movie::addToList(
            $user->getId(),
            $movieId,
            $movieData['title'],
            $listType,
            $movieData['poster_path'] ?? null,
            $movieData['backdrop_path'] ?? null,
            $movieData['overview'] ?? null,
            $movieData['release_date'] ?? null,
            isset($movieData['genres']) ? json_encode($movieData['genres']) : null,
            $movieData['runtime'] ?? null,
            $movieData['vote_average'] ?? null,
            $movieData['vote_count'] ?? null,
            isset($movieData['production_companies']) ? json_encode($movieData['production_companies']) : null,
            $localPosterPath,
            $localBackdropPath,
            $movieData['original_title'] ?? null,
            $movieData['tagline'] ?? null,
            $movieData['status'] ?? null,
            isset($movieData['production_countries']) ? json_encode($movieData['production_countries']) : null,
            isset($movieData['spoken_languages']) ? json_encode($movieData['spoken_languages']) : null,
            $movieData['budget'] ?? null,
            $movieData['revenue'] ?? null,
            $movieData['homepage'] ?? null,
            $movieData['imdb_id'] ?? null,
            $movieData['original_language'] ?? null,
            $movieData['popularity'] ?? null,
            $movieData['adult'] ?? false,
            $movieData['video'] ?? false,
            $movieData['certifications'] ?? null,
            $movieData['keywords'] ?? null,
            $movieData['watch_providers'] ?? null,
            $movieData['credits'] ?? null,
            $movieData['similar'] ?? null,
            $movieData['recommendations'] ?? null,
            $movieData['videos'] ?? null,
            $movieData['images'] ?? null,
            $movieData['reviews'] ?? null,
            $movieData['external_ids'] ?? null
        );
        
        if (!$movie) {
            return new JsonResponse(['success' => false, 'error' => 'Failed to add movie to list.'], 500);
        }
        
        return new JsonResponse(['success' => true, 'message' => 'Movie added to list.']);
    }
    
    /**
     * Remove a movie from any list
     */
    public function removeFromList(Request $request, array $params): Response
    {
        // Check if user is logged in
        $redirect = $this->requireLogin();
        if ($redirect) {
            return $redirect;
        }
        
        Logger::info("Received request to remove movie", [
            'params' => $params,
            'user' => SessionService::getCurrentUser() ? SessionService::getCurrentUser()->getId() : 'not logged in',
            'post_data' => $request->request->all(),
            'request_method' => $request->getMethod(),
            'headers' => $request->headers->all()
        ]);
        
        // Check CSRF token
        $token = $request->request->get('csrf_token');
        if (!$this->verifyCsrfToken($token)) {
            Logger::warning("CSRF token validation failed", [
                'provided_token' => $token
            ]);
            return new JsonResponse(['success' => false, 'error' => 'Invalid request: CSRF token validation failed.'], 400);
        }
        
        $user = SessionService::getCurrentUser();
        $tmdbId = (int) ($params['id'] ?? 0);
        
        Logger::info("Attempting to remove movie", [
            'tmdb_id' => $tmdbId,
            'user_id' => $user->getId()
        ]);
        
        if ($tmdbId <= 0) {
            Logger::warning("Invalid movie ID provided", [
                'tmdb_id' => $tmdbId
            ]);
            return new JsonResponse(['success' => false, 'error' => 'Invalid movie ID.'], 400);
        }
        
        // Get movie to remove its cached images
        $stmt = Database::getInstance()->prepare("
            SELECT local_poster_path, local_backdrop_path 
            FROM movies 
            WHERE user_id = ? AND tmdb_id = ?
        ");
        $stmt->execute([$user->getId(), $tmdbId]);
        $movieData = $stmt->fetch();
        
        if ($movieData) {
            // Remove cached images
            ImageCache::removeImage($movieData['local_poster_path']);
            ImageCache::removeImage($movieData['local_backdrop_path']);
        }
        
        $success = Movie::removeFromList($user->getId(), $tmdbId);
        
        return new JsonResponse([
            'success' => $success,
            'error' => $success ? null : 'Failed to remove movie. The movie may have already been removed or there was a database error.',
            'message' => $success ? 'Movie removed from list.' : 'Failed to remove movie.'
        ]);
    }
    
    /**
     * Refresh movie data from TMDb for all movies in collection
     */
    public function refreshMovieData(Request $request): Response
    {
        // Check if user is logged in
        $redirect = $this->requireLogin();
        if ($redirect) {
            return $redirect;
        }
        
        // Check CSRF token
        $token = $request->headers->get('X-CSRF-Token');
        if (!$this->verifyCsrfToken($token)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid request.'], 400);
        }
        
        $user = SessionService::getCurrentUser();
        $userSettings = UserSettings::getByUserId($user->getId());
        
        if (!$userSettings || !$userSettings->hasValidTmdbCredentials()) {
            return new JsonResponse(['success' => false, 'error' => 'Missing TMDb credentials.'], 400);
        }
        
        $tmdbService = new TmdbService($userSettings);

        // Read options from JSON body (if provided)
        $keepLocalPoster = false;
        $contentType = $request->headers->get('Content-Type') ?: '';
        if (stripos($contentType, 'application/json') !== false) {
            $payload = json_decode((string)$request->getContent(), true);
            if (is_array($payload) && isset($payload['keep_local_poster'])) {
                $keepLocalPoster = (bool)$payload['keep_local_poster'];
            }
        }
        
        // Get all movies from user's lists
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM movies WHERE user_id = ?");
        $stmt->execute([$user->getId()]);
        $movies = $stmt->fetchAll();
        
        $updated = 0;
        $failed = 0;
        
        foreach ($movies as $movieData) {
            Logger::info("Refreshing movie data", [
                'tmdb_id' => $movieData['tmdb_id'],
                'title' => $movieData['title']
            ]);
            
            // Get fresh data from TMDb
            $freshData = $tmdbService->getMovie($movieData['tmdb_id']);
            
            if (!$freshData) {
                Logger::error("Failed to fetch fresh data for movie", [
                    'tmdb_id' => $movieData['tmdb_id'],
                    'title' => $movieData['title']
                ]);
                $failed++;
                continue;
            }
            
            // Cache images if they exist
            $localPosterPath = null;
            $localBackdropPath = null;

            // Determine poster strategy respecting user preference to keep custom poster
            if ($keepLocalPoster && !empty($movieData['local_poster_path'])) {
                $localPosterPath = $movieData['local_poster_path'];
            } else {
                if (!empty($freshData['poster_path'])) {
                    $posterUrl = $tmdbService->getImageUrl($freshData['poster_path']);
                    if ($posterUrl) {
                        $localPosterPath = ImageCache::cacheImage($posterUrl, 'poster');
                    }
                }
            }
            
            if (!empty($freshData['backdrop_path'])) {
                $backdropUrl = $tmdbService->getImageUrl($freshData['backdrop_path'], 'original');
                if ($backdropUrl) {
                    $localBackdropPath = ImageCache::cacheImage($backdropUrl, 'backdrop');
                }
            }
            
            // Build dynamic SQL to optionally preserve local_poster_path untouched
            $baseSql = "UPDATE movies
                SET title = ?, poster_path = ?, backdrop_path = ?, overview = ?,
                    release_date = ?, genres = ?, runtime = ?, vote_average = ?,
                    vote_count = ?, production_companies = ?, original_title = ?,
                    tagline = ?, status = ?, production_countries = ?,
                    spoken_languages = ?, budget = ?, revenue = ?, homepage = ?,
                    imdb_id = ?, original_language = ?, popularity = ?,
                    adult = ?, video = ?, ";

            $params = [
                $freshData['title'],
                $freshData['poster_path'] ?? null,
                $freshData['backdrop_path'] ?? null,
                $freshData['overview'] ?? null,
                $freshData['release_date'] ?? null,
                isset($freshData['genres']) ? json_encode($freshData['genres']) : null,
                $freshData['runtime'] ?? null,
                $freshData['vote_average'] ?? null,
                $freshData['vote_count'] ?? null,
                isset($freshData['production_companies']) ? json_encode($freshData['production_companies']) : null,
                $freshData['original_title'] ?? null,
                $freshData['tagline'] ?? null,
                $freshData['status'] ?? null,
                isset($freshData['production_countries']) ? json_encode($freshData['production_countries']) : null,
                isset($freshData['spoken_languages']) ? json_encode($freshData['spoken_languages']) : null,
                $freshData['budget'] ?? null,
                $freshData['revenue'] ?? null,
                $freshData['homepage'] ?? null,
                $freshData['imdb_id'] ?? null,
                $freshData['original_language'] ?? null,
                $freshData['popularity'] ?? null,
                $freshData['adult'] ?? false ? 1 : 0,
                $freshData['video'] ?? false ? 1 : 0,
            ];

            $preserved = false;
            if ($keepLocalPoster && !empty($movieData['local_poster_path'])) {
                // Do not touch local_poster_path field
                $baseSql .= "local_backdrop_path = ?, certifications = ?, keywords = ?,
                    watch_providers = ?, last_updated_at = CURRENT_TIMESTAMP WHERE id = ?";
                $params[] = $localBackdropPath;
                $params[] = $freshData['certifications'] ?? null;
                $params[] = $freshData['keywords'] ?? null;
                $params[] = $freshData['watch_providers'] ?? null;
                $params[] = $movieData['id'];
                $preserved = true;
            } else {
                $baseSql .= "local_poster_path = ?, local_backdrop_path = ?, certifications = ?, keywords = ?,
                    watch_providers = ?, last_updated_at = CURRENT_TIMESTAMP WHERE id = ?";
                $params[] = $localPosterPath;
                $params[] = $localBackdropPath;
                $params[] = $freshData['certifications'] ?? null;
                $params[] = $freshData['keywords'] ?? null;
                $params[] = $freshData['watch_providers'] ?? null;
                $params[] = $movieData['id'];
            }

            $stmt = $db->prepare($baseSql);
            $success = $stmt->execute($params);

            LoggerService::info('Bulk refresh poster handling', [
                'tmdb_id' => $movieData['tmdb_id'],
                'keep_local_poster' => $keepLocalPoster ? 'yes' : 'no',
                'had_custom_before' => !empty($movieData['local_poster_path']) ? 'yes' : 'no',
                'preserved_local' => $preserved ? 'yes' : 'no',
                'new_local_poster_path' => $preserved ? $movieData['local_poster_path'] : ($localPosterPath ?? 'null'),
                'success' => $success ? 'yes' : 'no'
            ]);
            
            if ($success) {
                $updated++;
            } else {
                $failed++;
            }
        }
        
        return new JsonResponse([
            'success' => true,
            'message' => sprintf(
                'Updated %d movies. Failed to update %d movies.',
                $updated,
                $failed
            )
        ]);
    }

    /**
     * Update the formats for a movie in the user's collection
     */
    public function updateFormats(Request $request, array $params): Response
    {
        // Check if user is logged in
        $user = SessionService::getCurrentUser();
        if (!$user) {
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success' => false,
                    'error' => 'You must be logged in to update movie formats'
                ], 401);
            }
            return $this->redirect('/login');
        }
        
        // Get movie ID from params
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid movie ID'
                ], 400);
            }
            return $this->redirect('/collection');
        }
        
        // Validate CSRF token
        $token = $request->request->get('csrf_token');
        if (!$this->verifyCsrfToken($token)) {
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid CSRF token'
                ], 400);
            }
            return $this->redirect('/movies/' . $id);
        }
        
        // Get the movie ID and format IDs from the request
        $formatIds = $request->request->all('formats');
        
        if (!is_array($formatIds)) {
            $formatIds = [];
        }
        
        // Convert all format IDs to integers
        $formatIds = array_map('intval', $formatIds);
        
        // Check if the movie is in the user's collection and is owned
        $currentListType = \App\Models\Movie::getListType($user->getId(), $id);
        if (!$currentListType || $currentListType !== \App\Models\Movie::LIST_COLLECTION) {
            if ($request->isXmlHttpRequest()) {
                return $this->json([
                    'success' => false,
                    'error' => 'Movie is not in your owned collection'
                ], 400);
            }
            return $this->redirect('/movies/' . $id);
        }
        
        // Save the user's format selections
        $success = \App\Models\MovieFormat::saveUserMovieFormats($user->getId(), $id, $formatIds);
        
        LoggerService::info('User updated movie formats', [
            'user_id' => $user->getId(),
            'movie_id' => $id,
            'format_count' => count($formatIds),
            'success' => $success ? 'yes' : 'no'
        ]);
        
        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'success' => $success,
                'message' => $success ? 'Formats updated successfully' : 'Failed to update formats'
            ]);
        }
        
        // Redirect back to the movie page
        return $this->redirect('/movies/' . $id);
    }

    /**
     * Refresh a single movie's data from TMDb, with option to keep user's custom poster
     */
    public function refreshSingleMovie(Request $request, array $params): Response
    {
        // Require login
        $redirect = $this->requireLogin();
        if ($redirect) {
            return $redirect;
        }

        // Validate movie id
        $tmdbId = (int)($params['id'] ?? 0);
        if ($tmdbId <= 0) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Invalid movie ID'], 400);
            }
            return $this->redirect('/collection');
        }

        // CSRF token (form or header)
        $token = $request->request->get('csrf_token') ?: $request->headers->get('X-CSRF-Token');
        if (!$this->verifyCsrfToken($token)) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Invalid CSRF token'], 400);
            }
            return $this->redirect('/movies/' . $tmdbId);
        }

        $user = SessionService::getCurrentUser();

        // Ensure user has TMDb credentials
        $userSettings = UserSettings::getByUserId($user->getId());
        if (!$userSettings || !$userSettings->hasValidTmdbCredentials()) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Missing TMDb credentials'], 400);
            }
            return $this->redirect('/settings');
        }

        // Ensure movie exists for this user
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM movies WHERE user_id = ? AND tmdb_id = ? LIMIT 1");
        $stmt->execute([$user->getId(), $tmdbId]);
        $movieRow = $stmt->fetch();
        if (!$movieRow) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Movie not found in your lists'], 404);
            }
            return $this->redirect('/collection');
        }

        // Respect keep_local_poster flag (form checkbox or JSON)
        $keepLocalPoster = false;
        if ($request->isXmlHttpRequest()) {
            $payload = json_decode((string)$request->getContent(), true);
            if (is_array($payload) && isset($payload['keep_local_poster'])) {
                $keepLocalPoster = (bool)$payload['keep_local_poster'];
            }
        } else {
            $keepLocalPoster = (bool)$request->request->get('keep_local_poster', false);
        }

        $tmdbService = new TmdbService($userSettings);

        try {
            $freshData = $tmdbService->getMovie($tmdbId);
            if (!$freshData) {
                if ($request->isXmlHttpRequest()) {
                    return $this->json(['success' => false, 'error' => 'Failed to fetch data from TMDb'], 502);
                }
                return $this->redirect('/movies/' . $tmdbId);
            }

            // Prepare image caching respecting flag
            $localPosterPath = null;
            if ($keepLocalPoster && !empty($movieRow['local_poster_path'])) {
                $localPosterPath = $movieRow['local_poster_path'];
            } else {
                if (!empty($freshData['poster_path'])) {
                    $posterUrl = $tmdbService->getImageUrl($freshData['poster_path']);
                    if ($posterUrl) {
                        $localPosterPath = ImageCache::cacheImage($posterUrl, 'poster');
                    }
                }
            }

            $localBackdropPath = null;
            if (!empty($freshData['backdrop_path'])) {
                $backdropUrl = $tmdbService->getImageUrl($freshData['backdrop_path'], 'original');
                if ($backdropUrl) {
                    $localBackdropPath = ImageCache::cacheImage($backdropUrl, 'backdrop');
                }
            }

            // Update DB
            $upd = $db->prepare("UPDATE movies
                SET title = ?, poster_path = ?, backdrop_path = ?, overview = ?,
                    release_date = ?, genres = ?, runtime = ?, vote_average = ?,
                    vote_count = ?, production_companies = ?, original_title = ?,
                    tagline = ?, status = ?, production_countries = ?,
                    spoken_languages = ?, budget = ?, revenue = ?, homepage = ?,
                    imdb_id = ?, original_language = ?, popularity = ?,
                    adult = ?, video = ?, local_poster_path = ?,
                    local_backdrop_path = ?, certifications = ?, keywords = ?,
                    watch_providers = ?, last_updated_at = CURRENT_TIMESTAMP
                WHERE id = ?");

            $ok = $upd->execute([
                $freshData['title'],
                $freshData['poster_path'] ?? null,
                $freshData['backdrop_path'] ?? null,
                $freshData['overview'] ?? null,
                $freshData['release_date'] ?? null,
                isset($freshData['genres']) ? json_encode($freshData['genres']) : null,
                $freshData['runtime'] ?? null,
                $freshData['vote_average'] ?? null,
                $freshData['vote_count'] ?? null,
                isset($freshData['production_companies']) ? json_encode($freshData['production_companies']) : null,
                $freshData['original_title'] ?? null,
                $freshData['tagline'] ?? null,
                $freshData['status'] ?? null,
                isset($freshData['production_countries']) ? json_encode($freshData['production_countries']) : null,
                isset($freshData['spoken_languages']) ? json_encode($freshData['spoken_languages']) : null,
                $freshData['budget'] ?? null,
                $freshData['revenue'] ?? null,
                $freshData['homepage'] ?? null,
                $freshData['imdb_id'] ?? null,
                $freshData['original_language'] ?? null,
                $freshData['popularity'] ?? null,
                $freshData['adult'] ?? false ? 1 : 0,
                $freshData['video'] ?? false ? 1 : 0,
                $localPosterPath,
                $localBackdropPath,
                $freshData['certifications'] ?? null,
                $freshData['keywords'] ?? null,
                $freshData['watch_providers'] ?? null,
                $movieRow['id']
            ]);

            LoggerService::info('Single movie refresh completed', [
                'user_id' => $user->getId(),
                'tmdb_id' => $tmdbId,
                'kept_local_poster' => $keepLocalPoster && !empty($movieRow['local_poster_path']) ? 'yes' : 'no',
                'success' => $ok ? 'yes' : 'no'
            ]);

            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => (bool)$ok]);
            }
            return $this->redirect('/movies/' . $tmdbId);
        } catch (\Throwable $e) {
            LoggerService::error('Single movie refresh failed', [
                'tmdb_id' => $tmdbId,
                'error' => $e->getMessage()
            ]);
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Refresh failed'], 500);
            }
            return $this->redirect('/movies/' . $tmdbId);
        }
    }

    /**
     * Mark a movie as watched now (or at a supplied time) and update roll-ups.
     * Accepts optional fields via form or JSON: watched_at, source, format_id, rating, notes, location, runtime_minutes
     */
    public function markWatched(Request $request, array $params): Response
    {
        // Require login
        $redirect = $this->requireLogin();
        if ($redirect) {
            return $redirect;
        }

        $user = SessionService::getCurrentUser();
        $tmdbId = (int)($params['id'] ?? 0);
        // Determine if the client expects JSON
        $wantsJson = $request->isXmlHttpRequest()
            || stripos($request->headers->get('Content-Type') ?? '', 'application/json') !== false
            || stripos($request->headers->get('Accept') ?? '', 'application/json') !== false;

        if ($tmdbId <= 0) {
            return $this->json(['success' => false, 'error' => 'Invalid movie ID'], 400);
        }

        // CSRF token (form or header)
        $token = $request->request->get('csrf_token') ?: $request->headers->get('X-CSRF-Token');
        if (!$this->verifyCsrfToken($token)) {
            if ($wantsJson) {
                return $this->json(['success' => false, 'error' => 'Invalid CSRF token'], 400);
            }
            return $this->redirect('/movies/' . $tmdbId);
        }

        // Ensure the movie exists in any of the user's lists
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id FROM movies WHERE user_id = ? AND tmdb_id = ? LIMIT 1");
        $stmt->execute([$user->getId(), $tmdbId]);
        $movieRow = $stmt->fetch();
        if (!$movieRow) {
            $msg = 'Movie is not in your lists yet. Add it first.';
            if ($wantsJson) {
                return $this->json(['success' => false, 'error' => $msg], 400);
            }
            return $this->redirect('/movies/' . $tmdbId);
        }

        // Parse input (allow JSON or form)
        $meta = [];
        $watchedAt = null;
        if ($request->isXmlHttpRequest() || (stripos($request->headers->get('Content-Type') ?? '', 'application/json') !== false)) {
            $payload = json_decode((string)$request->getContent(), true) ?: [];
            $meta['source'] = $payload['source'] ?? null;
            $meta['format_id'] = isset($payload['format_id']) ? (int)$payload['format_id'] : null;
            $meta['location'] = $payload['location'] ?? null;
            $meta['runtime_minutes'] = isset($payload['runtime_minutes']) ? (int)$payload['runtime_minutes'] : null;
            $meta['rating'] = isset($payload['rating']) ? (int)$payload['rating'] : null;
            $meta['notes'] = $payload['notes'] ?? null;
            if (!empty($payload['watched_at'])) {
                try { $watchedAt = new \DateTimeImmutable($payload['watched_at']); } catch (\Throwable $e) { $watchedAt = null; }
            }
        } else {
            $meta['source'] = $request->request->get('source');
            $meta['format_id'] = $request->request->getInt('format_id') ?: null;
            $meta['location'] = $request->request->get('location');
            $meta['runtime_minutes'] = $request->request->getInt('runtime_minutes') ?: null;
            $meta['rating'] = $request->request->getInt('rating') ?: null;
            $meta['notes'] = $request->request->get('notes');
            $wa = $request->request->get('watched_at');
            if (!empty($wa)) {
                try { $watchedAt = new \DateTimeImmutable((string)$wa); } catch (\Throwable $e) { $watchedAt = null; }
            }
        }

        $ok = WatchedLog::create($user->getId(), $tmdbId, $watchedAt, $meta);

        LoggerService::info('Marked movie as watched', [
            'user_id' => $user->getId(),
            'tmdb_id' => $tmdbId,
            'success' => $ok ? 'yes' : 'no'
        ]);

        // Optional auto-remove from To Watch if user enabled the setting
        $autoRemoved = false;
        try {
            $userSettings = UserSettings::getByUserId($user->getId());
            if ($userSettings && $userSettings->getAutoRemoveToWatchOnWatched()) {
                $currentList = \App\Models\Movie::getListType($user->getId(), $tmdbId);
                if ($currentList === \App\Models\Movie::LIST_TO_WATCH) {
                    $autoRemoved = \App\Models\Movie::removeFromList($user->getId(), $tmdbId);
                    LoggerService::info('Auto-removed movie from To Watch after marking watched', [
                        'user_id' => $user->getId(),
                        'tmdb_id' => $tmdbId,
                        'removed' => $autoRemoved ? 'yes' : 'no'
                    ]);
                }
            }
        } catch (\Throwable $e) {
            LoggerService::warning('Failed to process auto-remove from To Watch', [
                'tmdb_id' => $tmdbId,
                'error' => $e->getMessage()
            ]);
        }

        if ($wantsJson) {
            // Return updated roll-ups (fallback to watched_logs if no movies row exists)
            $stmt = $db->prepare("SELECT watched_count, last_watched_at FROM movies WHERE user_id = ? AND tmdb_id = ?");
            $stmt->execute([$user->getId(), $tmdbId]);
            $roll = $stmt->fetch();
            $watchedCount = 0;
            $lastWatchedAt = null;
            if ($roll) {
                $watchedCount = (int)($roll['watched_count'] ?? 0);
                $lastWatchedAt = $roll['last_watched_at'] ?? null;
            } else {
                try {
                    $agg = $db->prepare("SELECT COUNT(*) AS cnt, MAX(watched_at) AS last_at FROM watched_logs WHERE user_id = ? AND tmdb_id = ?");
                    $agg->execute([$user->getId(), $tmdbId]);
                    $r2 = $agg->fetch() ?: ['cnt' => 0, 'last_at' => null];
                    $watchedCount = (int)($r2['cnt'] ?? 0);
                    $lastWatchedAt = $r2['last_at'] ?? null;
                } catch (\Throwable $e) {
                    // keep defaults
                }
            }
            return $this->json([
                'success' => (bool)$ok,
                'watched_count' => $watchedCount,
                'last_watched_at' => $lastWatchedAt,
                'auto_removed_from_to_watch' => $autoRemoved,
            ], $ok ? 200 : 500);
        }

        return $this->redirect('/movies/' . $tmdbId);
    }

    /**
     * Add a simple entry to the user's watchlog for the given TMDb movie id.
     * This must NOT add or remove the movie from any collection/list.
     * Accepts CSRF token and optional watched_at (defaults to now).
     */
    public function addToWatchlog(Request $request, array $params): Response
    {
        // Require login
        $redirect = $this->requireLogin();
        if ($redirect) {
            return $redirect;
        }

        $user = SessionService::getCurrentUser();
        $tmdbId = (int)($params['id'] ?? 0);
        if ($tmdbId <= 0) {
            return $this->json(['success' => false, 'error' => 'Invalid movie ID'], 400);
        }

        // CSRF token (from form field or header)
        $token = $request->request->get('csrf_token') ?: $request->headers->get('X-CSRF-Token');
        if (!$this->verifyCsrfToken($token)) {
            return $this->json(['success' => false, 'error' => 'Invalid CSRF token'], 400);
        }

        // Optional fields can come via JSON or form
        $meta = [];
        $watchedAt = null;
        $contentType = $request->headers->get('Content-Type') ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $payload = json_decode((string)$request->getContent(), true) ?: [];
            if (!empty($payload['watched_at'])) {
                try { $watchedAt = new \DateTimeImmutable((string)$payload['watched_at']); } catch (\Throwable $e) { $watchedAt = null; }
            }
            $meta['source'] = $payload['source'] ?? null;
            $meta['format_id'] = isset($payload['format_id']) ? (int)$payload['format_id'] : null;
            $meta['location'] = $payload['location'] ?? null;
            $meta['runtime_minutes'] = isset($payload['runtime_minutes']) ? (int)$payload['runtime_minutes'] : null;
            $meta['rating'] = isset($payload['rating']) ? (int)$payload['rating'] : null;
            $meta['notes'] = $payload['notes'] ?? null;
        } else {
            $wa = $request->request->get('watched_at');
            if (!empty($wa)) {
                try { $watchedAt = new \DateTimeImmutable((string)$wa); } catch (\Throwable $e) { $watchedAt = null; }
            }
            $meta['source'] = $request->request->get('source');
            $meta['format_id'] = $request->request->getInt('format_id') ?: null;
            $meta['location'] = $request->request->get('location');
            $meta['runtime_minutes'] = $request->request->getInt('runtime_minutes') ?: null;
            $meta['rating'] = $request->request->getInt('rating') ?: null;
            $meta['notes'] = $request->request->get('notes');
        }

        // Create a watchlog entry only. Do NOT add to any list/collection.
        $ok = WatchedLog::create($user->getId(), $tmdbId, $watchedAt, $meta);

        LoggerService::info('Add to Watchlog', [
            'user_id' => $user->getId(),
            'tmdb_id' => $tmdbId,
            'success' => $ok ? 'yes' : 'no'
        ]);

        // Return updated roll-ups based on watched_logs aggregates (works even when no movies row exists)
        $db = Database::getInstance();
        $agg = ['cnt' => 0, 'last_at' => null];
        try {
            $stmt = $db->prepare("SELECT COUNT(*) AS cnt, MAX(watched_at) AS last_at FROM watched_logs WHERE user_id = ? AND tmdb_id = ?");
            $stmt->execute([$user->getId(), $tmdbId]);
            $agg = $stmt->fetch() ?: $agg;
        } catch (\Throwable $e) {
            // ignore
        }

        return $this->json([
            'success' => (bool)$ok,
            'watched_count' => (int)($agg['cnt'] ?? 0),
            'last_watched_at' => $agg['last_at'] ?? null,
        ]);
    }

    /**
     * Return paginated watched log entries for a movie (JSON only).
     */
    public function getWatchedLog(Request $request, array $params): Response
    {
        $redirect = $this->requireLogin();
        if ($redirect) {
            return $redirect;
        }
        $user = SessionService::getCurrentUser();
        $tmdbId = (int)($params['id'] ?? 0);
        if ($tmdbId <= 0) {
            return $this->json(['success' => false, 'error' => 'Invalid movie ID'], 400);
        }

        $page = max(1, (int)($request->query->get('page') ?: 1));
        $limit = min(50, max(1, (int)($request->query->get('limit') ?: 20)));
        $offset = ($page - 1) * $limit;

        $items = WatchedLog::getByMovie($user->getId(), $tmdbId, $limit, $offset);

        return $this->json([
            'success' => true,
            'page' => $page,
            'limit' => $limit,
            'count' => count($items),
            'items' => $items,
        ]);
    }

    /**
     * Delete a watched log entry (JSON only). Recomputes roll-ups on success.
     */
    public function deleteWatchedLog(Request $request, array $params): Response
    {
        $redirect = $this->requireLogin();
        if ($redirect) {
            return $redirect;
        }
        $user = SessionService::getCurrentUser();

        // CSRF required via header for fetch requests
        $token = $request->headers->get('X-CSRF-Token') ?: $request->request->get('csrf_token');
        if (!$this->verifyCsrfToken($token)) {
            return $this->json(['success' => false, 'error' => 'Invalid CSRF token'], 400);
        }

        $logId = (int)($params['id'] ?? 0);
        if ($logId <= 0) {
            return $this->json(['success' => false, 'error' => 'Invalid log ID'], 400);
        }

        // Read the log to know which movie to recompute and to authorize
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT tmdb_id FROM watched_logs WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$logId, $user->getId()]);
        $row = $stmt->fetch();
        if (!$row) {
            return $this->json(['success' => false, 'error' => 'Log not found'], 404);
        }
        $tmdbId = (int)$row['tmdb_id'];

        $ok = WatchedLog::delete($user->getId(), $logId);
        if (!$ok) {
            return $this->json(['success' => false, 'error' => 'Failed to delete log'], 500);
        }

        // Return updated roll-ups (fallback to watched_logs if no movies row exists)
        $r = $db->prepare("SELECT watched_count, last_watched_at FROM movies WHERE user_id = ? AND tmdb_id = ? LIMIT 1");
        $r->execute([$user->getId(), $tmdbId]);
        $roll = $r->fetch();
        $watchedCount = 0;
        $lastWatchedAt = null;

        if ($roll) {
            $watchedCount = (int)($roll['watched_count'] ?? 0);
            $lastWatchedAt = $roll['last_watched_at'] ?? null;
        } else {
            try {
                $agg = $db->prepare("SELECT COUNT(*) AS cnt, MAX(watched_at) AS last_at FROM watched_logs WHERE user_id = ? AND tmdb_id = ?");
                $agg->execute([$user->getId(), $tmdbId]);
                $row = $agg->fetch() ?: ['cnt' => 0, 'last_at' => null];
                $watchedCount = (int)($row['cnt'] ?? 0);
                $lastWatchedAt = $row['last_at'] ?? null;
            } catch (\Throwable $e) {
                // keep defaults
            }
        }

        return $this->json([
            'success' => true,
            'tmdb_id' => $tmdbId,
            'watched_count' => $watchedCount,
            'last_watched_at' => $lastWatchedAt,
        ]);
    }

    /**
     * Edit/Update a watched log entry (JSON only). Returns updated roll-ups on success.
     * Accepts fields: watched_at, source, format_id, rating (1–5), notes, location, runtime_minutes (>=0)
     */
    public function updateWatchedLog(Request $request, array $params): Response
    {
        // Require login
        $redirect = $this->requireLogin();
        if ($redirect) {
            return $redirect;
        }

        // Enforce JSON and CSRF
        $token = $request->headers->get('X-CSRF-Token') ?: $request->request->get('csrf_token');
        if (!$this->verifyCsrfToken($token)) {
            return $this->json(['success' => false, 'error' => 'Invalid CSRF token'], 400);
        }

        $user = SessionService::getCurrentUser();
        $logId = (int)($params['id'] ?? 0);
        if ($logId <= 0) {
            return $this->json(['success' => false, 'error' => 'Invalid log ID'], 400);
        }

        // Parse payload (support JSON or form-encoded)
        $fields = [];
        $contentType = $request->headers->get('Content-Type') ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $payload = json_decode((string)$request->getContent(), true) ?: [];
            $fields = is_array($payload) ? $payload : [];
        } else {
            $fields['watched_at'] = $request->request->get('watched_at');
            $fields['source'] = $request->request->get('source');
            $fields['format_id'] = $request->request->get('format_id');
            $fields['rating'] = $request->request->get('rating');
            $fields['notes'] = $request->request->get('notes');
            $fields['location'] = $request->request->get('location');
            $fields['runtime_minutes'] = $request->request->get('runtime_minutes');
        }

        // Coerce and validate specific fields
        if (isset($fields['rating']) && $fields['rating'] !== null && $fields['rating'] !== '') {
            $r = (int)$fields['rating'];
            if ($r < 1 || $r > 5) {
                return $this->json(['success' => false, 'error' => 'Rating must be between 1 and 5'], 400);
            }
            $fields['rating'] = $r;
        } else {
            // allow clearing
            $fields['rating'] = null;
        }

        if (isset($fields['runtime_minutes']) && $fields['runtime_minutes'] !== null && $fields['runtime_minutes'] !== '') {
            $m = (int)$fields['runtime_minutes'];
            if ($m < 0) {
                return $this->json(['success' => false, 'error' => 'Runtime minutes cannot be negative'], 400);
            }
            $fields['runtime_minutes'] = $m;
        } else {
            $fields['runtime_minutes'] = null;
        }

        if (isset($fields['format_id']) && $fields['format_id'] !== null && $fields['format_id'] !== '') {
            $fields['format_id'] = (int)$fields['format_id'];
        } else {
            $fields['format_id'] = null;
        }

        // watched_at validation happens in model (DateTime parse). Optionally pre-validate format
        if (isset($fields['watched_at']) && $fields['watched_at']) {
            try { new \DateTimeImmutable((string)$fields['watched_at']); } catch (\Throwable $e) {
                return $this->json(['success' => false, 'error' => 'Invalid date/time provided'], 400);
            }
        }

        // Update via model (also enforces ownership)
        $result = WatchedLog::update($user->getId(), $logId, $fields);
        if (!$result['ok']) {
            return $this->json(['success' => false, 'error' => 'Failed to update viewing entry'], 500);
        }

        $tmdbId = (int)($result['tmdb_id'] ?? 0);
        $db = Database::getInstance();
        $r = $db->prepare("SELECT watched_count, last_watched_at FROM movies WHERE user_id = ? AND tmdb_id = ? LIMIT 1");
        $r->execute([$user->getId(), $tmdbId]);
        $roll = $r->fetch();

        $watchedCount = 0;
        $lastWatchedAt = null;
        if ($roll) {
            $watchedCount = (int)($roll['watched_count'] ?? 0);
            $lastWatchedAt = $roll['last_watched_at'] ?? null;
        } else {
            // Fallback to aggregates from watched_logs when movie isn't in any list
            try {
                $agg = $db->prepare("SELECT COUNT(*) AS cnt, MAX(watched_at) AS last_at FROM watched_logs WHERE user_id = ? AND tmdb_id = ?");
                $agg->execute([$user->getId(), $tmdbId]);
                $row = $agg->fetch() ?: ['cnt' => 0, 'last_at' => null];
                $watchedCount = (int)($row['cnt'] ?? 0);
                $lastWatchedAt = $row['last_at'] ?? null;
            } catch (\Throwable $e) {
                // keep defaults
            }
        }

        return $this->json([
            'success' => true,
            'tmdb_id' => $tmdbId,
            'watched_count' => $watchedCount,
            'last_watched_at' => $lastWatchedAt,
        ]);
    }

    /**
     * Upload/replace a custom poster image for a movie in the user's Owned Media
     */
    public function uploadPoster(Request $request, array $params): Response
    {
        // Require login
        $user = SessionService::getCurrentUser();
        if (!$user) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'You must be logged in'], 401);
            }
            return $this->redirect('/login');
        }

        $tmdbId = (int)($params['id'] ?? 0);
        if ($tmdbId <= 0) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Invalid movie ID'], 400);
            }
            return $this->redirect('/collection');
        }

        // CSRF
        $token = $request->request->get('csrf_token');
        if (!$this->verifyCsrfToken($token)) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Invalid CSRF token'], 400);
            }
            return $this->redirect('/movies/' . $tmdbId);
        }

        // Verify movie is in user's Owned Media
        $listType = Movie::getListType($user->getId(), $tmdbId);
        if ($listType !== Movie::LIST_COLLECTION) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Movie must be in your Owned Media to set a custom poster'], 400);
            }
            return $this->redirect('/movies/' . $tmdbId);
        }

        // Validate uploaded file
        $file = $request->files->get('poster');
        if (!$file) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'No file uploaded'], 400);
            }
            return $this->redirect('/movies/' . $tmdbId);
        }

        // Allowed mime types
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

        // Safely detect MIME type without requiring symfony/mime
        $mime = null;
        try {
            // This may throw if symfony/mime isn't installed
            $mime = $file->getMimeType();
        } catch (\Throwable $e) {
            // ignore and try other methods
            \App\Services\LoggerService::warning('Uploaded file getMimeType failed, falling back to finfo/magic bytes', [
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback to finfo
        if (!$mime && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $file->getPathname()) ?: null;
                finfo_close($finfo);
            }
        }

        // Fallback to simple magic byte checks
        if (!$mime) {
            $fh = @fopen($file->getPathname(), 'rb');
            if ($fh) {
                $sig = fread($fh, 12);
                fclose($fh);
                if ($sig !== false) {
                    if (strncmp($sig, "\xFF\xD8\xFF", 3) === 0) {
                        $mime = 'image/jpeg';
                    } elseif (strncmp($sig, "\x89PNG\x0D\x0A\x1A\x0A", 8) === 0) {
                        $mime = 'image/png';
                    } elseif (strncmp($sig, 'RIFF', 4) === 0 && substr($sig, 8, 4) === 'WEBP') {
                        $mime = 'image/webp';
                    }
                }
            }
        }

        $ext = $mime && isset($allowed[$mime]) ? $allowed[$mime] : null;
        if (!$ext) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Unsupported image type. Allowed: JPG, PNG, WEBP'], 400);
            }
            return $this->redirect('/movies/' . $tmdbId);
        }

        // Limit size to 5 MB
        $maxBytes = 5_242_880;
        if ($file->getSize() > $maxBytes) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'File too large (max 5 MB)'], 400);
            }
            return $this->redirect('/movies/' . $tmdbId);
        }

        // Ensure destination directory exists
        $destDir = __DIR__ . '/../../public/uploads/posters';
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Server error: cannot create upload directory'], 500);
            }
            return $this->redirect('/movies/' . $tmdbId);
        }

        // Generate unique safe filename
        $safeBase = sprintf('u%d_m%d_%d_%s', $user->getId(), $tmdbId, time(), bin2hex(random_bytes(4)));
        $filename = $safeBase . '.' . $ext;
        $fullPath = rtrim($destDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

        try {
            // Remove previous local poster if any
            $stmt = Database::getInstance()->prepare("SELECT local_poster_path FROM movies WHERE user_id = ? AND tmdb_id = ? LIMIT 1");
            $stmt->execute([$user->getId(), $tmdbId]);
            $row = $stmt->fetch();
            if ($row && !empty($row['local_poster_path'])) {
                ImageCache::removeImage($row['local_poster_path']);
            }

            // Move file
            $file->move($destDir, $filename);
            @chmod($fullPath, 0644);

            $webPath = '/uploads/posters/' . $filename;

            // Update DB
            $upd = Database::getInstance()->prepare("UPDATE movies SET local_poster_path = ?, last_updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND tmdb_id = ?");
            $ok = $upd->execute([$webPath, $user->getId(), $tmdbId]);

            LoggerService::info('User uploaded custom poster', [
                'user_id' => $user->getId(),
                'tmdb_id' => $tmdbId,
                'path' => $webPath,
                'success' => $ok ? 'yes' : 'no'
            ]);

            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => (bool)$ok, 'poster_url' => $webPath]);
            }
            return $this->redirect('/movies/' . $tmdbId);
        } catch (\Throwable $e) {
            LoggerService::error('Poster upload failed', [
                'message' => $e->getMessage(),
                'tmdb_id' => $tmdbId,
            ]);
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'error' => 'Upload failed'], 500);
            }
            return $this->redirect('/movies/' . $tmdbId);
        }
    }
}