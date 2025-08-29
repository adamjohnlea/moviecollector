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
            
            Logger::info("[Movie Search] About to call searchMovies with query: " . $query);
            $searchResults = $tmdbService->searchMovies($query);
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
        
        // For owned movies, load formats the user has selected
        $userFormats = [];
        $allFormats = [];
        if ($isOwned) {
            // Check if the MovieFormat class exists
            if (class_exists('\\App\\Models\\MovieFormat')) {
                try {
                    // Load all available formats grouped by category
                    $allFormats = \App\Models\MovieFormat::getFormatsByCategory();
                    
                    // Load user's selected formats for this movie
                    $userFormats = \App\Models\MovieFormat::getFormatsForUserMovie($user->getId(), $tmdbId);
                } catch (\Exception $e) {
                    LoggerService::error('Error loading movie formats', [
                        'message' => $e->getMessage(),
                        'movie_id' => $tmdbId
                    ]);
                }
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
                        'external_ids' => $cachedMovie->getExternalIds()
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
            
            if (!empty($freshData['poster_path'])) {
                $posterUrl = $tmdbService->getImageUrl($freshData['poster_path']);
                if ($posterUrl) {
                    $localPosterPath = ImageCache::cacheImage($posterUrl, 'poster');
                }
            }
            
            if (!empty($freshData['backdrop_path'])) {
                $backdropUrl = $tmdbService->getImageUrl($freshData['backdrop_path'], 'original');
                if ($backdropUrl) {
                    $localBackdropPath = ImageCache::cacheImage($backdropUrl, 'backdrop');
                }
            }
            
            // Update the movie in the database with fresh data
            $stmt = $db->prepare("
                UPDATE movies
                SET title = ?, poster_path = ?, backdrop_path = ?, overview = ?,
                    release_date = ?, genres = ?, runtime = ?, vote_average = ?,
                    vote_count = ?, production_companies = ?, original_title = ?,
                    tagline = ?, status = ?, production_countries = ?,
                    spoken_languages = ?, budget = ?, revenue = ?, homepage = ?,
                    imdb_id = ?, original_language = ?, popularity = ?,
                    adult = ?, video = ?, local_poster_path = ?,
                    local_backdrop_path = ?, certifications = ?, keywords = ?,
                    watch_providers = ?, last_updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            $success = $stmt->execute([
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
                $movieData['id']
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
} 