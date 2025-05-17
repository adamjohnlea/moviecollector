<?php
declare(strict_types=1);

namespace App\Models;

use App\Database\Database;

class User
{
    private int $id;
    private string $username;
    private string $email;
    private string $passwordHash;
    private string $createdAt;
    private string $updatedAt;
    
    /**
     * Create a new user in the database
     */
    public static function create(string $username, string $email, string $password): ?User
    {
        $db = Database::getInstance();
        
        try {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt = $db->prepare("
                INSERT INTO users (username, email, password_hash)
                VALUES (?, ?, ?)
            ");
            
            $stmt->execute([$username, $email, $passwordHash]);
            $userId = (int) $db->lastInsertId();
            
            // Create empty settings for the user
            $settingsStmt = $db->prepare("
                INSERT INTO user_settings (user_id)
                VALUES (?)
            ");
            $settingsStmt->execute([$userId]);
            
            // Retrieve the newly created user
            return self::findById($userId);
        } catch (\PDOException $e) {
            // Handle duplicate username/email
            return null;
        }
    }
    
    /**
     * Find a user by ID
     */
    public static function findById(int $id): ?User
    {
        $db = Database::getInstance();
        
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        
        $userData = $stmt->fetch();
        
        if (!$userData) {
            return null;
        }
        
        return self::createFromArray($userData);
    }
    
    /**
     * Find a user by username
     */
    public static function findByUsername(string $username): ?User
    {
        $db = Database::getInstance();
        
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        
        $userData = $stmt->fetch();
        
        if (!$userData) {
            return null;
        }
        
        return self::createFromArray($userData);
    }
    
    /**
     * Find a user by email
     */
    public static function findByEmail(string $email): ?User
    {
        $db = Database::getInstance();
        
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        
        $userData = $stmt->fetch();
        
        if (!$userData) {
            return null;
        }
        
        return self::createFromArray($userData);
    }
    
    /**
     * Verify user credentials and return user if valid
     */
    public static function authenticate(string $username, string $password): ?User
    {
        $user = self::findByUsername($username);
        
        if (!$user) {
            return null;
        }
        
        if (password_verify($password, $user->getPasswordHash())) {
            return $user;
        }
        
        return null;
    }
    
    /**
     * Create a User object from database row
     */
    private static function createFromArray(array $userData): User
    {
        $user = new User();
        $user->id = (int) $userData['id'];
        $user->username = $userData['username'];
        $user->email = $userData['email'];
        $user->passwordHash = $userData['password_hash'];
        $user->createdAt = $userData['created_at'];
        $user->updatedAt = $userData['updated_at'];
        
        return $user;
    }
    
    // Getters
    public function getId(): int
    {
        return $this->id;
    }
    
    public function getUsername(): string
    {
        return $this->username;
    }
    
    public function getEmail(): string
    {
        return $this->email;
    }
    
    private function getPasswordHash(): string
    {
        return $this->passwordHash;
    }
    
    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }
    
    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }
} 