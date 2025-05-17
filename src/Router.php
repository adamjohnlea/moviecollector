<?php
declare(strict_types=1);

namespace App;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class Router
{
    private array $routes = [];
    
    /**
     * Register a GET route
     */
    public function get(string $path, callable|array $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }
    
    /**
     * Register a POST route
     */
    public function post(string $path, callable|array $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }
    
    /**
     * Add a route to the routing table
     */
    private function addRoute(string $method, string $path, callable|array $handler): void
    {
        // Convert path parameters to regex pattern
        $pattern = preg_replace('/{([^}]+)}/', '(?P<$1>[^/]+)', $path);
        $pattern = "#^{$pattern}$#";
        
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'handler' => $handler
        ];
    }
    
    /**
     * Dispatch the request to the appropriate route handler
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->getMethod();
        $uri = $request->getPathInfo();
        
        // Remove trailing slash except for the root path
        if ($uri !== '/' && str_ends_with($uri, '/')) {
            $uri = rtrim($uri, '/');
        }
        
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            
            if (preg_match($route['pattern'], $uri, $matches)) {
                // Remove numeric keys
                foreach ($matches as $key => $value) {
                    if (is_int($key)) {
                        unset($matches[$key]);
                    }
                }
                
                // Call the route handler
                $handler = $route['handler'];
                if (is_array($handler) && count($handler) === 2) {
                    [$controllerClass, $method] = $handler;
                    $controller = new $controllerClass();
                    $response = $controller->$method($request, $matches);
                } else {
                    $response = $handler($request, $matches);
                }
                
                return $response instanceof Response 
                    ? $response 
                    : new Response((string) $response);
            }
        }
        
        // No route found
        return new Response('Page not found', Response::HTTP_NOT_FOUND);
    }
} 