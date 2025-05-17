<?php
declare(strict_types=1);

namespace App\Database;

class Migration
{
    private \PDO $db;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    /**
     * Run all migrations to set up database schema
     */
    public function runMigrations(): void
    {
        // First create the migrations table if it doesn't exist
        $this->createMigrationsTable();
        
        // Get list of migrations that have already been run
        $stmt = $this->db->query("SELECT name FROM migrations");
        $completedMigrations = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        
        // Get all migration files
        $files = glob(__DIR__ . '/migrations/*.php');
        
        // Run each migration that hasn't been run yet
        foreach ($files as $file) {
            $migrationName = basename($file);
            
            if (!in_array($migrationName, $completedMigrations)) {
                require_once $file;
                
                // Format: YYYYMMDD_description.php -> MigrationYYYYMMDD_N
                $className = 'App\\Database\\Migrations\\Migration' . substr($migrationName, 0, 8);
                
                // Extract the migration number if present (e.g. _3)
                if (preg_match('/_(\d+)_/', $migrationName, $matches)) {
                    $className .= '_' . $matches[1];
                }
                
                if (class_exists($className)) {
                    $migration = new $className();
                    $migration->up($this->db);
                    
                    // Record that migration has been run
                    $stmt = $this->db->prepare("INSERT INTO migrations (name) VALUES (?)");
                    $stmt->execute([$migrationName]);
                    
                    echo "Migration $migrationName completed." . PHP_EOL;
                }
            }
        }
    }
    
    /**
     * Create the migrations table to track which migrations have been run
     */
    private function createMigrationsTable(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }
} 