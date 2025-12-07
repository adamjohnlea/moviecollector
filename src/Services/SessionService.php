<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\User;

class SessionService
{
    private const SESSION_USER_ID = 'user_id';
    
    /**
     * Start the session if not already started
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_secure' => true,
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
                'use_strict_mode' => true,
            ]);
        }
    }
    
    /**
     * Set the user as logged in
     */
    public static function login(User $user): void
    {
        self::start();
        $_SESSION[self::SESSION_USER_ID] = $user->getId();
        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);
    }
    
    /**
     * Log the user out
     */
    public static function logout(): void
    {
        self::start();
        
        // Unset all session variables
        $_SESSION = [];
        
        // Delete the session cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?? null,
                ]
            );
        }
        
        // Destroy the session
        session_destroy();
    }
    
    /**
     * Check if a user is logged in
     */
    public static function isLoggedIn(): bool
    {
        self::start();
        if (!isset($_SESSION[self::SESSION_USER_ID])) {
            return false;
        }

        // Ensure the user actually exists (guards against stale session IDs after DB reset)
        $userId = (int) $_SESSION[self::SESSION_USER_ID];
        return User::findById($userId) !== null;
    }
    
    /**
     * Get the current logged-in user
     */
    public static function getCurrentUser(): ?User
    {
        if (!self::isLoggedIn()) {
            return null;
        }
        
        $userId = (int) $_SESSION[self::SESSION_USER_ID];
        return User::findById($userId);
    }
    
    /**
     * Get a CSRF token for the current session
     */
    public static function getCsrfToken(): string
    {
        self::start();
        
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Verify a CSRF token
     */
    public static function verifyCsrfToken(?string $token): bool
    {
        if ($token === null) {
            return false;
        }
        
        self::start();
        
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }
} 