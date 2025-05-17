<?php
declare(strict_types=1);

namespace App\Core;

use App\Services\LoggerService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

abstract class Controller
{
    protected Environment $twig;
    
    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }
    
    /**
     * Render a template with the given parameters
     * The actual Response creation is handled by derived classes
     */
    protected function render(string $template, array $parameters = []): string
    {
        try {
            return $this->twig->render($template, $parameters);
        } catch (\Throwable $exception) {
            // Log the template rendering error
            LoggerService::exception($exception, "Error rendering template: {$template}");
            throw $exception;
        }
    }
    
    /**
     * Redirect to the given URL
     * The actual RedirectResponse creation is handled by derived classes
     */
    protected function redirect(string $url): ?RedirectResponse
    {
        return null; // Actual implementation in derived class
    }
    
    /**
     * Log an action performed by a controller
     */
    protected function logAction(string $action, array $context = []): void
    {
        $controllerName = get_class($this);
        LoggerService::info("Controller action: {$controllerName}::{$action}", $context);
    }
    
    /**
     * Log and handle an error in the controller
     */
    protected function logError(string $message, array $context = []): void
    {
        $controllerName = get_class($this);
        $context['controller'] = $controllerName;
        
        LoggerService::error($message, $context);
    }
} 