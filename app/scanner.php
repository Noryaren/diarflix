<?php
declare(strict_types=1);

/**
 * Library scanner — parses folder/file names only (no ffmpeg, no metadata API).
 *
 * Movies : /diarflix/movie/Judul Film (2020)/video.mkv  (one folder = one movie)
 * Series : /diarflix/series/Judul/Season 1/Judul.S01E01.mkv  (SxxEyy required)
 */

require_once __DIR__ . '/transcode.php';

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
            $before       = $stats['movies_added'] + $stats['movies_updated'];
            upsert_movie($video, $title, $year, $folderPoster, $stats);
            $changed = ($stats['movies_added'] + $stats['movies_updated']) > $before;
            transcode_scan_check($video, 'movie', $entry, $changed, $stats);
            extract_embedded_subtitles($video, $stats, $changed);
        } elseif (is_file($full) && in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), $videoExt, true)) {
            [$title, $year] = parse_title_year(pathinfo($entry, PATHINFO_FILENAME));
            $seen[]         = $full;
            $stats['movies_seen']++;
            $before = $stats['movies_added'] + $stats['movies_updated'];
            upsert_movie($full, $title, $year, null, $stats);
            $changed = ($stats['movies_added'] + $stats['movies_updated']) > $before;
            transcode_scan_check($full, 'movie', $entry, $changed, $stats);
            extract_embedded_subtitles($full, $stats, $changed);
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
            $rel  = str_replace('\\', '/', ltrim(substr($path, strlen($showDir)), '/'));

            if (preg_match('/S(\d{1,2})[\s._-]*E(\d{1,3})/i', $rel, $m)) {
                $seasonNo = (int) $m[1];
                $epNo     = (int) $m[2];
            } elseif (preg_match('#(?:^|/)(?:Season|S)\s?(\d{1,2})(?:/|$)#i', $rel, $m)) {
                // Season folder gives the season; the filename must carry the
                // episode number as "E02"/"EP2"/"Episode 3" or a bare "02".
                $seasonNo = (int) $m[1];
                $base     = pathinfo($path, PATHINFO_FILENAME);

                if (preg_match('/(?:^|[\s._-])(?:E|EP|Episode)[\s._-]?(\d{1,3})(?=[\s._-]|$)/i', $base, $m2)) {
                    $epNo = (int) $m2[1];
                } elseif (preg_match('/^(\d{1,3})(?:$|[\s._-])/', $base, $m2)) {
                    $epNo = (int) $m2[1];
                } else {
                    $stats['warnings'][] = $entry . '/' . $rel . ': nomor episode tidak ditemukan, dilewati';
                    continue;
                }
            } else {
                // Fallback: "Show - 01.mkv" / "Show - 25 END.mkv" / "01.mkv" without season info -> Season 1.
                $base = pathinfo($path, PATHINFO_FILENAME);
                if (preg_match('/(?:^|[\s._-])(\d{1,3})(?:\s*(?:END|FINAL|OVA|SP))?(?:\s*(?:\[.*\]|\(.*\)))?\s*$/i', $base, $m2)) {
                    $seasonNo = 1;
                    $epNo     = (int) $m2[1];
                } else {
                    $stats['warnings'][] = $entry . '/' . $rel . ': pola SxxEyy tidak ditemukan, dilewati';
                    continue;
                }
            }

            $epTitle = parse_episode_title($path, $seasonNo, $epNo);
            $seen[]  = $path;
            $before  = $stats['episodes_added'] + $stats['episodes_updated'];
            upsert_episode($seriesId, $seasonNo, $epNo, $epTitle, $path, $stats);
            $changed = ($stats['episodes_added'] + $stats['episodes_updated']) > $before;
            transcode_scan_check($path, 'episode', $entry . '/' . $rel, $changed, $stats);
            extract_embedded_subtitles($path, $stats, $changed);

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

/**
 * Export embedded MKV subtitle tracks as sidecar SRT files. The browser's
 * <video> element cannot read softsubs inside the container, only external
 * tracks.
 *
 * Dipanggil setiap scan (bukan hanya saat file berubah) supaya ekstraksi yang
 * gagal — misalnya file masih diunggah — otomatis dicoba lagi pada scan
 * berikutnya. Berhenti lebih dulu kalau ffmpeg/ffprobe tidak ada, file masih
 * ditulis, atau sidecar sudah ada untuk video ini.
 */
function extract_embedded_subtitles(string $videoPath, array &$stats, bool $changed = false): void
{
    static $ffmpegReady = null;

    if ($ffmpegReady === null) {
        $ffmpegReady = trim((string) @shell_exec('command -v ffmpeg 2>/dev/null')) !== ''
            && trim((string) @shell_exec('command -v ffprobe 2>/dev/null')) !== '';
    }
    if (!$ffmpegReady) {
        return;
    }

    $dir        = dirname($videoPath);
    $base       = pathinfo($videoPath, PATHINFO_FILENAME);
    $videoMtime = (int) @filemtime($videoPath);
    $store      = extracted_subtitles_dir();
    $key        = extracted_subtitles_key($videoPath) . '.';

    // Subtitle sudah tersedia kalau ada sidecar sefolder video atau hasil
    // ekstraksi di storage. Yang dipakai hanya yang masih segar — srt lama
    // yang lebih tua dari videonya (hasil ekstraksi dari file belum lengkap)
    // dianggap basi supaya diekstrak ulang.
    $hasSidecar = false;
    $hasFresh   = false;

    $consider = static function (string $path) use (&$hasSidecar, &$hasFresh, $videoMtime): void {
        $hasSidecar = true;
        if ($videoMtime <= 0 || (int) @filemtime($path) >= $videoMtime) {
            $hasFresh = true;
        }
    };

    foreach (@scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (!in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), subtitle_extensions(), true)) {
            continue;
        }
        $name = pathinfo($entry, PATHINFO_FILENAME);
        if ($name === $base || str_starts_with($name, $base . '.')) {
            $consider($dir . '/' . $entry);
        }
    }

    if (!is_dir($store)) {
        @mkdir($store, 0775, true);
    }

    foreach (@scandir($store) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || !str_starts_with($entry, $key)) {
            continue;
        }
        if (!in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), subtitle_extensions(), true)) {
            continue;
        }
        $name = substr(pathinfo($entry, PATHINFO_FILENAME), strlen($key));
        if ($name === $base || str_starts_with($name, $base . '.')) {
            $consider($store . '/' . $entry);
        }
    }

    if ($hasSidecar && $hasFresh) {
        return;
    }

    if (!is_dir($store)) {
        $stats['warnings'][] = basename($videoPath)
            . ': folder subtitle aplikasi tidak bisa dibuat (' . $store . ')';
        return;
    }

    // Upload (scp/rsync) yang masih berjalan membuat file belum lengkap, dan
    // ffmpeg akan gagal — atau menghasilkan .srt terpotong — di dalamnya.
    // Tunda sampai file benar-benar selesai ditulis.
    if ($videoMtime > 0 && (time() - $videoMtime) < 120) {
        $stats['warnings'][] = basename($videoPath)
            . ': file baru saja ditulis — ekstraksi subtitle ditunda, jalankan scan ulang sebentar lagi';
        return;
    }

    $probe = (string) @shell_exec(
        'ffprobe -v error -select_streams s -show_entries stream=index:stream_tags=language -of csv=p=0 '
        . escapeshellarg($videoPath) . ' 2>/dev/null'
    );

    $lines = array_values(array_filter(explode("\n", trim($probe)), static fn ($l) => $l !== ''));

    if ($lines === []) {
        if ($changed) {
            $stats['warnings'][] = basename($videoPath)
                . ': tidak ada track subtitle embedded — taruh file .srt sefolder dengan video kalau ada';
        }
        return;
    }

    // Sidecar yang sudah basi boleh ditimpa; yang masih segar dibedakan namanya.
    $fresh = static function (string $path) use ($videoMtime): bool {
        return is_file($path) && ($videoMtime <= 0 || (int) @filemtime($path) >= $videoMtime);
    };

    $used = [];
    foreach ($lines as $i => $line) {
        $cols   = explode(',', $line);
        $lang   = isset($cols[1]) && preg_match('/^[a-z]{2,3}(-[a-z]{2})?$/i', $cols[1])
            ? strtolower($cols[1]) : '';
        $suffix = $lang !== '' ? '.' . $lang : ($i > 0 ? '.sub' . $i : '');
        $out    = $store . '/' . $key . $base . $suffix . '.srt';

        // Beberapa track bisa ber-label bahasa yang sama (semuanya "ind"),
        // jadi namanya dibedakan: .ind.srt, .ind2.srt, .ind3.srt …
        if (isset($used[$out]) || $fresh($out)) {
            $n   = 2;
            $alt = $store . '/' . $key . $base . $suffix . $n . '.srt';
            while (isset($used[$alt]) || $fresh($alt)) {
                $n++;
                $alt = $store . '/' . $key . $base . $suffix . $n . '.srt';
            }
            $out = $alt;
        }
        $used[$out] = true;

        // Ekstrak ke file sementara dulu. ffmpeg bisa sukses menulis .srt
        // parsial dari video yang belum selesai di-upload, dan kalau langsung
        // jadi file final dia tidak akan pernah diekstrak ulang.
        $tmp = $out . '.part';
        @unlink($tmp);
        $err = (string) @shell_exec(
            'ffmpeg -y -v warning -i ' . escapeshellarg($videoPath)
            . ' -map 0:s:' . $i . ' -c:s srt -f srt ' . escapeshellarg($tmp) . ' 2>&1 1>/dev/null'
        );

        $size = is_file($tmp) ? (int) @filesize($tmp) : 0;
        $cut  = preg_match('/ended prematurely|file ended/i', $err) === 1;

        if ($size === 0 || $cut) {
            @unlink($tmp);
            $stats['warnings'][] = basename($videoPath) . ($cut
                ? ': file belum selesai di-upload (berakhir sebelum waktunya) — '
                    . 'ekstraksi ditunda, jalankan scan ulang setelah upload selesai'
                : ': track subtitle embedded gagal diekstrak');
            if ($cut) {
                return;
            }
            continue;
        }

        @rename($tmp, $out);
    }
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
            purge_extracted_subtitles((string) $row['p']);
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
 * "Show.S01E02.The.Pilot.1080p.mkv"  "The Pilot"
 */
function parse_episode_title(string $path, int $seasonNo, int $epNo): string
{
    $base = pathinfo($path, PATHINFO_FILENAME);

    // (?!\d) stops E01 from backtracking to E0 and leaving a stray "1" behind.
    if (preg_match('/S\d{1,2}[\s._-]*E\d{1,3}(?!\d)/i', $base, $m, PREG_OFFSET_CAPTURE)) {
        $after = substr($base, $m[0][1] + strlen($m[0][0]));
        $title = preg_replace('/^[\s._-]+/', '', $after) ?? '';
        $title = str_replace(['.', '_'], ' ', $title);
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;
        $title = trim($title, ' -._');

        $tokens = '1080p|720p|2160p|480p|4k|web-?dl|webrip|bluray|x264|x265|h264|h265|hevc|aac|dts';
        $title  = preg_replace('/\s+(' . $tokens . ')(\s+|$)/i', ' ', $title) ?? $title;
        $title  = trim($title);

        if ($title !== '' && !preg_match('/^\d+$/', $title)) {
            return mb_substr($title, 0, 255);
        }
    }

    // Fallback for "Show - 01 [Tag]" style names: title sits before the episode number.
    if (preg_match('/^(.*?)[\s._-]+(\d{1,3})(?:\s*(?:END|FINAL|OVA|SP))?(?:\s*(?:\[.*\]|\(.*\)))?\s*$/i', $base, $m)) {
        $title = preg_replace('/^\[.*?\]\s*/', '', $m[1]) ?? $m[1];
        $title = str_replace(['.', '_'], ' ', $title);
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title, ' -._');
        if ($title !== '' && !preg_match('/^\d+$/', $title)) {
            return mb_substr($title, 0, 255);
        }
    }

    return 'Episode ' . $epNo;
}
