<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Custom logger service for the application
 * All logging should go through this service
 */
class LoggerService
{
    // Log levels
    public const ERROR = 'ERROR';
    public const WARNING = 'WARNING';
    public const INFO = 'INFO';
    public const DEBUG = 'DEBUG';
    
    private static string $logFile;
    private static bool $initialized = false;
    private static array $baseContext = [];
    
    /**
     * Initialize the logger
     */
    public static function init(?string $logFile = null): void
    {
        if (self::$initialized) {
            return;
        }
        
        // Set default log file if none provided
        self::$logFile = $logFile ?? __DIR__ . '/../../logs/app.log';
        
        // Create logs directory if it doesn't exist
        $logsDir = dirname(self::$logFile);
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0755, true);
        }
        
        self::$initialized = true;
    }
    
    /**
     * Set or extend base context for all log lines in this request/process
     */
    public static function setBaseContext(array $context): void
    {
        // Merge, with new keys overwriting previous ones
        self::$baseContext = array_replace(self::$baseContext, $context);
    }
    
    /**
     * Log an error message
     */
    public static function error(string $message, array $context = []): void
    {
        self::log(self::ERROR, $message, $context);
    }
    
    /**
     * Log a warning message
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log(self::WARNING, $message, $context);
    }
    
    /**
     * Log an info message
     */
    public static function info(string $message, array $context = []): void
    {
        self::log(self::INFO, $message, $context);
    }
    
    /**
     * Log a debug message
     */
    public static function debug(string $message, array $context = []): void
    {
        self::log(self::DEBUG, $message, $context);
    }
    
    /**
     * Log an exception
     */
    public static function exception(\Throwable $exception, ?string $message = null): void
    {
        $context = [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ];
        
        self::error($message ?? 'Exception: ' . $exception->getMessage(), $context);
    }
    
    /**
     * Write a log message
     */
    private static function log(string $level, string $message, array $context = []): void
    {
        if (!self::$initialized) {
            self::init();
        }
        
        // Merge base context and provided context
        $context = array_replace(self::$baseContext, $context);
        
        // Redact sensitive fields if present
        $sensitiveKeys = ['tmdb_api_key','tmdb_access_token','api_key','access_token','authorization','Authorization','password'];
        foreach ($sensitiveKeys as $key) {
            if (isset($context[$key]) && is_string($context[$key])) {
                $context[$key] = '***redacted***';
            }
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $contextJson = !empty($context) ? ' ' . json_encode($context) : '';
        $logMessage = "[{$timestamp}] [{$level}] {$message}{$contextJson}" . PHP_EOL;
        
        // Append to log file
        file_put_contents(self::$logFile, $logMessage, FILE_APPEND);
    }
}