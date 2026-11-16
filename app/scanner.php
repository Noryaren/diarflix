<?php
declare(strict_types=1);

/**
 * Library scanner — parses folder/file names only (no ffmpeg, no metadata API).
 *
 * Movies : /diarflix/movie/Judul Film (2020)/video.mkv  (one folder = one movie)
 * Series : /diarflix/series/Judul/Season 1/Judul.S01E01.mkv  (SxxEyy required)
 */

function scan_library(bool $full = false, ?callable $progress = null): array
{
    $stats = [
        'warnings'         => [],
        'movies_seen'      => 0,
        'movies_added'     => 0,
        'movies_updated'   => 0,
        'movies_removed'   => 0,
        'series_seen'      => 0,
        'series_added'     => 0,
        'episodes_added'   => 0,
        'episodes_updated' => 0,
        'episodes_removed' => 0,
        'series_removed'   => 0,
    ];

    if ($full) {
        db()->exec('UPDATE movies SET file_mtime = 0, file_size = 0');
        db()->exec('UPDATE episodes SET file_mtime = 0, file_size = 0');
    }

    scan_movies($stats, $progress);
    scan_series($stats, $progress);

    return $stats;
}

/* -------------------------------------------------------------------------
 * Movies
 * ---------------------------------------------------------------------- */

function scan_movies(array &$stats, ?callable $progress): void
{
    $root = movie_root();

    if (!is_dir($root)) {
        $stats['warnings'][] = 'Folder film tidak ada: ' . $root;
        return;
    }

    $videoExt = video_extensions();
    $seen     = [];

    $entries = @scandir($root) ?: [];
    sort($entries);

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $full = $root . '/' . $entry;

        if (is_dir($full)) {
            [$title, $year] = parse_title_year($entry);

            $videos = [];
            foreach (@scandir($full) ?: [] as $child) {
                if ($child === '.' || $child === '..') {
                    continue;
                }
                $childFull = $full . '/' . $child;
                if (is_file($childFull) && in_array(strtolower(pathinfo($child, PATHINFO_EXTENSION)), $videoExt, true)) {
                    $videos[] = $childFull;
                }
            }

            if ($videos === []) {
                $stats['warnings'][] = 'Folder tanpa video: ' . $entry;
                continue;
            }

            // One folder = one movie: use the largest video file.
            usort($videos, static fn ($a, $b) => (int) @filesize($b) <=> (int) @filesize($a));
            $video      = $videos[0];
            $seen[]     = $video;
            $stats['movies_seen']++;

            if (count($videos) > 1) {
                $stats['warnings'][] = $entry . ': ' . count($videos)
                    . ' video, pakai yang terbesar saja';
            }

            $folderPoster = find_folder_poster($full);
            upsert_movie($video, $title, $year, $folderPoster, $stats);
        } elseif (is_file($full) && in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), $videoExt, true)) {
            [$title, $year] = parse_title_year(pathinfo($entry, PATHINFO_FILENAME));
            $seen[]         = $full;
            $stats['movies_seen']++;
            upsert_movie($full, $title, $year, null, $stats);
        }

        if ($progress !== null && ($stats['movies_seen'] % 25) === 0) {
            $progress($stats);
        }
    }

    remove_missing('movies', $seen, $stats['movies_removed']);
}

function upsert_movie(string $path, string $title, ?int $year, ?string $folderPoster, array &$stats): void
{
    $size  = (int) @filesize($path);
    $mtime = (int) @filemtime($path);

    $stmt = db()->prepare('SELECT id, file_size, file_mtime, overview, poster FROM movies WHERE file_path = ? LIMIT 1');
    $stmt->execute([$path]);
    $row = $stmt->fetch();

    if ($row) {
        if ((int) $row['file_size'] === $size && (int) $row['file_mtime'] === $mtime) {
            // File unchanged: still refresh the folder poster in case one was added later.
            db()->prepare('UPDATE movies SET folder_poster = ? WHERE id = ?')
                ->execute([$folderPoster, (int) $row['id']]);
            return;
        }

        // Never touch title/overview/poster the admin may have edited.
        $upd = db()->prepare('UPDATE movies SET file_size = ?, file_mtime = ?, folder_poster = ? WHERE id = ?');
        $upd->execute([$size, $mtime, $folderPoster, (int) $row['id']]);
        $stats['movies_updated']++;
        return;
    }

    $ins = db()->prepare(
        'INSERT INTO movies (title, year, folder_poster, file_path, file_size, file_mtime, added_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())'
    );
    $ins->execute([$title, $year, $folderPoster, $path, $size, $mtime]);
    $stats['movies_added']++;
}

/* -------------------------------------------------------------------------
 * Series
 * ---------------------------------------------------------------------- */

function scan_series(array &$stats, ?callable $progress): void
{
    $root = series_root();

    if (!is_dir($root)) {
        $stats['warnings'][] = 'Folder serial tidak ada: ' . $root;
        return;
    }

    $videoExt = video_extensions();
    $seen     = [];
    $showIds  = [];

    $entries = @scandir($root) ?: [];
    sort($entries);

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $showDir = $root . '/' . $entry;
        if (!is_dir($showDir)) {
            continue;
        }

        [$title, $year] = parse_title_year($entry);
        $homePath       = $showDir;
        $folderPoster   = find_folder_poster($showDir);

        $seriesId = upsert_series($homePath, $title, $year, $folderPoster, $stats);
        $showIds[] = $seriesId;
        $stats['series_seen']++;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($showDir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            if (!in_array(strtolower($fileInfo->getExtension()), $videoExt, true)) {
                continue;
            }

            $path = $fileInfo->getPathname();
            $rel  = ltrim(substr($path, strlen($showDir)), '/');

            if (preg_match('/S(\d{1,2})[\s._-]*E(\d{1,3})/i', $rel, $m)) {
                $seasonNo = (int) $m[1];
                $epNo     = (int) $m[2];
            } elseif (preg_match('#(?:^|/)(?:Season|S)\s?(\d{1,2})(?:/|$)#i', $rel, $m)
                && preg_match('/(?:^|[\s._-])(?:E|EP|Episode)[\s._-]?(\d{1,3})(?=[\s._-]|$)/i', basename($path), $m2)) {
                $seasonNo = (int) $m[1];
                $epNo     = (int) $m[2];
            } else {
                $stats['warnings'][] = $entry . '/' . $rel . ': pola SxxEyy tidak ditemukan, dilewati';
                continue;
            }

            $epTitle = parse_episode_title($path, $seasonNo, $epNo);
            $seen[]  = $path;
            upsert_episode($seriesId, $seasonNo, $epNo, $epTitle, $path, $stats);

            if ($progress !== null && (count($seen) % 50) === 0) {
                $progress($stats);
            }
        }
    }

    remove_missing('episodes', $seen, $stats['episodes_removed']);

    // Drop series that no longer have any episode on disk.
    $orphan = db()->query(
        'SELECT s.id FROM series s
         LEFT JOIN episodes e ON e.series_id = s.id
         WHERE e.id IS NULL'
    )->fetchAll();
    foreach ($orphan as $row) {
        db()->prepare('DELETE FROM series WHERE id = ?')->execute([(int) $row['id']]);
        $stats['series_removed']++;
    }
}

function upsert_series(string $homePath, string $title, ?int $year, ?string $folderPoster, array &$stats): int
{
    $stmt = db()->prepare('SELECT id FROM series WHERE home_path = ? LIMIT 1');
    $stmt->execute([$homePath]);
    $row = $stmt->fetch();

    if ($row) {
        $upd = db()->prepare('UPDATE series SET title = ?, year = ?, folder_poster = ? WHERE id = ?');
        $upd->execute([$title, $year, $folderPoster, (int) $row['id']]);
        return (int) $row['id'];
    }

    $ins = db()->prepare(
        'INSERT INTO series (title, home_path, year, folder_poster, added_at) VALUES (?, ?, ?, ?, NOW())'
    );
    $ins->execute([$title, $homePath, $year, $folderPoster]);
    $stats['series_added']++;

    return (int) db()->lastInsertId();
}

function upsert_episode(int $seriesId, int $seasonNo, int $epNo, string $epTitle, string $path, array &$stats): void
{
    $seasonId = season_id($seriesId, $seasonNo);
    $size     = (int) @filesize($path);
    $mtime    = (int) @filemtime($path);

    $stmt = db()->prepare('SELECT id, file_size, file_mtime FROM episodes WHERE file_path = ? LIMIT 1');
    $stmt->execute([$path]);
    $row = $stmt->fetch();

    if ($row) {
        if ((int) $row['file_size'] === $size && (int) $row['file_mtime'] === $mtime) {
            db()->prepare('UPDATE episodes SET season_id = ?, episode_no = ? WHERE id = ?')
                ->execute([$seasonId, $epNo, (int) $row['id']]);
            return;
        }
        db()->prepare('UPDATE episodes SET season_id = ?, episode_no = ?, title = ?, file_size = ?, file_mtime = ? WHERE id = ?')
            ->execute([$seasonId, $epNo, $epTitle, $size, $mtime, (int) $row['id']]);
        $stats['episodes_updated']++;
        return;
    }

    $ins = db()->prepare(
        'INSERT INTO episodes (series_id, season_id, episode_no, title, file_path, file_size, file_mtime, added_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $ins->execute([$seriesId, $seasonId, $epNo, $epTitle, $path, $size, $mtime]);
    $stats['episodes_added']++;
}

function season_id(int $seriesId, int $seasonNo): int
{
    $stmt = db()->prepare('SELECT id FROM seasons WHERE series_id = ? AND season_no = ? LIMIT 1');
    $stmt->execute([$seriesId, $seasonNo]);
    $row = $stmt->fetch();

    if ($row) {
        return (int) $row['id'];
    }

    $ins = db()->prepare('INSERT INTO seasons (series_id, season_no, name) VALUES (?, ?, ?)');
    $ins->execute([$seriesId, $seasonNo, 'Season ' . $seasonNo]);

    return (int) db()->lastInsertId();
}

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

function remove_missing(string $table, array $seenPaths, int &$removed): void
{
    $col  = $table === 'movies' ? 'file_path' : 'file_path';
    $stmt = db()->query("SELECT id, {$col} AS p FROM {$table}");
    $seen = array_flip($seenPaths);

    foreach ($stmt as $row) {
        if (!isset($seen[$row['p']])) {
            db()->prepare("DELETE FROM {$table} WHERE id = ?")->execute([(int) $row['id']]);
            $removed++;
        }
    }
}

function find_folder_poster(string $dir): ?string
{
    $names = config('media.folder_posters');
    if (!is_array($names)) {
        return null;
    }

    foreach ($names as $name) {
        $path = $dir . '/' . $name;
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

/**
 * "Judul Film (2020)" / "Judul.Film.2020.1080p" → ["Judul Film", 2020]
 */
function parse_title_year(string $name): array
{
    $name = trim($name);
    $year = null;

    if (preg_match('/\((19|20)\d{2}\)\s*$/', $name, $m)) {
        $year = (int) $m[1];
        $name = trim(preg_replace('/\((19|20)\d{2}\)\s*$/', '', $name));
    } elseif (preg_match('/[\s._-]+(19|20)\d{2}\s*$/', $name, $m)) {
        $year = (int) $m[1];
        $name = trim(preg_replace('/[\s._-]+(19|20)\d{2}\s*$/', '', $name));
    }

    $name = str_replace(['.', '_'], ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;
    $name = trim($name);

    // Strip common release tokens from file-derived names.
    $tokens = '1080p|720p|2160p|480p|4k|web-?dl|webrip|bluray|brrip|dvdrip|hdtv|x264|x265|h264|h265|hevc|aac|dts|remux|proper|repack|uncut|extended|subbed|dubbed|indo|idn';
    $name   = preg_replace('/\s+(' . $tokens . ')(\s+|$)/i', ' ', $name) ?? $name;
    $name   = preg_replace('/\s+/', ' ', $name) ?? $name;
    $name   = trim($name, " -._");

    if ($name === '') {
        $name = 'Tanpa Judul';
    }

    return [$name, $year];
}

/**
 * "Show.S01E02.The.Pilot.1080p.mkv" → "The Pilot"
 */
function parse_episode_title(string $path, int $seasonNo, int $epNo): string
{
    $base = pathinfo($path, PATHINFO_FILENAME);

    if (preg_match('/S\d{1,2}[\s._-]*E\d{1,3}[\s._-]*(.+)$/i', $base, $m)) {
        $title = $m[1];
        $title = str_replace(['.', '_'], ' ', $title);
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;
        $title = trim($title, " -._");

        $tokens = '1080p|720p|2160p|480p|4k|web-?dl|webrip|bluray|x264|x265|h264|h265|hevc|aac|dts';
        $title  = preg_replace('/\s+(' . $tokens . ')(\s+|$)/i', ' ', $title) ?? $title;
        $title  = trim($title);

        if ($title !== '') {
            return mb_substr($title, 0, 255);
        }
    }

    return 'Episode ' . $epNo;
}
