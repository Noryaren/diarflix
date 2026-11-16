<?php
declare(strict_types=1);

function config(?string $key = null, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        $config = [
            'app' => [
                'name'         => getenv('APP_NAME') ?: 'Diarflix',
                'debug'        => filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOL),
                'timezone'     => 'UTC',
                'base_path'    => null,
                'trust_proxy'  => filter_var(getenv('TRUST_PROXY') ?: '1', FILTER_VALIDATE_BOOL),
                'token_ttl'    => 31536000,
            ],
            'db' => [
                'dsn'  => getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;port=3307;dbname=diarflix;charset=utf8mb4',
                'user' => getenv('DB_USER') ?: 'diarflix',
                'pass' => getenv('DB_PASS') ?: '',
            ],
            'media' => [
                'movie_path'          => getenv('MOVIE_PATH') ?: '/diarflix/movie',
                'series_path'         => getenv('SERIES_PATH') ?: '/diarflix/series',
                'video_extensions'    => ['mp4', 'mkv', 'webm', 'm4v', 'mov', 'avi', 'ts'],
                'subtitle_extensions' => ['srt', 'vtt'],
                'folder_posters'      => ['poster.jpg', 'poster.png', 'cover.jpg', 'cover.png', 'folder.jpg', 'front.jpg', 'movie.jpg'],
            ],
        ];

        $file = ROOT_PATH . '/config/config.php';
        if (is_file($file)) {
            $local = require $file;
            if (is_array($local)) {
                $config = array_replace_recursive($config, $local);
            }
        }
    }

    if ($key === null) {
        return $config;
    }

    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }

    return $value;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(): string
{
    static $base = null;

    if ($base !== null) {
        return $base;
    }

    $configured = config('app.base_path');
    if ($configured !== null) {
        $base = rtrim('/' . trim((string) $configured, '/'), '/');
        if ($base === '/') {
            $base = '';
        }
        return $base;
    }

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    if (PHP_SAPI === 'cli-server' || $script === '' || !str_ends_with($script, '.php')) {
        return '';
    }

    $dir  = str_replace('\\', '/', dirname($script));
    $base = ($dir === '/' || $dir === '.' || $dir === '') ? '' : rtrim($dir, '/');

    return $base;
}

function url(string $path = ''): string
{
    $path = '/' . ltrim($path, '/');
    return base_path() . ($path === '/' ? '/' : $path);
}

function csp_nonce(): string
{
    if (empty($GLOBALS['diarflix_nonce'])) {
        $GLOBALS['diarflix_nonce'] = base64_encode(random_bytes(18));
    }
    return $GLOBALS['diarflix_nonce'];
}

function like_term(string $term): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
}

function clean_search_term(mixed $value): string
{
    return mb_substr(trim((string) $value), 0, 120);
}

/**
 * <video> and <img> cannot send an Authorization header, so media URLs carry
 * the caller's own bearer token as a "t" query parameter instead.
 */
function with_media_token(string $path): string
{
    $token = function_exists('auth_token') ? auth_token() : null;

    if ($token === null || $token === '') {
        return $path;
    }

    return $path . (str_contains($path, '?') ? '&' : '?') . 't=' . rawurlencode($token);
}

function storage_path(string $path = ''): string
{
    $base = ROOT_PATH . '/storage';
    return $path === '' ? $base : $base . '/' . ltrim($path, '/');
}

function movie_root(): string
{
    return rtrim((string) config('media.movie_path'), '/');
}

function series_root(): string
{
    return rtrim((string) config('media.series_path'), '/');
}

function video_extensions(): array
{
    $ext = config('media.video_extensions');
    return is_array($ext) ? array_map('strtolower', $ext) : [];
}

function subtitle_extensions(): array
{
    $ext = config('media.subtitle_extensions');
    return is_array($ext) ? array_map('strtolower', $ext) : [];
}

function movie_stream_url(int $movieId): string
{
    return with_media_token(url('/stream/m/' . $movieId));
}

function episode_stream_url(int $episodeId): string
{
    return with_media_token(url('/stream/e/' . $episodeId));
}

function poster_url(?string $poster, ?string $folderPoster): string
{
    if ($poster !== null && $poster !== '') {
        // Direct link (mis. https://drive.diaraji.my.id/share/...) dipakai apa adanya.
        if (preg_match('#^https?://#i', $poster)) {
            return $poster;
        }
        return with_media_token(url('/poster/up/' . rawurlencode(basename($poster))));
    }
    if ($folderPoster !== null && $folderPoster !== '') {
        return with_media_token(url('/poster/f/' . rawurlencode(hash('sha1', $folderPoster)) . '.jpg'));
    }
    return '';
}

function upload_poster_key(int $id): string
{
    return 'm' . $id . '-' . bin2hex(random_bytes(4)) . '.img';
}
