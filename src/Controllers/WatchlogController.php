<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Services\SessionService;
use App\Services\LoggerService as Logger;
use App\Services\TmdbService;
use App\Models\UserSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WatchlogController extends Controller
{
    public function index(Request $request): Response
    {
        // Require login
        $redirect = $this->requireLogin();
        if ($redirect) {
            return $redirect;
        }

        $user = SessionService::getCurrentUser();
        $page = max(1, (int)($request->query->get('page') ?? 1));
        $perPage = max(1, min(50, (int)($request->query->get('limit') ?? 25)));
        $offset = ($page - 1) * $perPage;

        $db = Database::getInstance();

        // Fetch logs with one extra row to detect next page
        $stmt = $db->prepare("SELECT * FROM watched_logs WHERE user_id = ? ORDER BY watched_at DESC, id DESC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $user->getId(), \PDO::PARAM_INT);
        $stmt->bindValue(2, $perPage + 1, \PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];

        $hasNext = false;
        if (count($rows) > $perPage) {
            $hasNext = true;
            $rows = array_slice($rows, 0, $perPage);
        }

        // Prepare TMDb service if possible
        $tmdb = null;
        try {
            $settings = UserSettings::getByUserId($user->getId());
            if ($settings && $settings->hasValidTmdbCredentials()) {
                $tmdb = new TmdbService($settings);
            }
        } catch (\Throwable $e) {
            Logger::warning('Failed to initialize TMDb service for watchlog', ['error' => $e->getMessage()]);
        }

        $items = [];
        foreach ($rows as $log) {
            $tmdbId = (int)$log['tmdb_id'];
            // Fetch the user\'s movie row for title and local poster
            $m = $db->prepare("SELECT title, local_poster_path, poster_path, last_updated_at FROM movies WHERE user_id = ? AND tmdb_id = ? LIMIT 1");
            $m->execute([$user->getId(), $tmdbId]);
            $movieRow = $m->fetch() ?: [];

            $posterUrl = null;
            if (!empty($movieRow['local_poster_path'])) {
                $version = isset($movieRow['last_updated_at']) ? rawurlencode((string)$movieRow['last_updated_at']) : '';
                $posterUrl = $movieRow['local_poster_path'] . ($version ? ('?v=' . $version) : '');
            } elseif (!empty($movieRow['poster_path']) && $tmdb) {
                // Use a smaller size for list
                $posterUrl = $tmdb->getImageUrl($movieRow['poster_path'], 'w185');
            }

            $items[] = [
                'id' => (int)$log['id'],
                'tmdb_id' => $tmdbId,
                'title' => $movieRow['title'] ?? 'Untitled',
                'poster_url' => $posterUrl,
                'watched_at' => $log['watched_at'],
            ];
        }

        return $this->renderResponse('watchlog/index.twig', [
            'items' => $items,
            'page' => $page,
            'has_next' => $hasNext,
            'has_prev' => $page > 1,
            'limit' => $perPage,
        ]);
    }
}
