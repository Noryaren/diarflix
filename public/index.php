<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/scanner.php';
require __DIR__ . '/../app/stream.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$uri    = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$base   = base_path();

if ($base !== '' && str_starts_with($uri, $base)) {
    $uri = substr($uri, strlen($base));
}

$uri = '/' . ltrim($uri, '/');

try {
    if ($uri === '/api' || str_starts_with($uri, '/api/')) {
        require __DIR__ . '/../app/api.php';
        api_dispatch($method, substr($uri, 4) ?: '/');
        exit;
    }

    if ($method === 'GET' || $method === 'HEAD') {
        if (preg_match('#^/stream/m/(\d+)$#', $uri, $matches)) {
            stream_movie((int) $matches[1]);
        }

        if (preg_match('#^/stream/e/(\d+)$#', $uri, $matches)) {
            stream_episode((int) $matches[1]);
        }

        if (preg_match('#^/subtitle/m/(\d+)/(\d+)$#', $uri, $matches)) {
            serve_movie_subtitle((int) $matches[1], (int) $matches[2]);
        }

        if (preg_match('#^/subtitle/e/(\d+)/(\d+)$#', $uri, $matches)) {
            serve_episode_subtitle((int) $matches[1], (int) $matches[2]);
        }

        if (preg_match('#^/poster/up/([A-Za-z0-9._-]+)$#', $uri, $matches)) {
            poster_uploaded($matches[1]);
        }

        if (preg_match('#^/poster/f/([0-9a-f]{40})\.jpg$#', $uri, $matches)) {
            poster_folder($matches[1]);
        }
    }

    if ($method !== 'GET' && $method !== 'HEAD') {
        http_response_code(405);
        header('Allow: GET, HEAD');
        exit;
    }

    if (PHP_SAPI === 'cli-server') {
        $file = __DIR__ . $uri;
        if ($uri !== '/' && is_file($file)) {
            return false;
        }
    }

    require __DIR__ . '/../app/views/shell.php';
} catch (ApiException $e) {
    http_response_code($e->status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
    exit;
} catch (Throwable $e) {
    error_log('diarflix: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Terjadi kesalahan di server.';
    exit;
}

/** Resolve the Nth sidecar subtitle of a movie and serve it as VTT. */
function serve_movie_subtitle(int $movieId, int $index): void
{
    $stmt = db()->prepare('SELECT file_path FROM movies WHERE id = ? LIMIT 1');
    $stmt->execute([$movieId]);
    $path = $stmt->fetchColumn();

    if (!$path || !is_string($path)) {
        http_response_code(404);
        echo 'Film tidak ditemukan.';
        exit;
    }

    $subs = find_subtitles($path);
    if (!isset($subs[$index])) {
        http_response_code(404);
        echo 'Subtitle tidak ditemukan.';
        exit;
    }

    serve_subtitle($subs[$index]['path']);
}

/** Resolve the Nth sidecar subtitle of an episode and serve it as VTT. */
function serve_episode_subtitle(int $episodeId, int $index): void
{
    $stmt = db()->prepare('SELECT file_path FROM episodes WHERE id = ? LIMIT 1');
    $stmt->execute([$episodeId]);
    $path = $stmt->fetchColumn();

    if (!$path || !is_string($path)) {
        http_response_code(404);
        echo 'Episode tidak ditemukan.';
        exit;
    }

    $subs = find_subtitles($path);
    if (!isset($subs[$index])) {
        http_response_code(404);
        echo 'Subtitle tidak ditemukan.';
        exit;
    }

    serve_subtitle($subs[$index]['path']);
}
