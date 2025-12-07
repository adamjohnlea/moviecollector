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
                        // Load user's movie row to get title and local poster if present
                        $stmt = \App\Database\Database::getInstance()->prepare("SELECT * FROM movies WHERE user_id = ? AND tmdb_id = ? LIMIT 1");
                        $stmt->execute([$user->getId(), (int)$log['tmdb_id']]);
                        $row = $stmt->fetch();
                        if (!$row) { continue; }
                        $item = [
                            'tmdb_id' => (int)$log['tmdb_id'],
                            'watched_at' => $log['watched_at'],
                            'title' => $row['title'] ?? 'Untitled',
                        ];
                        // Determine poster URL: prefer local_poster_path, else TMDb URL
                        if (!empty($row['local_poster_path'])) {
                            // Cache-bust with last_updated_at
                            $version = isset($row['last_updated_at']) ? rawurlencode((string)$row['last_updated_at']) : '';
                            $item['poster_url'] = $row['local_poster_path'] . ($version ? ('?v=' . $version) : '');
                        } elseif (!empty($row['poster_path'])) {
                            $item['poster_url'] = $tmdbService->getImageUrl($row['poster_path'], 'w342');
                        } else {
                            $item['poster_url'] = null;
                        }
                        $recentlyWatched[] = $item;
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