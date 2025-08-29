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

// Attach per-request logging context
$rid = bin2hex(random_bytes(8));
\App\Services\LoggerService::setBaseContext([
    'request_id' => $rid,
    'method' => $request->getMethod(),
    'path' => $request->getPathInfo(),
]);

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
        http_response_code(500);
        $errorPage = ROOT_DIR . '/public/500.html';
        if (is_readable($errorPage)) {
            readfile($errorPage);
        } else {
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Server Error</title><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#0b0d12;color:#e6e8eb;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.card{max-width:560px;padding:32px;border-radius:12px;background:#131722;box-shadow:0 6px 24px rgba(0,0,0,.4)}h1{margin:0 0 8px;font-size:24px}p{margin:0;color:#b3bac5}</style></head><body><div class="card"><h1>Something went wrong</h1><p>An unexpected error occurred. Please try again later.</p></div></body></html>';
        }
    } else {
        // In development, show the error
        throw $e;
    }
} 