<?php
declare(strict_types=1);

/**
 * Video streaming with HTTP Range support (seek in the browser),
 * sidecar subtitle delivery (SRT auto-converted to VTT), and posters.
 */

function stream_movie(int $id): void
{
    require_user();

    $stmt = db()->prepare('SELECT id, title, file_path FROM movies WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Film tidak ditemukan.';
        exit;
    }

    serve_video((string) $row['file_path'], (string) $row['title']);
}

function stream_episode(int $id): void
{
    require_user();

    $stmt = db()->prepare(
        'SELECT e.id, e.title, e.episode_no, e.file_path, s.title AS series_title, se.season_no
         FROM episodes e
         JOIN series s ON s.id = e.series_id
         JOIN seasons se ON se.id = e.season_id
         WHERE e.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Episode tidak ditemukan.';
        exit;
    }

    $label = $row['series_title'] . ' S' . str_pad((string) $row['season_no'], 2, '0', STR_PAD_LEFT)
        . 'E' . str_pad((string) $row['episode_no'], 2, '0', STR_PAD_LEFT);

    serve_video((string) $row['file_path'], $label);
}

function serve_video(string $path, string $label): void
{
    if (!is_file($path) || !is_readable($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'File video tidak dapat diakses di server.';
        exit;
    }

    $size = (int) filesize($path);
    $mime = media_mime(pathinfo($path, PATHINFO_EXTENSION));

    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . addcslashes(basename($path), '"\\') . '"');

    $start   = 0;
    $end     = $size - 1;
    $partial = false;

    $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
    if ($range !== '' && $size > 0) {
        if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)) {
            if ($m[1] === '' && $m[2] !== '') {
                $suffix = (int) $m[2];
                $start  = max(0, $size - $suffix);
                $end    = $size - 1;
            } else {
                $start = (int) $m[1];
                $end   = $m[2] !== '' ? min((int) $m[2], $size - 1) : $size - 1;
            }

            if ($start <= $end && $start < $size) {
                $partial = true;
            } else {
                header('Content-Range: bytes */' . $size);
                http_response_code(416);
                exit;
            }
        }
    }

    $length = $partial ? $end - $start + 1 : $size;

    if ($partial) {
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    header('Content-Length: ' . $length);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        exit;
    }

    $fh = fopen($path, 'rb');
    if ($fh === false) {
        http_response_code(500);
        exit;
    }

    if ($start > 0) {
        fseek($fh, $start);
    }

    $remaining = $length;
    while ($remaining > 0 && !feof($fh) && !connection_aborted()) {
        $chunk = fread($fh, min(262144, $remaining));
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    fclose($fh);
    exit;
}

/* -------------------------------------------------------------------------
 * Subtitles (sidecars next to the video file)
 * ---------------------------------------------------------------------- */

/**
 * Subtitle files that belong to a video: same basename first
 * (Film.srt / Film.en.srt / Film.id.vtt); if none, every subtitle in a
 * single-video folder.
 *
 * @return array<int, array{path: string, label: string, lang: string}>
 */
function find_subtitles(string $videoPath): array
{
    $dir   = dirname($videoPath);
    $base  = pathinfo($videoPath, PATHINFO_FILENAME);
    $exts  = subtitle_extensions();
    $store = extracted_subtitles_dir();
    $key   = extracted_subtitles_key($videoPath) . '.';

    $all = [];
    foreach (@scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (in_array($ext, $exts, true)) {
            $all[] = $dir . '/' . $entry;
        }
    }

    $matched = [];
    foreach ($all as $path) {
        $name = pathinfo($path, PATHINFO_FILENAME);
        if ($name === $base || str_starts_with($name, $base . '.')) {
            $matched[] = $path;
        }
    }

    if ($matched === [] && $all !== []) {
        // Only fall back to "all subs in folder" when the folder holds one video.
        $videoExt   = video_extensions();
        $videoCount = 0;
        foreach (@scandir($dir) ?: [] as $entry) {
            if (is_file($dir . '/' . $entry)
                && in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), $videoExt, true)) {
                $videoCount++;
            }
        }
        if ($videoCount === 1) {
            $matched = $all;
        }
    }

    // Hasil ekstraksi disimpan di storage/subtitles/<key>.<nama>.srt.
    foreach (@scandir($store) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || !str_starts_with($entry, $key)) {
            continue;
        }
        if (!in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), $exts, true)) {
            continue;
        }
        $name = substr(pathinfo($entry, PATHINFO_FILENAME), strlen($key));
        if ($name === $base || str_starts_with($name, $base . '.')) {
            $matched[] = $store . '/' . $entry;
        }
    }

    if ($matched === []) {
        return [];
    }

    // Nama yang sama bisa muncul di dua tempat (srt lama di folder video dan
    // ekstraksi baru di storage) — tampilkan yang paling baru saja.
    $newest = [];
    foreach ($matched as $path) {
        $name = pathinfo($path, PATHINFO_FILENAME);
        if (str_starts_with($name, $key)) {
            $name = substr($name, strlen($key));
        }
        $mtime = (int) @filemtime($path);
        if (!isset($newest[$name]) || $mtime > $newest[$name][1]) {
            $newest[$name] = [$path, $mtime];
        }
    }
    $matched = array_column($newest, 0);

    sort($matched);

    $out = [];
    foreach ($matched as $path) {
        $file = basename($path);
        if (str_starts_with($file, $key)) {
            $file = substr($file, strlen($key)); // buang prefix hash storage
        }

        $name  = pathinfo($file, PATHINFO_FILENAME);
        $lang  = 'id';
        $label = 'Subtitle';

        $suffix = $name;
        if (str_starts_with($suffix, $base)) {
            $suffix = trim(substr($suffix, strlen($base)), '.');
        }

        if ($suffix !== '' && preg_match('/^[a-z]{2,3}(-[a-z]{2})?$/i', $suffix, $m)) {
            $lang  = strtolower(str_replace('_', '-', $m[0]));
            $label = strtoupper($lang) . ' — ' . $file;
        } else {
            $label = $file;
        }

        $out[] = ['path' => $path, 'label' => $label, 'lang' => $lang];
    }

    return $out;
}

function serve_subtitle(string $path): void
{
    require_user();

    $real = realpath($path);
    if ($real === false || !is_file($real) || !is_readable($real)) {
        http_response_code(404);
        echo 'Subtitle tidak ditemukan.';
        exit;
    }

    $content = (string) file_get_contents($real);

    // Strip UTF-8 BOM.
    if (str_starts_with($content, "\xEF\xBB\xBF")) {
        $content = substr($content, 3);
    }

    if (strtolower(pathinfo($real, PATHINFO_EXTENSION)) === 'srt') {
        $content = srt_to_vtt($content);
    } elseif (!str_starts_with(ltrim($content), 'WEBVTT')) {
        $content = "WEBVTT\n\n" . $content;
    }

    header('Content-Type: text/vtt; charset=utf-8');
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

function srt_to_vtt(string $srt): string
{
    $srt = str_replace(["\r\n", "\r"], "\n", $srt);
    $srt = preg_replace('/^\s*\d+\s*\n/', '', $srt) ?? $srt; // drop first index line if present
    // Replace SRT decimal commas with dots (timestamps only).
    $srt = preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $srt) ?? $srt;

    return "WEBVTT\n\n" . trim($srt) . "\n";
}

/* -------------------------------------------------------------------------
 * Posters
 * ---------------------------------------------------------------------- */

function poster_uploaded(string $basename): void
{
    require_user();

    if (!preg_match('/^m\d+-[0-9a-f]+\.img$/', $basename)) {
        http_response_code(404);
        exit;
    }

    $path = storage_path('covers/' . $basename);
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }

    serve_image($path);
}

function poster_folder(string $hash): void
{
    require_user();

    if (!preg_match('/^[0-9a-f]{40}$/', $hash)) {
        http_response_code(404);
        exit;
    }

    $stmt = db()->prepare(
        'SELECT folder_poster FROM movies WHERE SHA1(folder_poster) = ? LIMIT 1'
    );
    $stmt->execute([$hash]);
    $path = $stmt->fetchColumn();

    if (!$path) {
        $stmt = db()->prepare(
            'SELECT folder_poster FROM series WHERE SHA1(folder_poster) = ? LIMIT 1'
        );
        $stmt->execute([$hash]);
        $path = $stmt->fetchColumn();
    }

    if (!$path || !is_string($path)) {
        http_response_code(404);
        exit;
    }

    // The path must live under a configured media root.
    $real = realpath($path);
    if ($real === false || !is_file($real)) {
        http_response_code(404);
        exit;
    }

    $ok = false;
    foreach ([movie_root(), series_root()] as $root) {
        $rootReal = realpath($root);
        if ($rootReal !== false && str_starts_with($real, $rootReal . '/')) {
            $ok = true;
            break;
        }
    }

    if (!$ok) {
        http_response_code(404);
        exit;
    }

    serve_image($real);
}

function serve_image(string $path): void
{
    $info = @getimagesize($path);
    $mime = $info !== false ? $info['mime'] : 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . (int) filesize($path));
    readfile($path);
    exit;
}

function media_mime(string $ext): string
{
    return match (strtolower($ext)) {
        'mp4', 'm4v' => 'video/mp4',
        'webm'       => 'video/webm',
        'mkv'        => 'video/x-matroska',
        'mov'        => 'video/quicktime',
        'avi'        => 'video/x-msvideo',
        'ts'         => 'video/mp2t',
        'flv'        => 'video/x-flv',
        default      => 'application/octet-stream',
    };
}
