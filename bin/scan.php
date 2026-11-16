<?php
declare(strict_types=1);

/**
 * Library scanner CLI.
 *
 *   php bin/scan.php            # incremental (only new/changed files)
 *   php bin/scan.php --full     # re-read every file
 *   php bin/scan.php --quiet    # only the final summary
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/scanner.php';

$full  = in_array('--full', $argv, true);
$quiet = in_array('--quiet', $argv, true);

set_time_limit(0);
ini_set('memory_limit', '512M');
ini_set('display_errors', '1');

echo 'Folder film : ' . movie_root() . (is_dir(movie_root()) ? '' : '  (TIDAK ADA)') . "\n";
echo 'Folder serial: ' . series_root() . (is_dir(series_root()) ? '' : '  (TIDAK ADA)') . "\n";
if ($full) {
    echo "Mode penuh: semua file akan dibaca ulang.\n";
}

$started = microtime(true);

$progress = null;
if (!$quiet) {
    $progress = static function (array $stats): void {
        static $last = 0;
        if ($stats['movies_seen'] - $last >= 25 || $stats['movies_seen'] === $last) {
            $last = $stats['movies_seen'];
            fwrite(STDERR, sprintf(
                "\rFilm: %d  Serial: %d  Episode: %d  ",
                $stats['movies_seen'],
                $stats['series_seen'],
                $stats['episodes_added'] + $stats['episodes_updated']
            ));
        }
    };
}

$stats = scan_library($full, $progress);

if (!$quiet) {
    fwrite(STDERR, "\n");
}

$elapsed = round(microtime(true) - $started, 1);

echo "Selesai dalam {$elapsed}s\n";
echo "  Film     : {$stats['movies_seen']} dilihat, {$stats['movies_added']} baru, "
    . "{$stats['movies_updated']} diperbarui, {$stats['movies_removed']} dihapus\n";
echo "  Serial   : {$stats['series_seen']} folder, {$stats['series_added']} baru, "
    . "{$stats['series_removed']} dihapus\n";
echo "  Episode  : {$stats['episodes_added']} baru, {$stats['episodes_updated']} diperbarui, "
    . "{$stats['episodes_removed']} dihapus\n";

if ($stats['warnings'] !== []) {
    echo "\nPeringatan (" . count($stats['warnings']) . "):\n";
    foreach (array_slice($stats['warnings'], 0, 30) as $warning) {
        echo '  - ' . $warning . "\n";
    }
    if (count($stats['warnings']) > 30) {
        echo '  … dan ' . (count($stats['warnings']) - 30) . ' lagi (lihat error log).\n';
    }
    foreach ($stats['warnings'] as $warning) {
        error_log('diarflix scan: ' . $warning);
    }
}
