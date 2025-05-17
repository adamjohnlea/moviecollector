<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database\Migration;

// Run all migrations
$migration = new Migration();
$migration->runMigrations();

echo "Database initialization complete!" . PHP_EOL; 