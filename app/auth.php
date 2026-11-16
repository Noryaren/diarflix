<?php
declare(strict_types=1);

/**
 * Stateless authentication: JWT (HS256) sent as "Authorization: Bearer <token>".
 *
 * Nothing is kept server side — no session, no session cookie, no CSRF token —
 * so login keeps working behind the Cloudflare Tunnel. The browser holds the
 * token in localStorage and replays it on every API call.
 *
 * Media requests (<video>/<img>/<track> cannot set headers) carry the same
 * token as a "t" query parameter.
 */

function jwt_secret(): string
{
    static $secret = null;

    if ($secret !== null) {
        return $secret;
    }

    $configured = config('app.jwt_secret');
    if (is_string($configured) && trim($configured) !== '') {
        $secret = trim($configured);
        return $secret;
    }

    $file = ROOT_PATH . '/storage/jwt.secret';

    if (is_file($file)) {
        $existing = @file_get_contents($file);

        if ($existing === false) {
            $GLOBALS['diarflix_jwt_secret_broken'] = true;
            error_log('diarflix: tidak bisa membaca ' . $file
                . ' — pastikan user web (www-data) boleh membacanya.');
        } else {
            $existing = trim($existing);
            if ($existing !== '') {
                $secret = $existing;
                return $secret;
            }
        }
    }

    $secret = bin2hex(random_bytes(32));

    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }

    if (@file_put_contents($file, $secret, LOCK_EX) === false) {
        $GLOBALS['diarflix_jwt_secret_broken'] = true;
        error_log('diarflix: tidak bisa menyimpan secret JWT di ' . $file);
    } else {
        @chmod($file, 0600);
    }

    return $secret;
}

function jwt_secret_broken(): bool
{
    jwt_secret();

    return !empty($GLOBALS['diarflix_jwt_secret_broken']);
}

function token_ttl(): int
{
    $ttl = (int) config('app.token_ttl', 31536000);
    return $ttl > 0 ? $ttl : 31536000;
}

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string
{
    $b64 = strtr($data, '-_', '+/');
    $pad = strlen($b64) % 4;

    if ($pad > 0) {
        $b64 .= str_repeat('=', 4 - $pad);
    }

    return (string) base64_decode($b64, true);
}

function issue_token(int $userId): string
{
    $now = time();

    $header = base64url_encode((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $claims = base64url_encode((string) json_encode([
        'iss' => 'diarflix',
        'sub' => $userId,
        'iat' => $now,
        'exp' => $now + token_ttl(),
        'jti' => bin2hex(random_bytes(16)),
    ]));

    $signature = hash_hmac('sha256', $header . '.' . $claims, jwt_secret(), true);

    return $header . '.' . $claims . '.' . base64url_encode($signature);
}

function token_claims(?string $token): ?array
{
    if ($token === null || $token === '' || strlen($token) > 4096) {
        return null;
    }

    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$rawHeader, $rawClaims, $rawSignature] = $parts;
    if ($rawHeader === '' || $rawClaims === '' || $rawSignature === '') {
        return null;
    }

    $header = json_decode(base64url_decode($rawHeader), true);
    if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
        return null;
    }

    $expected = base64url_encode(hash_hmac('sha256', $rawHeader . '.' . $rawClaims, jwt_secret(), true));
    if (!hash_equals($expected, $rawSignature)) {
        return null;
    }

    $claims = json_decode(base64url_decode($rawClaims), true);
    if (!is_array($claims)) {
        return null;
    }

    if (!isset($claims['exp']) || !is_numeric($claims['exp']) || time() >= (int) $claims['exp']) {
        return null;
    }

    if (isset($claims['nbf']) && is_numeric($claims['nbf']) && time() < (int) $claims['nbf']) {
        return null;
    }

    if (!isset($claims['sub'])) {
        return null;
    }

    return $claims;
}

function auth_token(): ?string
{
    static $cached = false;
    static $token  = null;

    if ($cached) {
        return $token;
    }
    $cached = true;

    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    if ($header === '') {
        $header = (string) ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    }

    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $header = (string) $value;
                    break;
                }
            }
        }
    }

    if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches) === 1) {
        $token = $matches[1];
        return $token;
    }

    $fromQuery = (string) ($_GET['t'] ?? '');
    if ($fromQuery !== '') {
        $token = $fromQuery;
    }

    return $token;
}

function find_user(int $userId): ?array
{
    $stmt = db()->prepare('SELECT id, name, email, is_admin, created_at FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $row['id']       = (int) $row['id'];
    $row['is_admin'] = (int) $row['is_admin'] === 1;

    return $row;
}

function current_user(): ?array
{
    if (array_key_exists('diarflix_current_user', $GLOBALS)) {
        return $GLOBALS['diarflix_current_user'];
    }

    $user   = null;
    $claims = token_claims(auth_token());

    if ($claims !== null) {
        $user = find_user((int) $claims['sub']);
    }

    $GLOBALS['diarflix_current_user'] = $user;

    return $user;
}

function require_user(): array
{
    $user = current_user();
    if ($user === null) {
        header('WWW-Authenticate: Bearer realm="Diarflix", error="invalid_token"');
        throw new ApiException('Silakan masuk terlebih dahulu.', 401);
    }
    return $user;
}

function require_admin(): array
{
    $user = require_user();
    if (empty($user['is_admin'])) {
        throw new ApiException('Hanya admin yang boleh melakukan ini.', 403);
    }
    return $user;
}

function login_user(int $userId): string
{
    $GLOBALS['diarflix_current_user'] = find_user($userId);

    return issue_token($userId);
}

function attempt_login(string $email, string $password): ?string
{
    if ($email === '' || $password === '') {
        return null;
    }

    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, (string) $row['password_hash'])) {
        return null;
    }

    if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
        $upd = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $upd->execute([password_hash($password, PASSWORD_DEFAULT), (int) $row['id']]);
    }

    return login_user((int) $row['id']);
}
