<?php
declare(strict_types=1);

namespace App\Core;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\Markup;

class TwigExtension extends AbstractExtension
{
    /**
     * Register custom filters
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('json_decode', [$this, 'jsonDecode']),
        ];
    }
    
    /**
     * Decode a JSON string into an array or object
     */
    public function jsonDecode($input): mixed
    {
        // Null check
        if ($input === null) {
            return null;
        }
        
        // Already an array - return as is
        if (is_array($input)) {
            return $input;
        }
        
        // Handle Twig\Markup objects
        if ($input instanceof Markup) {
            $string = (string)$input;
            
            // Try to decode the string from Markup if it looks like JSON
            if (preg_match('/^\s*[\{\[]/', $string)) {
                $decoded = json_decode($string, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }
            
            return $string;
        }
        
        // Handle regular strings
        if (is_string($input)) {
            // Check if it appears to be JSON
            if (preg_match('/^\s*[\{\[]/', $input)) {
                $decoded = json_decode($input, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }
        }
        
        // Return original input as a fallback
        return $input;
    }
} 