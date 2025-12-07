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
            }
        }
        
        // Render the template
        return $this->renderResponse('home.twig', [
            'user' => $user,
            'popular_movies' => $popularMovies,
        ]);
    }
} 