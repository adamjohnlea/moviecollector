#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database\Database;

const POSTERS_DIR = __DIR__ . '/../public/uploads/posters';
const BACKDROPS_DIR = __DIR__ . '/../public/uploads/backdrops';

function bytes_human(int $bytes): string {
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    $val = (float)$bytes;
    while ($val >= 1024 && $i < count($units)-1) { $val /= 1024; $i++; }
    return sprintf('%.2f %s', $val, $units[$i]);
}

function list_files(string $dir): array {
    if (!is_dir($dir)) return [];
    $files = [];
    $dh = opendir($dir);
    if ($dh === false) return [];
    while (($f = readdir($dh)) !== false) {
        if ($f === '.' || $f === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $f;
        if (is_file($path)) $files[] = $path;
    }
    closedir($dh);
    return $files;
}

function collect_db_refs(): array {
    $db = Database::getInstance();
    $stmt = $db->query("SELECT local_poster_path, local_backdrop_path FROM movies");
    $refs = [];
    while ($row = $stmt->fetch()) {
        foreach (['local_poster_path','local_backdrop_path'] as $col) {
            if (!empty($row[$col]) && is_string($row[$col])) {
                // Convert web path to absolute path
                $web = $row[$col]; // e.g., /uploads/posters/abc.jpg
                $abs = realpath(__DIR__ . '/../public' . $web) ?: (__DIR__ . '/../public' . $web);
                $refs[$abs] = true;
            }
        }
    }
    return $refs;
}

function cmd_stats(): int {
    $posters = list_files(POSTERS_DIR);
    $backdrops = list_files(BACKDROPS_DIR);
    $count = count($posters) + count($backdrops);
    $bytes = 0;
    foreach (array_merge($posters, $backdrops) as $p) { $bytes += filesize($p) ?: 0; }
    echo "Image cache stats\n";
    echo "- Posters:   " . str_pad((string)count($posters), 6, ' ', STR_PAD_LEFT) . " files\n";
    echo "- Backdrops: " . str_pad((string)count($backdrops), 6, ' ', STR_PAD_LEFT) . " files\n";
    echo "- Total:     " . str_pad((string)$count, 6, ' ', STR_PAD_LEFT) . " files\n";
    echo "- Size:      " . bytes_human($bytes) . "\n";
    return 0;
}

function cmd_prune_orphans(bool $dryRun = false): int {
    $refs = collect_db_refs();
    $files = array_merge(list_files(POSTERS_DIR), list_files(BACKDROPS_DIR));
    $orphans = array_values(array_filter($files, fn($f) => !isset($refs[realpath($f) ?: $f])));
    $deleted = 0; $bytes = 0;
    foreach ($orphans as $f) {
        $size = filesize($f) ?: 0;
        if ($dryRun) {
            echo "DRY-RUN would delete: $f (" . bytes_human($size) . ")\n";
            continue;
        }
        if (@unlink($f)) { $deleted++; $bytes += $size; echo "Deleted: $f\n"; }
    }
    if (!$dryRun) echo "Pruned $deleted files, freed " . bytes_human($bytes) . "\n";
    return 0;
}

function cmd_prune_older_than(int $days, bool $dryRun = false): int {
    $cutoff = time() - ($days * 86400);
    $refs = collect_db_refs();
    $files = array_merge(list_files(POSTERS_DIR), list_files(BACKDROPS_DIR));
    $deleted = 0; $bytes = 0;
    foreach ($files as $f) {
        $rp = realpath($f) ?: $f;
        if (isset($refs[$rp])) continue; // never delete referenced files
        $mtime = filemtime($f) ?: 0;
        if ($mtime > 0 && $mtime < $cutoff) {
            $size = filesize($f) ?: 0;
            if ($dryRun) {
                echo "DRY-RUN would delete: $f (" . bytes_human($size) . ")\n";
                continue;
            }
            if (@unlink($f)) { $deleted++; $bytes += $size; echo "Deleted: $f\n"; }
        }
    }
    if (!$dryRun) echo "Pruned $deleted files older than $days days, freed " . bytes_human($bytes) . "\n";
    return 0;
}

function usage(): void {
    echo "Usage:\n";
    echo "  php bin/image_cache.php stats\n";
    echo "  php bin/image_cache.php prune --orphans [--dry-run]\n";
    echo "  php bin/image_cache.php prune --older-than=N [--dry-run]\n";
}

$argv = $_SERVER['argv'] ?? [];
array_shift($argv); // script name
if (count($argv) === 0) { usage(); exit(1); }

$cmd = array_shift($argv);
switch ($cmd) {
    case 'stats':
        exit(cmd_stats());
    case 'prune':
        $dryRun = in_array('--dry-run', $argv, true);
        if (in_array('--orphans', $argv, true)) {
            exit(cmd_prune_orphans($dryRun));
        }
        $olderArg = null;
        foreach ($argv as $a) {
            if (str_starts_with($a, '--older-than=')) { $olderArg = (int)substr($a, strlen('--older-than=')); }
        }
        if ($olderArg !== null && $olderArg > 0) {
            exit(cmd_prune_older_than($olderArg, $dryRun));
        }
        usage();
        exit(1);
    default:
        usage();
        exit(1);
}
