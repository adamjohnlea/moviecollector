<?php
declare(strict_types=1);

namespace App\Database;

class Database
{
    private static ?\PDO $instance = null;
    private const DB_PATH = __DIR__ . '/../../data/moviecollector.sqlite';

    /**
     * Get PDO instance (singleton pattern)
     */
    public static function getInstance(): \PDO
    {
        if (self::$instance === null) {
            // Create data directory if it doesn't exist
            $dataDir = dirname(self::DB_PATH);
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0755, true);
            }

            // Initialize PDO connection
            $dsn = 'sqlite:' . self::DB_PATH;
            self::$instance = new \PDO($dsn, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            
            // Enable foreign key constraints
            self::$instance->exec('PRAGMA foreign_keys = ON');
        }
        
        return self::$instance;
    }
} 