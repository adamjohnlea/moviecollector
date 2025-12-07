<?php
declare(strict_types=1);

namespace App\Models;

use App\Database\Database;
use App\Services\LoggerService as Logger;

class WatchedLog
{
    public static function create(int $userId, int $tmdbId, ?\DateTimeInterface $watchedAt = null, array $meta = []): bool
    {
        $db = Database::getInstance();

        $watchedAtStr = ($watchedAt ?? new \DateTimeImmutable('now'))
            ->format('Y-m-d H:i:s');

        $stmt = $db->prepare("INSERT INTO watched_logs (user_id, tmdb_id, watched_at, source, format_id, location, runtime_minutes, rating, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ok = $stmt->execute([
            $userId,
            $tmdbId,
            $watchedAtStr,
            $meta['source'] ?? null,
            isset($meta['format_id']) ? (int)$meta['format_id'] : null,
            $meta['location'] ?? null,
            isset($meta['runtime_minutes']) ? (int)$meta['runtime_minutes'] : null,
            isset($meta['rating']) ? (int)$meta['rating'] : null,
            $meta['notes'] ?? null,
        ]);

        if ($ok) {
            // Update roll-ups on movies for this user+movie
            self::recomputeRollups($userId, $tmdbId);
        } else {
            Logger::warning('Failed to insert watched log', [
                'user_id' => $userId,
                'tmdb_id' => $tmdbId,
            ]);
        }
        return $ok;
    }

    public static function delete(int $userId, int $logId): bool
    {
        $db = Database::getInstance();

        // Get the log first to know which tmdb_id to recompute
        $stmt = $db->prepare("SELECT tmdb_id FROM watched_logs WHERE id = ? AND user_id = ?");
        $stmt->execute([$logId, $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }
        $tmdbId = (int)$row['tmdb_id'];

        $del = $db->prepare("DELETE FROM watched_logs WHERE id = ? AND user_id = ?");
        $ok = $del->execute([$logId, $userId]);
        if ($ok) {
            self::recomputeRollups($userId, $tmdbId);
        }
        return $ok;
    }

    public static function getByMovie(int $userId, int $tmdbId, int $limit = 20, int $offset = 0): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM watched_logs WHERE user_id = ? AND tmdb_id = ? ORDER BY watched_at DESC, id DESC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $tmdbId, \PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(4, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public static function getRecentByUser(int $userId, int $limit = 10): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM watched_logs WHERE user_id = ? ORDER BY watched_at DESC, id DESC LIMIT ?");
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Update a watched log entry. Returns [ok => bool, tmdb_id => int].
     */
    public static function update(int $userId, int $logId, array $fields): array
    {
        $db = Database::getInstance();

        // Ensure ownership and get current row
        $stmt = $db->prepare("SELECT * FROM watched_logs WHERE id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$logId, $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['ok' => false, 'tmdb_id' => 0];
        }

        $updates = [];
        $params = [];

        // Editable fields
        if (isset($fields['watched_at']) && $fields['watched_at']) {
            try {
                $dt = new \DateTimeImmutable((string)$fields['watched_at']);
                $updates[] = 'watched_at = ?';
                $params[] = $dt->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                // ignore invalid date
            }
        }
        if (array_key_exists('source', $fields)) { $updates[] = 'source = ?'; $params[] = $fields['source'] ?: null; }
        if (array_key_exists('format_id', $fields)) { $updates[] = 'format_id = ?'; $params[] = $fields['format_id'] !== null ? (int)$fields['format_id'] : null; }
        if (array_key_exists('location', $fields)) { $updates[] = 'location = ?'; $params[] = $fields['location'] ?: null; }
        if (array_key_exists('runtime_minutes', $fields)) { $updates[] = 'runtime_minutes = ?'; $params[] = $fields['runtime_minutes'] !== null ? (int)$fields['runtime_minutes'] : null; }
        if (array_key_exists('rating', $fields)) { $updates[] = 'rating = ?'; $params[] = $fields['rating'] !== null ? (int)$fields['rating'] : null; }
        if (array_key_exists('notes', $fields)) { $updates[] = 'notes = ?'; $params[] = $fields['notes'] ?: null; }

        if (empty($updates)) {
            return ['ok' => true, 'tmdb_id' => (int)$row['tmdb_id']];
        }

        $updates[] = 'updated_at = CURRENT_TIMESTAMP';
        $params[] = $logId;
        $params[] = $userId;

        $sql = 'UPDATE watched_logs SET ' . implode(', ', $updates) . ' WHERE id = ? AND user_id = ?';
        $ok = $db->prepare($sql)->execute($params);

        if ($ok) {
            // recompute rollups if watched_at changed or potentially relevant
            self::recomputeRollups($userId, (int)$row['tmdb_id']);
        }

        return ['ok' => (bool)$ok, 'tmdb_id' => (int)$row['tmdb_id']];
    }

    public static function recomputeRollups(int $userId, int $tmdbId): void
    {
        $db = Database::getInstance();
        // Count and last watched
        $stmt = $db->prepare("SELECT COUNT(*) as cnt, MAX(watched_at) as last_at FROM watched_logs WHERE user_id = ? AND tmdb_id = ?");
        $stmt->execute([$userId, $tmdbId]);
        $row = $stmt->fetch();
        $count = (int)($row['cnt'] ?? 0);
        $lastAt = $row['last_at'] ?? null;

        // Update movies table for this user+movie
        $upd = $db->prepare("UPDATE movies SET watched_count = ?, last_watched_at = ? WHERE user_id = ? AND tmdb_id = ?");
        $upd->execute([$count, $lastAt, $userId, $tmdbId]);
    }
}
