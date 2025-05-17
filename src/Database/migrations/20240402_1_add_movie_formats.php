<?php
declare(strict_types=1);

namespace App\Database\Migrations;

class Migration20240402_1
{
    /**
     * Run the migration
     */
    public function up(\PDO $db): void
    {
        // Create movie_formats table to store available formats
        $db->exec("
            CREATE TABLE IF NOT EXISTS movie_formats (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT,
                category TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Create junction table for user's movie formats
        $db->exec("
            CREATE TABLE IF NOT EXISTS user_movie_formats (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                movie_id INTEGER NOT NULL,
                format_id INTEGER NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                FOREIGN KEY (format_id) REFERENCES movie_formats (id) ON DELETE CASCADE,
                UNIQUE(user_id, movie_id, format_id)
            )
        ");
        
        // Insert predefined format types
        $formats = [
            // Physical Media - Standard Definition
            ['VHS', 'Video Home System tape format', 'Standard Definition'],
            ['Betamax', 'Sony\'s videocassette format (1975-2016)', 'Standard Definition'],
            ['LaserDisc', 'Optical disc video format', 'Standard Definition'],
            ['DVD', 'Digital Versatile Disc', 'Standard Definition'],
            ['VCD', 'Video Compact Disc', 'Standard Definition'],
            ['CED', 'Capacitance Electronic Disc', 'Standard Definition'],
            ['MiniDVD', 'Smaller form factor DVD', 'Standard Definition'],
            ['UMD', 'Universal Media Disc (for PlayStation Portable)', 'Standard Definition'],
            
            // Physical Media - High Definition
            ['Blu-ray', 'High-definition optical disc (BD)', 'High Definition'],
            ['4K Ultra HD Blu-ray', 'Ultra high-definition optical disc (UHD BD)', 'High Definition'],
            ['HD DVD', 'High-definition optical disc format (discontinued)', 'High Definition'],
            
            // Digital Formats
            ['Digital Download (DRM-Free)', 'Digital files without copy protection', 'Digital'],
            ['Digital Download (DRM-Protected)', 'Digital files with copy protection', 'Digital']
        ];
        
        $stmt = $db->prepare("
            INSERT INTO movie_formats (name, description, category)
            VALUES (?, ?, ?)
        ");
        
        foreach ($formats as $format) {
            $stmt->execute($format);
        }
    }
}
