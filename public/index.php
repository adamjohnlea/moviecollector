<?php
declare(strict_types=1);

// Set the environment
$appEnv = getenv('APP_ENV') ?: 'development';
putenv("APP_ENV={$appEnv}");

// Enable all errors in development
if ($appEnv === 'development') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    // Hide errors in production
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}

// Define the root directory
define('ROOT_DIR', dirname(__DIR__));

// Autoloader
require_once ROOT_DIR . '/vendor/autoload.php';

// Initialize logging
\App\Services\LoggerService::init(ROOT_DIR . '/logs/app.log');

// Initialize error handling and logging
\App\Core\ErrorHandler::register();

// Bootstrap the application
use App\Router;
use Symfony\Component\HttpFoundation\Request;

// Create the request from globals
$request = Request::createFromGlobals();

// Initialize the router
$router = new Router();

// Load routes
require_once ROOT_DIR . '/config/routes.php';

// Example of using the logger
\App\Services\LoggerService::info('Application started', [
    'environment' => $appEnv,
    'timestamp' => date('Y-m-d H:i:s')
]);

// Dispatch the request
$response = $router->dispatch($request);

// Example error logging
try {
    // Send the response
    $response->send();
} catch (\Throwable $e) {
    \App\Services\LoggerService::exception($e, 'An error occurred while processing the request');
    
    // Show error page
    if ($appEnv === 'production') {
        include ROOT_DIR . '/templates/error.twig';
    } else {
        // In development, show the error
        throw $e;
    }
} 