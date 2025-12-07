<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserSettings;
use App\Services\SessionService;
use App\Services\LoggerService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

class UserController extends Controller
{
    /**
     * Movie detail sections configuration
     */
    private function getMovieDetailSections(): array
    {
        return [
            'overview' => [
                'title' => 'Overview & Details',
                'description' => 'Plot summary and basic information'
            ],
            'cast_crew' => [
                'title' => 'Cast & Crew',
                'description' => 'Actors, directors, and other crew members'
            ],
            'videos' => [
                'title' => 'Trailers & Videos',
                'description' => 'Trailers, teasers, and other video content'
            ],
            'images' => [
                'title' => 'Gallery',
                'description' => 'Movie posters and still images'
            ],
            'similar' => [
                'title' => 'Similar Movies',
                'description' => 'Movies you might also enjoy'
            ],
            'recommendations' => [
                'title' => 'Recommended Movies',
                'description' => 'Recommended based on this movie'
            ],
            'reviews' => [
                'title' => 'Reviews',
                'description' => 'User reviews and ratings'
            ],
            'more_info' => [
                'title' => 'Additional Information',
                'description' => 'Budget, revenue, status, and more'
            ],
            'watch' => [
                'title' => 'Where to Watch',
                'description' => 'Streaming and purchase options'
            ],
            'externalIds' => [
                'title' => 'External Links',
                'description' => 'Links to IMDb, social media, and other external sites'
            ]
        ];
    }
    
    /**
     * Show settings page
     */
    public function showSettings(Request $request): Response
    {
        // Check if user is logged in
        $redirect = $this->requireLogin();
        if ($redirect) {
            return $redirect;
        }
        
        $user = SessionService::getCurrentUser();
        $userSettings = UserSettings::getByUserId($user->getId());
        // Build a reasonable list of timezones (COMMON to avoid an extremely long list)
        $tzList = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL);
        sort($tzList);
        
        return $this->renderResponse('user/settings.twig', [
            'user' => $user,
            'user_settings' => $userSettings,
            'error' => null,
            'success' => null,
            'sections' => $this->getMovieDetailSections(),
            'timezones' => $tzList,
            'active_timezone' => $userSettings ? $userSettings->getTimezone() : 'UTC'
        ]);
    }
    
    /**
     * Update user settings
     */
    public function updateSettings(Request $request): Response
    {
        // Check if user is logged in
        $redirect = $this->requireLogin();
        if ($redirect) {
            return $redirect;
        }
        
        // Check CSRF token
        $token = $request->request->get('csrf_token');
        if (!$this->verifyCsrfToken($token)) {
            $user = SessionService::getCurrentUser();
            $userSettings = $user ? UserSettings::getByUserId($user->getId()) : null;
            $tzList = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL);
            sort($tzList);

            return $this->renderResponse('user/settings.twig', [
                'user' => $user,
                'user_settings' => $userSettings,
                'error' => 'Invalid request, please try again.',
                'success' => null,
                'sections' => $this->getMovieDetailSections(),
                'timezones' => $tzList,
                'active_timezone' => $userSettings ? $userSettings->getTimezone() : 'UTC',
                'active_tab' => 'settings'
            ]);
        }
        
        $user = SessionService::getCurrentUser();
        $tmdbApiKey = $request->request->get('tmdb_api_key');
        $tmdbAccessToken = $request->request->get('tmdb_access_token');
        $timezone = $request->request->get('timezone');
        $autoRemove = $request->request->has('auto_remove_to_watch');
        
        // Update settings
        $updated = true;
        
        if ($tmdbApiKey !== null) {
            $updated = UserSettings::updateTmdbApiKey($user->getId(), $tmdbApiKey) && $updated;
        }
        
        if ($tmdbAccessToken !== null) {
            $updated = UserSettings::updateTmdbAccessToken($user->getId(), $tmdbAccessToken) && $updated;
        }
        
        if ($timezone !== null) {
            $updated = UserSettings::updateTimezone($user->getId(), (string)$timezone) && $updated;
        }

        // Update auto-remove setting
        $updated = UserSettings::updateAutoRemoveToWatchOnWatched($user->getId(), (bool)$autoRemove) && $updated;
        
        // Get updated settings
        $userSettings = UserSettings::getByUserId($user->getId());
        $tzList = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL);
        sort($tzList);
        
        return $this->renderResponse('user/settings.twig', [
            'user' => $user,
            'user_settings' => $userSettings,
            'success' => $updated ? 'Settings updated successfully.' : null,
            'error' => !$updated ? 'There was a problem updating your settings.' : null,
            'sections' => $this->getMovieDetailSections(),
            'timezones' => $tzList,
            'active_timezone' => $userSettings ? $userSettings->getTimezone() : 'UTC'
        ]);
    }
    
    /**
     * Update display preferences
     */
    public function updateDisplayPreferences(Request $request): Response
    {
        // Check if user is logged in
        $redirect = $this->requireLogin();
        if ($redirect) {
            return $redirect;
        }
        
        // Check CSRF token
        $token = $request->request->get('csrf_token');
        if (!$this->verifyCsrfToken($token)) {
            $user = SessionService::getCurrentUser();
            $userSettings = $user ? UserSettings::getByUserId($user->getId()) : null;
            $tzList = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL);
            sort($tzList);

            return $this->renderResponse('user/settings.twig', [
                'user' => $user,
                'user_settings' => $userSettings,
                'error' => 'Invalid request, please try again.',
                'success' => null,
                'sections' => $this->getMovieDetailSections(),
                'timezones' => $tzList,
                'active_timezone' => $userSettings ? $userSettings->getTimezone() : 'UTC',
                'active_tab' => 'display_preferences'
            ]);
        }
        
        $user = SessionService::getCurrentUser();
        $preferences = [];
        
        // Get valid sections
        $validSections = array_keys($this->getMovieDetailSections());
        
        // Process each section checkbox
        foreach ($validSections as $section) {
            $preferences[$section] = $request->request->has('show_' . $section);
        }
        
        LoggerService::info('Updating display preferences', [
            'user_id' => $user->getId(),
            'preferences' => $preferences
        ]);
        
        // Update preferences
        $updated = UserSettings::updateDisplayPreferences($user->getId(), $preferences);
        
        // Get updated settings
        $userSettings = UserSettings::getByUserId($user->getId());
        // Build timezone list for template (strict_variables requires it)
        $tzList = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL);
        sort($tzList);
        
        return $this->renderResponse('user/settings.twig', [
            'user' => $user,
            'user_settings' => $userSettings,
            'success' => $updated ? 'Display preferences updated successfully.' : null,
            'error' => !$updated ? 'There was a problem updating your display preferences.' : null,
            'sections' => $this->getMovieDetailSections(),
            'timezones' => $tzList,
            'active_timezone' => $userSettings ? $userSettings->getTimezone() : 'UTC',
            'active_tab' => 'display_preferences'
        ]);
    }
}