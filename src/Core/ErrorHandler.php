<?php
declare(strict_types=1);

namespace App\Core;

use App\Services\LoggerService;

/**
 * Application error handler 
 * Handles all errors and exceptions using our custom logging system
 */
class ErrorHandler
{
    /**
     * Register error and exception handlers
     */
    public static function register(): void
    {
        // Initialize logger
        LoggerService::init();
        
        // Set error handler
        set_error_handler([self::class, 'handleError']);
        
        // Set exception handler
        set_exception_handler([self::class, 'handleException']);
        
        // Register shutdown function to catch fatal errors
        register_shutdown_function([self::class, 'handleShutdown']);
    }
    
    /**
     * Handle PHP errors
     */
    public static function handleError(int $code, string $message, string $file, int $line): bool
    {
        // Don't handle errors that are suppressed with @
        if (!(error_reporting() & $code)) {
            return false;
        }
        
        $context = [
            'code' => $code,
            'file' => $file,
            'line' => $line
        ];
        
        // Log error using our custom logger
        LoggerService::error("PHP Error: {$message}", $context);
        
        // Let PHP handle the error normally
        return false;
    }
    
    /**
     * Handle uncaught exceptions
     */
    public static function handleException(\Throwable $exception): void
    {
        // Log exception using our custom logger
        LoggerService::exception($exception);
        
        // Display an error page in production
        if (getenv('APP_ENV') === 'production') {
            http_response_code(500);
            require __DIR__ . '/../../templates/error.twig';
            exit;
        }
        
        // In development, let the exception bubble up for better debugging
    }
    
    /**
     * Handle shutdown and catch fatal errors
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        
        // Check if a fatal error occurred
        if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            $context = [
                'code' => $error['type'],
                'file' => $error['file'],
                'line' => $error['line']
            ];
            
            // Log the fatal error using our custom logger
            LoggerService::error("Fatal Error: {$error['message']}", $context);
            
            // Display an error page in production
            if (getenv('APP_ENV') === 'production') {
                http_response_code(500);
                require __DIR__ . '/../../templates/error.twig';
            }
        }
    }
} 