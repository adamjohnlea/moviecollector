<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\SessionService;
use App\Services\TmdbService;
use App\Models\UserSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\ImageCache;
use App\Services\LoggerService as Logger;

class HomeController extends Controller
{
    /**
     * Home page action
     */
    public function index(Request $request): Response
    {
        // Check if user is logged in
        $user = SessionService::getCurrentUser();
        
        // Get popular movies if the user has TMDb credentials
        $popularMovies = [];
        $recentlyWatched = [];
        if ($user) {
            $userSettings = UserSettings::getByUserId($user->getId());
            
            if ($userSettings && $userSettings->hasValidTmdbCredentials()) {
                // Get popular movies from TMDb with short-lived persistent cache
                $tmdbService = new TmdbService($userSettings);
                $response = $tmdbService->getPopularMoviesCached();
                
                if ($response && isset($response['results'])) {
                    $popularMovies = $response['results'];
                    
                    // Add full image URLs (no blocking server-side caching on homepage)
                    foreach ($popularMovies as &$movie) {
                        Logger::info("Processing popular movie", [
                            'title' => $movie['title'],
                            'poster_path' => $movie['poster_path'] ?? null
                        ]);
                        
                        if (isset($movie['poster_path'])) {
                            // Use a smaller size for faster first paint (w342)
                            $posterUrl = $tmdbService->getImageUrl($movie['poster_path'], 'w342');
                            if ($posterUrl) {
                                $movie['poster_url'] = $posterUrl;
                            }
                        }
                    }
                }

                // Recently watched widget (last 10 for current user)
                try {
                    $logs = \App\Models\WatchedLog::getRecentByUser($user->getId(), 10);
                    foreach ($logs as $log) {
                        $tmdbId = (int)$log['tmdb_id'];
                        // Load user's movie row if it exists (title/local poster)
                        $stmt = \App\Database\Database::getInstance()->prepare("SELECT * FROM movies WHERE user_id = ? AND tmdb_id = ? LIMIT 1");
                        $stmt->execute([$user->getId(), $tmdbId]);
                        $row = $stmt->fetch() ?: [];

                        $title = $row['title'] ?? null;
                        $posterUrl = null;

                        // Prefer local poster if present
                        if (!empty($row['local_poster_path'])) {
                            $version = isset($row['last_updated_at']) ? rawurlencode((string)$row['last_updated_at']) : '';
                            $posterUrl = $row['local_poster_path'] . ($version ? ('?v=' . $version) : '');
                        } elseif (!empty($row['poster_path'])) {
                            $posterUrl = $tmdbService->getImageUrl($row['poster_path'], 'w342');
                        }

                        // Fallback for items added directly to watchlog (no movie row yet) or missing data
                        if ((!$title || !$posterUrl)) {
                            try {
                                $apiData = $tmdbService->getCompleteMovieDetails($tmdbId);
                                if (!$title && isset($apiData['title'])) {
                                    $title = $apiData['title'];
                                }
                                if (!$posterUrl && !empty($apiData['poster_path'])) {
                                    $posterUrl = $tmdbService->getImageUrl($apiData['poster_path'], 'w342');
                                }
                            } catch (\Throwable $e) {
                                Logger::warning('Failed TMDb fallback for Recently Watched item', [
                                    'tmdb_id' => $tmdbId,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }

                        $recentlyWatched[] = [
                            'tmdb_id' => $tmdbId,
                            'watched_at' => $log['watched_at'],
                            'title' => $title ?: 'Untitled',
                            'poster_url' => $posterUrl,
                        ];
                    }
                } catch (\Throwable $e) {
                    Logger::error('Recently watched widget failed', ['error' => $e->getMessage()]);
                }
            }
        }
        
        // Render the template
        return $this->renderResponse('home.twig', [
            'user' => $user,
            'popular_movies' => $popularMovies,
            'recently_watched' => $recentlyWatched,
        ]);
    }
}