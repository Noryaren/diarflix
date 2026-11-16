<?php
declare(strict_types=1);

/**
 * JSON API used by the single-page player.
 */

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_input(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || $raw === '') {
        return [];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        throw new ApiException('Body JSON tidak valid.', 400);
    }

    return $data;
}

/* -------------------------------------------------------------------------
 * DTOs
 * ---------------------------------------------------------------------- */

function dto_movie(array $row): array
{
    return [
        'id'        => (int) $row['id'],
        'type'      => 'movie',
        'title'     => (string) $row['title'],
        'year'      => $row['year'] !== null ? (int) $row['year'] : null,
        'overview'  => $row['overview'] ?? null,
        'genres'    => (string) ($row['genres'] ?? ''),
        'poster'    => poster_url($row['poster'] ?? null, $row['folder_poster'] ?? null),
        'added_at'  => (string) ($row['added_at'] ?? ''),
        'runtime_ms'=> (int) ($row['runtime_ms'] ?? 0),
    ];
}

function dto_series(array $row): array
{
    return [
        'id'       => (int) $row['id'],
        'type'     => 'series',
        'title'    => (string) $row['title'],
        'year'     => $row['year'] !== null ? (int) $row['year'] : null,
        'overview' => $row['overview'] ?? null,
        'genres'   => (string) ($row['genres'] ?? ''),
        'poster'   => poster_url($row['poster'] ?? null, $row['folder_poster'] ?? null),
        'added_at' => (string) ($row['added_at'] ?? ''),
        'episode_count' => (int) ($row['episode_count'] ?? 0),
    ];
}

/* -------------------------------------------------------------------------
 * Auth
 * ---------------------------------------------------------------------- */

function api_health(): void
{
    $dbOk = false;
    try {
        db()->query('SELECT 1');
        $dbOk = true;
    } catch (Throwable) {
        $dbOk = false;
    }

    json_response([
        'ok'      => $dbOk,
        'name'    => 'diarflix',
        'version' => '1.0.0',
        'db'      => $dbOk,
    ]);
}

function secret_broken_guard(): void
{
    if (jwt_secret_broken()) {
        json_response([
            'ok'    => false,
            'error' => 'Konfigurasi login rusak: storage/jwt.secret tidak bisa dibaca oleh '
                . 'user web (www-data), jadi token tidak pernah valid. Perbaiki dengan: '
                . 'sudo chown -R www-data:www-data /home/diarflix/storage',
        ], 500);
    }
}

function api_login(): void
{
    secret_broken_guard();

    $input = json_input();
    $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
    $pass  = (string) ($input['password'] ?? '');

    $token = attempt_login($email, $pass);

    if ($token === null) {
        json_response(['ok' => false, 'error' => 'Email atau password salah.'], 401);
    }

    json_response(['ok' => true, 'token' => $token, 'user' => current_user()]);
}

function api_register(): void
{
    secret_broken_guard();

    $input = json_input();
    $name  = mb_substr(trim((string) ($input['name'] ?? '')), 0, 80);
    $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
    $pass  = (string) ($input['password'] ?? '');

    if ($name === '') {
        throw new ApiException('Nama wajib diisi.', 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ApiException('Email tidak valid.', 422);
    }
    if (strlen($pass) < 6) {
        throw new ApiException('Password minimal 6 karakter.', 422);
    }

    $exists = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $exists->execute([$email]);
    if ($exists->fetch()) {
        throw new ApiException('Email sudah terdaftar.', 409);
    }

    // First account ever becomes admin (safety net if seeding was skipped).
    $hasUsers = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    $admin    = $hasUsers ? 0 : 1;

    $stmt = db()->prepare(
        'INSERT INTO users (name, email, password_hash, is_admin, created_at) VALUES (?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$name, mb_substr($email, 0, 190), password_hash($pass, PASSWORD_DEFAULT), $admin]);

    $token = login_user((int) db()->lastInsertId());
    json_response(['ok' => true, 'token' => $token, 'user' => current_user()], 201);
}

function api_logout(): void
{
    json_response(['ok' => true]);
}

function api_me(): void
{
    json_response(['ok' => true, 'user' => require_user()]);
}

function api_change_password(): void
{
    $user = require_user();
    $input = json_input();

    $current = (string) ($input['current_password'] ?? '');
    $new     = (string) ($input['new_password'] ?? '');

    if (strlen($new) < 6) {
        throw new ApiException('Password baru minimal 6 karakter.', 422);
    }

    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $hash = (string) $stmt->fetchColumn();

    if (!password_verify($current, $hash)) {
        throw new ApiException('Password saat ini salah.', 403);
    }

    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);

    json_response(['ok' => true]);
}

/* -------------------------------------------------------------------------
 * Home / browse / search
 * ---------------------------------------------------------------------- */

function api_home(): void
{
    $user = require_user();
    $uid  = $user['id'];

    // Continue watching (unfinished, most recent first).
    $continue = [];

    $rows = db()->prepare(
        "SELECT p.item_type, p.item_id, p.position_ms, p.duration_ms, p.updated_at,
                m.title, m.year, m.overview, m.genres, m.poster, m.folder_poster
         FROM watch_progress p
         JOIN movies m ON p.item_type = 'movie' AND m.id = p.item_id
         WHERE p.user_id = ? AND p.finished = 0 AND p.position_ms > 10000
         ORDER BY p.updated_at DESC
         LIMIT 12"
    );
    $rows->execute([$uid]);
    foreach ($rows as $row) {
        $continue[] = progress_dto($row, 'movie', (int) $row['item_id']);
    }

    $rows = db()->prepare(
        "SELECT p.item_type, p.item_id, p.position_ms, p.duration_ms, p.updated_at,
                e.title AS ep_title, e.episode_no, se.season_no,
                s.title AS series_title, s.poster, s.folder_poster, s.overview, s.genres
         FROM watch_progress p
         JOIN episodes e ON p.item_type = 'episode' AND e.id = p.item_id
         JOIN series s ON s.id = e.series_id
         JOIN seasons se ON se.id = e.season_id
         WHERE p.user_id = ? AND p.finished = 0 AND p.position_ms > 10000
         ORDER BY p.updated_at DESC
         LIMIT 12"
    );
    $rows->execute([$uid]);
    foreach ($rows as $row) {
        $dto = progress_dto($row, 'episode', (int) $row['item_id']);
        $dto['subtitle'] = $row['series_title'] . ' · S'
            . str_pad((string) $row['season_no'], 2, '0', STR_PAD_LEFT)
            . 'E' . str_pad((string) $row['episode_no'], 2, '0', STR_PAD_LEFT)
            . ($row['ep_title'] !== '' ? ' · ' . $row['ep_title'] : '');
        $dto['series_id'] = series_id_of_episode((int) $row['item_id']);
        $continue[] = $dto;
    }

    usort($continue, static fn ($a, $b) => strcmp($b['updated_at'], $a['updated_at']));
    $continue = array_slice($continue, 0, 12);

    $movies = db()->query(
        'SELECT id, title, year, overview, genres, poster, folder_poster, added_at, runtime_ms
         FROM movies ORDER BY added_at DESC, id DESC LIMIT 24'
    )->fetchAll();

    $series = db()->query(
        'SELECT s.id, s.title, s.year, s.overview, s.genres, s.poster, s.folder_poster, s.added_at,
                (SELECT COUNT(*) FROM episodes e WHERE e.series_id = s.id) AS episode_count
         FROM series s ORDER BY s.added_at DESC, s.id DESC LIMIT 24'
    )->fetchAll();

    $latestEpisodes = db()->query(
        "SELECT e.id, e.title AS ep_title, e.episode_no, e.added_at,
                se.season_no, s.id AS series_id, s.title AS series_title, s.poster, s.folder_poster
         FROM episodes e
         JOIN seasons se ON se.id = e.season_id
         JOIN series s ON s.id = e.series_id
         ORDER BY e.added_at DESC, e.id DESC
         LIMIT 16"
    )->fetchAll();

    $latest = [];
    foreach ($latestEpisodes as $row) {
        $latest[] = [
            'episode_id'  => (int) $row['id'],
            'series_id'   => (int) $row['series_id'],
            'series_title'=> (string) $row['series_title'],
            'season_no'   => (int) $row['season_no'],
            'episode_no'  => (int) $row['episode_no'],
            'ep_title'    => (string) $row['ep_title'],
            'poster'      => poster_url($row['poster'] ?? null, $row['folder_poster'] ?? null),
            'label'       => 'S' . str_pad((string) $row['season_no'], 2, '0', STR_PAD_LEFT)
                . 'E' . str_pad((string) $row['episode_no'], 2, '0', STR_PAD_LEFT),
        ];
    }

    json_response([
        'ok'       => true,
        'continue' => $continue,
        'movies'   => array_map('dto_movie', $movies),
        'series'   => array_map('dto_series', $series),
        'latest_episodes' => $latest,
    ]);
}

function progress_dto(array $row, string $type, int $id): array
{
    $duration = (int) $row['duration_ms'];
    $position = (int) $row['position_ms'];

    return [
        'type'        => $type,
        'id'          => $id,
        'title'       => (string) ($row['title'] ?? $row['series_title'] ?? ''),
        'year'        => isset($row['year']) && $row['year'] !== null ? (int) $row['year'] : null,
        'overview'    => $row['overview'] ?? null,
        'genres'      => (string) ($row['genres'] ?? ''),
        'poster'      => poster_url($row['poster'] ?? null, $row['folder_poster'] ?? null),
        'position_ms' => $position,
        'duration_ms' => $duration,
        'progress'    => $duration > 0 ? min(100, (int) round($position / $duration * 100)) : 0,
        'subtitle'    => (string) ($row['subtitle'] ?? ''),
        'updated_at'  => (string) ($row['updated_at'] ?? ''),
    ];
}

function series_id_of_episode(int $episodeId): int
{
    $stmt = db()->prepare('SELECT series_id FROM episodes WHERE id = ?');
    $stmt->execute([$episodeId]);
    return (int) $stmt->fetchColumn();
}

function api_movies(): void
{
    require_user();

    $limit  = max(1, min(200, (int) ($_GET['limit'] ?? 60)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));

    $stmt = db()->prepare(
        'SELECT id, title, year, overview, genres, poster, folder_poster, added_at, runtime_ms
         FROM movies ORDER BY title COLLATE utf8mb4_unicode_ci ASC
         LIMIT ? OFFSET ?'
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();

    $total = (int) db()->query('SELECT COUNT(*) FROM movies')->fetchColumn();

    json_response([
        'ok'    => true,
        'items' => array_map('dto_movie', $stmt->fetchAll()),
        'total' => $total,
    ]);
}

function api_series_list(): void
{
    require_user();

    $stmt = db()->query(
        'SELECT s.id, s.title, s.year, s.overview, s.genres, s.poster, s.folder_poster, s.added_at,
                (SELECT COUNT(*) FROM episodes e WHERE e.series_id = s.id) AS episode_count
         FROM series s ORDER BY s.title COLLATE utf8mb4_unicode_ci ASC'
    );

    json_response(['ok' => true, 'items' => array_map('dto_series', $stmt->fetchAll())]);
}

function api_movie_detail(int $id): void
{
    require_user();

    $stmt = db()->prepare(
        'SELECT id, title, year, overview, genres, poster, folder_poster, added_at, runtime_ms, file_path
         FROM movies WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        throw new ApiException('Film tidak ditemukan.', 404);
    }

    $dto        = dto_movie($row);
    $dto['url'] = movie_stream_url($id);

    $subs = [];
    foreach (find_subtitles((string) $row['file_path']) as $i => $sub) {
        $subs[] = [
            'label' => $sub['label'],
            'lang'  => $sub['lang'],
            'url'   => with_media_token(url('/subtitle/m/' . $id . '/' . $i)),
        ];
    }
    $dto['subtitles'] = $subs;
    $dto['progress']  = user_progress('movie', $id);

    json_response(['ok' => true, 'movie' => $dto]);
}

function api_series_detail(int $id): void
{
    $user = require_user();

    $stmt = db()->prepare(
        'SELECT id, title, year, overview, genres, poster, folder_poster, added_at
         FROM series WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        throw new ApiException('Serial tidak ditemukan.', 404);
    }

    $dto = dto_series($row);

    $seasons = db()->prepare(
        'SELECT id, season_no, name FROM seasons WHERE series_id = ? ORDER BY season_no'
    );
    $seasons->execute([$id]);

    $episodes = db()->prepare(
        'SELECT e.id, e.title, e.episode_no, e.duration_ms, e.overview, se.season_no,
                p.position_ms AS p_position, p.duration_ms AS p_duration, p.finished AS p_finished
         FROM episodes e
         JOIN seasons se ON se.id = e.season_id
         LEFT JOIN watch_progress p ON p.item_type = \'episode\' AND p.item_id = e.id AND p.user_id = ?
         WHERE e.series_id = ?
         ORDER BY se.season_no, e.episode_no'
    );
    $episodes->execute([$user['id'], $id]);
    $epRows = $episodes->fetchAll();

    $out = [];
    foreach ($seasons as $season) {
        $eps = [];
        foreach ($epRows as $ep) {
            if ((int) $ep['season_no'] !== (int) $season['season_no']) {
                continue;
            }
            $duration = (int) $ep['p_duration'] ?: (int) $ep['duration_ms'];
            $position = (int) $ep['p_position'];
            $eps[] = [
                'id'          => (int) $ep['id'],
                'episode_no'  => (int) $ep['episode_no'],
                'title'       => (string) ($ep['title'] ?: 'Episode ' . $ep['episode_no']),
                'overview'    => $ep['overview'],
                'duration_ms' => (int) $ep['duration_ms'],
                'position_ms' => $position,
                'progress'    => $duration > 0 ? min(100, (int) round($position / $duration * 100)) : 0,
                'finished'    => (int) $ep['p_finished'] === 1,
                'url'         => episode_stream_url((int) $ep['id']),
            ];
        }
        $out[] = [
            'id'        => (int) $season['id'],
            'season_no' => (int) $season['season_no'],
            'name'      => (string) $season['name'],
            'episodes'  => $eps,
        ];
    }

    $dto['seasons']   = $out;
    $dto['subtitles'] = []; // resolved per episode at detail time would be heavy; client asks /api/episode/{id}

    json_response(['ok' => true, 'series' => $dto]);
}

function api_episode_detail(int $id): void
{
    require_user();

    $stmt = db()->prepare(
        'SELECT e.id, e.title, e.episode_no, e.file_path, e.duration_ms,
                se.season_no, s.id AS series_id, s.title AS series_title
         FROM episodes e
         JOIN seasons se ON se.id = e.season_id
         JOIN series s ON s.id = e.series_id
         WHERE e.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        throw new ApiException('Episode tidak ditemukan.', 404);
    }

    $subs = [];
    foreach (find_subtitles((string) $row['file_path']) as $i => $sub) {
        $subs[] = [
            'label' => $sub['label'],
            'lang'  => $sub['lang'],
            'url'   => with_media_token(url('/subtitle/e/' . $id . '/' . $i)),
        ];
    }

    json_response([
        'ok'       => true,
        'episode'  => [
            'id'           => (int) $row['id'],
            'series_id'    => (int) $row['series_id'],
            'series_title' => (string) $row['series_title'],
            'season_no'    => (int) $row['season_no'],
            'episode_no'   => (int) $row['episode_no'],
            'title'        => (string) $row['title'],
            'duration_ms'  => (int) $row['duration_ms'],
            'url'          => episode_stream_url($id),
            'subtitles'    => $subs,
            'progress'     => user_progress('episode', $id),
        ],
    ]);
}

function user_progress(string $type, int $itemId): ?array
{
    $user = current_user();
    if ($user === null) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT position_ms, duration_ms, finished, updated_at
         FROM watch_progress WHERE user_id = ? AND item_type = ? AND item_id = ? LIMIT 1'
    );
    $stmt->execute([$user['id'], $type, $itemId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $duration = (int) $row['duration_ms'];
    $position = (int) $row['position_ms'];

    return [
        'position_ms' => $position,
        'duration_ms' => $duration,
        'progress'    => $duration > 0 ? min(100, (int) round($position / $duration * 100)) : 0,
        'finished'    => (int) $row['finished'] === 1,
    ];
}

function api_search(): void
{
    require_user();

    $q = clean_search_term($_GET['q'] ?? '');
    if ($q === '') {
        json_response(['ok' => true, 'movies' => [], 'series' => [], 'episodes' => []]);
    }

    $like = like_term($q);

    $movies = db()->prepare(
        'SELECT id, title, year, overview, genres, poster, folder_poster, added_at, runtime_ms
         FROM movies WHERE title LIKE ? OR overview LIKE ?
         ORDER BY title LIMIT 24'
    );
    $movies->execute([$like, $like]);

    $series = db()->prepare(
        'SELECT s.id, s.title, s.year, s.overview, s.genres, s.poster, s.folder_poster, s.added_at,
                (SELECT COUNT(*) FROM episodes e WHERE e.series_id = s.id) AS episode_count
         FROM series s WHERE s.title LIKE ? OR s.overview LIKE ?
         ORDER BY s.title LIMIT 24'
    );
    $series->execute([$like, $like]);

    $episodes = db()->prepare(
        "SELECT e.id, e.title, e.episode_no, se.season_no, s.id AS series_id, s.title AS series_title,
                s.poster, s.folder_poster
         FROM episodes e
         JOIN seasons se ON se.id = e.season_id
         JOIN series s ON s.id = e.series_id
         WHERE e.title LIKE ? OR s.title LIKE ?
         ORDER BY s.title, se.season_no, e.episode_no
         LIMIT 24"
    );
    $episodes->execute([$like, $like]);

    $epOut = [];
    foreach ($episodes as $row) {
        $epOut[] = [
            'episode_id'   => (int) $row['id'],
            'series_id'    => (int) $row['series_id'],
            'series_title' => (string) $row['series_title'],
            'season_no'    => (int) $row['season_no'],
            'episode_no'   => (int) $row['episode_no'],
            'ep_title'     => (string) $row['title'],
            'poster'       => poster_url($row['poster'] ?? null, $row['folder_poster'] ?? null),
            'label'        => 'S' . str_pad((string) $row['season_no'], 2, '0', STR_PAD_LEFT)
                . 'E' . str_pad((string) $row['episode_no'], 2, '0', STR_PAD_LEFT),
        ];
    }

    json_response([
        'ok'       => true,
        'query'    => $q,
        'movies'   => array_map('dto_movie', $movies->fetchAll()),
        'series'   => array_map('dto_series', $series->fetchAll()),
        'episodes' => $epOut,
    ]);
}

/* -------------------------------------------------------------------------
 * Watch progress
 * ---------------------------------------------------------------------- */

function api_save_progress(): void
{
    $user  = require_user();
    $input = json_input();

    $type     = (string) ($input['item_type'] ?? '');
    $itemId   = (int) ($input['item_id'] ?? 0);
    $position = max(0, (int) ($input['position_ms'] ?? 0));
    $duration = max(0, (int) ($input['duration_ms'] ?? 0));

    if (!in_array($type, ['movie', 'episode'], true) || $itemId <= 0) {
        throw new ApiException('Progress tidak valid.', 422);
    }

    // Validate ownership of the item.
    if ($type === 'movie') {
        $stmt = db()->prepare('SELECT id FROM movies WHERE id = ?');
    } else {
        $stmt = db()->prepare('SELECT id FROM episodes WHERE id = ?');
    }
    $stmt->execute([$itemId]);
    if (!$stmt->fetch()) {
        throw new ApiException('Item tidak ditemukan.', 404);
    }

    $finished = ($duration > 0 && $position >= (int) ($duration * 0.9)) ? 1 : 0;

    $stmt = db()->prepare(
        'INSERT INTO watch_progress (user_id, item_type, item_id, position_ms, duration_ms, finished, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            position_ms = VALUES(position_ms),
            duration_ms = GREATEST(VALUES(duration_ms), duration_ms),
            finished = VALUES(finished),
            updated_at = NOW()'
    );
    $stmt->execute([$user['id'], $type, $itemId, $position, $duration, $finished]);

    // Learn movie runtime from the player (server has no ffmpeg).
    if ($type === 'movie' && $duration > 0) {
        db()->prepare('UPDATE movies SET runtime_ms = ? WHERE id = ? AND runtime_ms = 0')
            ->execute([$duration, $itemId]);
    } elseif ($type === 'episode' && $duration > 0) {
        db()->prepare('UPDATE episodes SET duration_ms = ? WHERE id = ? AND duration_ms = 0')
            ->execute([$duration, $itemId]);
    }

    json_response(['ok' => true, 'finished' => $finished === 1]);
}

function api_continue(): void
{
    $user = require_user();

    $stmt = db()->prepare(
        "SELECT item_type, item_id, position_ms, duration_ms, updated_at
         FROM watch_progress
         WHERE user_id = ? AND finished = 0 AND position_ms > 10000
         ORDER BY updated_at DESC LIMIT 20"
    );
    $stmt->execute([$user['id']]);

    $items = [];
    foreach ($stmt as $row) {
        $items[] = [
            'type'        => (string) $row['item_type'],
            'id'          => (int) $row['item_id'],
            'position_ms' => (int) $row['position_ms'],
            'duration_ms' => (int) $row['duration_ms'],
            'updated_at'  => (string) $row['updated_at'],
        ];
    }

    json_response(['ok' => true, 'items' => $items]);
}

/* -------------------------------------------------------------------------
 * Admin: library scan
 * ---------------------------------------------------------------------- */

function api_admin_scan(): void
{
    require_admin();

    $full = !empty(json_input()['full']);

    $php   = PHP_BINARY;
    $script = escapeshellarg(ROOT_PATH . '/bin/scan.php');
    $flags = $full ? ' --full' : '';

    $cmd = escapeshellarg($php) . ' ' . $script . $flags . ' 2>&1';

    $output = [];
    $code   = 0;
    exec($cmd, $output, $code);

    json_response([
        'ok'     => $code === 0,
        'code'   => $code,
        'output' => implode("\n", $output),
    ], $code === 0 ? 200 : 500);
}

/* -------------------------------------------------------------------------
 * Admin: users CRUD
 * ---------------------------------------------------------------------- */

function api_admin_users(): void
{
    require_admin();

    $rows = db()->query(
        'SELECT id, name, email, is_admin, created_at FROM users ORDER BY id'
    )->fetchAll();

    $items = array_map(static fn ($r) => [
        'id'         => (int) $r['id'],
        'name'       => (string) $r['name'],
        'email'      => (string) $r['email'],
        'is_admin'   => (int) $r['is_admin'] === 1,
        'created_at' => (string) $r['created_at'],
    ], $rows);

    json_response(['ok' => true, 'items' => $items]);
}

function api_admin_create_user(): void
{
    $admin = require_admin();
    $input = json_input();

    $name  = mb_substr(trim((string) ($input['name'] ?? '')), 0, 80);
    $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
    $pass  = (string) ($input['password'] ?? '');
    $isAdmin = !empty($input['is_admin']);

    if ($name === '') {
        throw new ApiException('Nama wajib diisi.', 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ApiException('Email tidak valid.', 422);
    }
    if (strlen($pass) < 6) {
        throw new ApiException('Password minimal 6 karakter.', 422);
    }

    $exists = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $exists->execute([$email]);
    if ($exists->fetch()) {
        throw new ApiException('Email sudah terdaftar.', 409);
    }

    $stmt = db()->prepare(
        'INSERT INTO users (name, email, password_hash, is_admin, created_at) VALUES (?, ?, ?, ?, NOW())'
    );
    $stmt->execute([$name, mb_substr($email, 0, 190), password_hash($pass, PASSWORD_DEFAULT), $isAdmin ? 1 : 0]);

    json_response([
        'ok'   => true,
        'user' => [
            'id'       => (int) db()->lastInsertId(),
            'name'     => $name,
            'email'    => $email,
            'is_admin' => $isAdmin,
        ],
    ], 201);
}

function api_admin_update_user(int $id): void
{
    $admin = require_admin();
    $input = json_input();

    $target = find_user($id);
    if (!$target) {
        throw new ApiException('User tidak ditemukan.', 404);
    }

    $name     = array_key_exists('name', $input) ? mb_substr(trim((string) $input['name']), 0, 80) : $target['name'];
    $email    = array_key_exists('email', $input) ? mb_strtolower(trim((string) $input['email'])) : $target['email'];
    $isAdmin  = array_key_exists('is_admin', $input) ? !empty($input['is_admin']) : $target['is_admin'];
    $password = (string) ($input['password'] ?? '');

    if ($name === '') {
        throw new ApiException('Nama wajib diisi.', 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ApiException('Email tidak valid.', 422);
    }

    $exists = db()->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
    $exists->execute([$email, $id]);
    if ($exists->fetch()) {
        throw new ApiException('Email sudah dipakai user lain.', 409);
    }

    // Never demote or delete the last admin.
    if ($target['is_admin'] && !$isAdmin) {
        assert_single_admin_left($id, 'menonaktifkan admin terakhir');
    }

    $stmt = db()->prepare('UPDATE users SET name = ?, email = ?, is_admin = ? WHERE id = ?');
    $stmt->execute([$name, $email, $isAdmin ? 1 : 0, $id]);

    if ($password !== '') {
        if (strlen($password) < 6) {
            throw new ApiException('Password minimal 6 karakter.', 422);
        }
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
    }

    json_response(['ok' => true, 'user' => find_user($id)]);
}

function api_admin_delete_user(int $id): void
{
    $admin = require_admin();

    $target = find_user($id);
    if (!$target) {
        throw new ApiException('User tidak ditemukan.', 404);
    }

    if ($target['is_admin']) {
        assert_single_admin_left($id, 'menghapus admin terakhir');
    }

    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);

    json_response(['ok' => true, 'user_id' => $id]);
}

function assert_single_admin_left(int $excludedId, string $action): void
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE is_admin = 1 AND id != ?');
    $stmt->execute([$excludedId]);
    if ((int) $stmt->fetchColumn() === 0) {
        throw new ApiException('Tidak boleh ' . $action . '.', 409);
    }
}

/* -------------------------------------------------------------------------
 * Admin: metadata edit + poster upload
 * ---------------------------------------------------------------------- */

function api_admin_update_movie(int $id): void
{
    require_admin();
    $input = json_input();

    $stmt = db()->prepare('SELECT id, poster, folder_poster FROM movies WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        throw new ApiException('Film tidak ditemukan.', 404);
    }

    $fields = [];
    $values = [];

    foreach (['title', 'genres'] as $col) {
        if (array_key_exists($col, $input)) {
            $fields[]  = $col . ' = ?';
            $values[]  = mb_substr(trim((string) $input[$col]), 0, $col === 'title' ? 255 : 255);
        }
    }
    if (array_key_exists('year', $input)) {
        $fields[] = 'year = ?';
        $values[] = $input['year'] !== null && $input['year'] !== '' ? (int) $input['year'] : null;
    }
    if (array_key_exists('overview', $input)) {
        $fields[] = 'overview = ?';
        $values[] = $input['overview'] !== null ? mb_substr(trim((string) $input['overview']), 0, 20000) : null;
    }

    if ($fields !== []) {
        $values[] = $id;
        db()->prepare('UPDATE movies SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);
    }

    json_response(['ok' => true]);
}

function api_admin_update_series(int $id): void
{
    require_admin();
    $input = json_input();

    $stmt = db()->prepare('SELECT id FROM series WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        throw new ApiException('Serial tidak ditemukan.', 404);
    }

    $fields = [];
    $values = [];

    foreach (['title', 'genres'] as $col) {
        if (array_key_exists($col, $input)) {
            $fields[] = $col . ' = ?';
            $values[] = mb_substr(trim((string) $input[$col]), 0, 255);
        }
    }
    if (array_key_exists('year', $input)) {
        $fields[] = 'year = ?';
        $values[] = $input['year'] !== null && $input['year'] !== '' ? (int) $input['year'] : null;
    }
    if (array_key_exists('overview', $input)) {
        $fields[] = 'overview = ?';
        $values[] = $input['overview'] !== null ? mb_substr(trim((string) $input['overview']), 0, 20000) : null;
    }

    if ($fields !== []) {
        $values[] = $id;
        db()->prepare('UPDATE series SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);
    }

    json_response(['ok' => true]);
}

function api_admin_update_episode(int $id): void
{
    require_admin();
    $input = json_input();

    $stmt = db()->prepare('SELECT id FROM episodes WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        throw new ApiException('Episode tidak ditemukan.', 404);
    }

    $fields = [];
    $values = [];

    if (array_key_exists('title', $input)) {
        $fields[] = 'title = ?';
        $values[] = mb_substr(trim((string) $input['title']), 0, 255);
    }
    if (array_key_exists('overview', $input)) {
        $fields[] = 'overview = ?';
        $values[] = $input['overview'] !== null ? mb_substr(trim((string) $input['overview']), 0, 20000) : null;
    }

    if ($fields !== []) {
        $values[] = $id;
        db()->prepare('UPDATE episodes SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);
    }

    json_response(['ok' => true]);
}

/**
 * Multipart poster upload: field "poster", target movie|series.
 * POST /admin/{movie|series}/{id}/poster
 */
function api_admin_upload_poster(string $type, int $id): void
{
    require_admin();

    $table = $type === 'series' ? 'series' : 'movies';

    $stmt = db()->prepare("SELECT id, poster FROM {$table} WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new ApiException('Item tidak ditemukan.', 404);
    }

    if (!isset($_FILES['poster']) || !is_uploaded_file($_FILES['poster']['tmp_name'])) {
        throw new ApiException('File poster belum dipilih.', 422);
    }

    $file = $_FILES['poster'];
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new ApiException('Upload gagal (kode ' . $file['error'] . ').', 422);
    }

    $size = (int) $file['size'];
    if ($size > 5 * 1024 * 1024) {
        throw new ApiException('Poster maksimal 5 MB.', 422);
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        throw new ApiException('File harus berupa gambar.', 422);
    }

    $key  = upload_poster_key($id) . '.' . ($info['mime'] === 'image/png' ? 'png' : 'jpg');
    $dest = storage_path('covers/' . $key);

    if (!is_dir(dirname($dest))) {
        @mkdir(dirname($dest), 0770, true);
    }

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new ApiException('Gagal menyimpan poster.', 500);
    }
    @chmod($dest, 0644);

    // Drop previous upload for this item.
    if (!empty($row['poster'])) {
        $old = storage_path('covers/' . basename((string) $row['poster']));
        if (is_file($old)) {
            @unlink($old);
        }
    }

    db()->prepare("UPDATE {$table} SET poster = ? WHERE id = ?")->execute(['covers/' . $key, $id]);

    json_response(['ok' => true, 'poster' => poster_url('covers/' . $key, null)]);
}

function api_admin_delete_poster(string $type, int $id): void
{
    require_admin();

    $table = $type === 'series' ? 'series' : 'movies';

    $stmt = db()->prepare("SELECT id, poster FROM {$table} WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new ApiException('Item tidak ditemukan.', 404);
    }

    if (!empty($row['poster'])) {
        $old = storage_path('covers/' . basename((string) $row['poster']));
        if (is_file($old)) {
            @unlink($old);
        }
    }

    db()->prepare("UPDATE {$table} SET poster = NULL WHERE id = ?")->execute([$id]);

    json_response(['ok' => true]);
}

/* -------------------------------------------------------------------------
 * Dispatch
 * ---------------------------------------------------------------------- */

function api_dispatch(string $method, string $path): void
{
    try {
        $path = '/' . trim($path, '/');

        if ($method === 'GET' && $path === '/health') {
            api_health();
        }

        if ($method === 'POST' && $path === '/auth/login') {
            api_login();
        }

        if ($method === 'POST' && $path === '/auth/register') {
            api_register();
        }

        if ($method === 'POST' && $path === '/auth/logout') {
            api_logout();
        }

        if ($method === 'POST' && $path === '/auth/password') {
            api_change_password();
        }

        $user = require_user();

        if ($method === 'GET' && $path === '/me') {
            api_me();
        }

        if ($method === 'GET' && $path === '/home') {
            api_home();
        }

        if ($method === 'GET' && $path === '/movies') {
            api_movies();
        }

        if ($method === 'GET' && $path === '/series') {
            api_series_list();
        }

        if (preg_match('#^/movie/(\d+)$#', $path, $m) && $method === 'GET') {
            api_movie_detail((int) $m[1]);
        }

        if (preg_match('#^/series/(\d+)$#', $path, $m) && $method === 'GET') {
            api_series_detail((int) $m[1]);
        }

        if (preg_match('#^/episode/(\d+)$#', $path, $m) && $method === 'GET') {
            api_episode_detail((int) $m[1]);
        }

        if ($method === 'GET' && $path === '/search') {
            api_search();
        }

        if ($method === 'POST' && $path === '/progress') {
            api_save_progress();
        }

        if ($method === 'GET' && $path === '/progress') {
            api_continue();
        }

        /* ----- Admin ----- */

        if ($method === 'POST' && $path === '/admin/scan') {
            api_admin_scan();
        }

        if ($method === 'GET' && $path === '/admin/users') {
            api_admin_users();
        }

        if ($method === 'POST' && $path === '/admin/users') {
            api_admin_create_user();
        }

        if (preg_match('#^/admin/users/(\d+)$#', $path, $m)) {
            if ($method === 'PATCH' || $method === 'PUT') {
                api_admin_update_user((int) $m[1]);
            }
            if ($method === 'DELETE') {
                api_admin_delete_user((int) $m[1]);
            }
        }

        if (preg_match('#^/admin/movie/(\d+)$#', $path, $m) && ($method === 'PATCH' || $method === 'PUT')) {
            api_admin_update_movie((int) $m[1]);
        }

        if (preg_match('#^/admin/series/(\d+)$#', $path, $m) && ($method === 'PATCH' || $method === 'PUT')) {
            api_admin_update_series((int) $m[1]);
        }

        if (preg_match('#^/admin/episode/(\d+)$#', $path, $m) && ($method === 'PATCH' || $method === 'PUT')) {
            api_admin_update_episode((int) $m[1]);
        }

        if (preg_match('#^/admin/(movie|series)/(\d+)/poster$#', $path, $m)) {
            if ($method === 'POST') {
                api_admin_upload_poster($m[1], (int) $m[2]);
            }
            if ($method === 'DELETE') {
                api_admin_delete_poster($m[1], (int) $m[2]);
            }
        }

        throw new ApiException('Endpoint tidak ditemukan.', 404);
    } catch (ApiException $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], $e->status);
    } catch (Throwable $e) {
        error_log('diarflix api: ' . $e->getMessage());
        json_response(['ok' => false, 'error' => 'Terjadi kesalahan di server.'], 500);
    }
}
