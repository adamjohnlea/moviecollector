<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller as BaseController;
use App\Services\LoggerService;
use App\Services\SessionService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use App\Core\TwigExtension;

abstract class Controller extends BaseController
{
    public function __construct()
    {
        // Set up Twig
        $loader = new FilesystemLoader(__DIR__ . '/../../templates');
        $twig = new Environment($loader, [
            'cache' => false, // For development, set to a path for production
            'debug' => true,
            'strict_variables' => true, // Re-enable strict variables for better code quality
        ]);
        
        // Add CSRF token to all templates
        $twig->addGlobal('csrf_token', SessionService::getCsrfToken());
        $twig->addGlobal('current_user', SessionService::getCurrentUser());
        
        // Register custom Twig extensions
        $twig->addExtension(new TwigExtension());
        
        // Call parent constructor with Twig environment
        parent::__construct($twig);
    }
    
    /**
     * Render a template with the given parameters
     * Returns content string, compatible with parent class
     */
    protected function render(string $template, array $parameters = []): string
    {
        // Add current route to parameters
        $parameters['current_route'] = $_SERVER['REQUEST_URI'] ?? '/';
        
        return parent::render($template, $parameters);
    }
    
    /**
     * Create a Response object from rendered template
     */
    protected function renderResponse(string $template, array $parameters = []): Response
    {
        $content = $this->render($template, $parameters);
        return new Response($content);
    }
    
    /**
     * Redirect to a route
     */
    protected function redirect(string $path): RedirectResponse
    {
        // Skip parent implementation as we're completely overriding it
        return new RedirectResponse($path);
    }
    
    /**
     * Check if the user is logged in, redirect if not
     */
    protected function requireLogin(): ?RedirectResponse
    {
        if (!SessionService::isLoggedIn()) {
            LoggerService::warning('Unauthorized access attempt', [
                'path' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            return $this->redirect('/login');
        }
        
        return null;
    }
    
    /**
     * Verify CSRF token
     */
    protected function verifyCsrfToken(?string $token): bool
    {
        if ($token === null) {
            LoggerService::warning('Null CSRF token provided', [
                'path' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            return false;
        }
        
        $isValid = SessionService::verifyCsrfToken($token);
        
        if (!$isValid) {
            LoggerService::warning('Invalid CSRF token', [
                'path' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        }
        
        return $isValid;
    }
    
    /**
     * Create a JSON response
     */
    protected function json(array $data, int $status = 200, array $headers = []): \Symfony\Component\HttpFoundation\JsonResponse
    {
        return new \Symfony\Component\HttpFoundation\JsonResponse($data, $status, $headers);
    }
} 