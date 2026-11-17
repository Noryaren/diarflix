<?php
declare(strict_types=1);

/**
 * Antrean convert video yang tidak didukung browser (HEVC/x265 → H.264 480p).
 *
 * Scan hanya mendeteksi + menulis penanda antrean (`.queued` di
 * storage/encode). Encode dijalankan lewat admin (POST /admin/transcode)
 * yang menyalakan bin/encode-worker.php di background — satu file pada
 * satu waktu supaya beban CPU server tetap terkendali.
 */

function transcode_queue_dir(): string
{
    $dir = storage_path('encode');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function transcode_unsupported_codecs(): array
{
    $list = config('media.unsupported_codecs');
    return is_array($list) ? array_map('strtolower', $list) : ['hevc'];
}

function transcode_marker_path(string $path): string
{
    return transcode_queue_dir() . '/' . md5($path) . '.queued';
}

function transcode_is_queued(string $path): bool
{
    return is_file(transcode_marker_path($path));
}

function transcode_queued_count(): int
{
    return count(glob(transcode_queue_dir() . '/*.queued') ?: []);
}

function transcode_ffprobe_available(): bool
{
    static $has = null;
    if ($has === null) {
        $has = trim((string) @shell_exec('command -v ffprobe 2>/dev/null')) !== '';
    }
    return $has;
}

function transcode_ffmpeg_available(): bool
{
    static $has = null;
    if ($has === null) {
        $has = trim((string) @shell_exec('command -v ffmpeg 2>/dev/null')) !== '';
    }
    return $has;
}

/** Codec stream video pertama, atau null kalau ffprobe tidak bisa membaca. */
function transcode_video_codec(string $path): ?string
{
    if (!transcode_ffprobe_available()) {
        return null;
    }

    $out = (string) @shell_exec(
        'ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of csv=p=0 '
        . escapeshellarg($path) . ' 2>/dev/null'
    );

    $codec = strtolower(trim(explode("\n", $out)[0] ?? ''));
    return $codec !== '' ? $codec : null;
}

/**
 * Deteksi + antrekan saat scan. Mengembalikan true kalau file sedang/masuk
 * antrean convert. Ekstraksi subtitle tidak ikut ditunda: srt dikeluarkan
 * dari file asli sebelum convert supaya tidak ikut hilang.
 */
function transcode_scan_check(string $path, string $kind, string $label, bool $changed, array &$stats): bool
{
    if (transcode_is_queued($path)) {
        $stats['warnings'][] = $label . ': dalam antrean convert 480p (klik tombol Convert di admin)';
        return true;
    }

    if (!$changed) {
        return false;
    }

    if (!transcode_ffprobe_available()) {
        static $warned = false;
        if (!$warned) {
            $stats['warnings'][] = 'ffprobe tidak tersedia — deteksi codec HEVC nonaktif';
            $warned = true;
        }
        return false;
    }

    $codec = transcode_video_codec($path);
    if ($codec === null || !in_array($codec, transcode_unsupported_codecs(), true)) {
        return false;
    }

    $marker = transcode_marker_path($path);
    $data   = json_encode([
        'path'      => $path,
        'kind'      => $kind,
        'codec'     => $codec,
        'queued_at' => date('c'),
    ], JSON_UNESCAPED_SLASHES);

    if ($data === false || @file_put_contents($marker, $data) === false) {
        $stats['warnings'][] = $label . ': gagal menulis antrean convert';
        return false;
    }

    $stats['warnings'][] = $label . ': format ' . $codec
        . ' tidak didukung browser — masuk antrean convert 480p (klik tombol Convert di admin)';
    return true;
}

function transcode_worker_running(): bool
{
    $lock = @fopen(transcode_queue_dir() . '/worker.lock', 'c');
    if ($lock === false) {
        return false;
    }

    $busy = !flock($lock, LOCK_EX | LOCK_NB);
    if (!$busy) {
        flock($lock, LOCK_UN);
    }
    fclose($lock);

    return $busy;
}

function transcode_php_cli(): string
{
    if (function_exists('php_cli_binary')) {
        return php_cli_binary();
    }
    return PHP_BINARY;
}

function transcode_spawn_worker(): bool
{
    if (transcode_queued_count() === 0 || transcode_worker_running()) {
        return false;
    }

    $php    = transcode_php_cli();
    $script = ROOT_PATH . '/bin/encode-worker.php';
    $log    = transcode_queue_dir() . '/worker.log';

    $cmd = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
        . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null &';
    exec($cmd);

    return true;
}

/** Perkiraan jumlah frame (durasi × fps) untuk file video. */
function transcode_video_frames(string $path): int
{
    static $cache = [];
    if (isset($cache[$path])) {
        return $cache[$path];
    }

    $out  = (string) @shell_exec(
        'ffprobe -v error -show_entries format=duration -show_entries stream=avg_frame_rate -of json '
        . escapeshellarg($path) . ' 2>/dev/null'
    );
    $j    = json_decode($out, true) ?: [];
    $dur  = (float) ($j['format']['duration'] ?? 0);
    $rate = '';
    foreach (($j['streams'] ?? []) as $stream) {
        if (!empty($stream['avg_frame_rate'])) {
            $rate = (string) $stream['avg_frame_rate'];
            break;
        }
    }

    $fps = 0.0;
    if (str_contains($rate, '/')) {
        [$num, $den] = explode('/', $rate, 2);
        if ((float) $den > 0) {
            $fps = (float) $num / (float) $den;
        }
    } elseif ($rate !== '') {
        $fps = (float) $rate;
    }

    return $cache[$path] = ($dur > 0 && $fps > 0) ? (int) round($dur * $fps) : 0;
}

function transcode_log_tail(string $file, int $bytes = 8192): string
{
    if (!is_file($file)) {
        return '';
    }
    $size = (int) filesize($file);
    $fh   = @fopen($file, 'rb');
    if ($fh === false) {
        return '';
    }
    fseek($fh, max(0, $size - $bytes));
    $tail = (string) fread($fh, $bytes);
    fclose($fh);
    return $tail;
}

/** Status antrean + progres encode berjalan, untuk ditampilkan di admin. */
function transcode_progress(): array
{
    $running = transcode_worker_running();
    $queued  = transcode_queued_count();

    $current = null;
    $tail    = transcode_log_tail(transcode_queue_dir() . '/worker.log', 4096);
    if (preg_match_all('/\] encode mulai: (.+?) -> /u', $tail, $m)) {
        $current = $m[1][array_key_last($m[1])];
    }

    $frame = null;
    $fps   = null;
    $chunk = transcode_log_tail(transcode_queue_dir() . '/ffmpeg.log', 8192);
    if ($chunk !== '' && preg_match_all('/frame=\s*(\d+)\s+fps=\s*([\d.]+)/', $chunk, $m2)) {
        $i     = array_key_last($m2[1]);
        $frame = (int) $m2[1][$i];
        $fps   = (float) $m2[2][$i];
    }

    $total   = null;
    $percent = null;
    $eta     = null;
    if ($running && $current !== null && $frame !== null) {
        $total = transcode_video_frames($current);
        if ($total > 0) {
            $percent = min(100.0, round($frame / $total * 100, 1));
            if ($fps !== null && $fps > 0) {
                $eta = (int) round(max(0, $total - $frame) / $fps);
            }
        }
    }

    return [
        'running'     => $running,
        'queued'      => $queued,
        'current'     => $current !== null ? basename($current) : null,
        'frame'       => $frame,
        'fps'         => $fps,
        'total'       => $total,
        'percent'     => $percent,
        'eta_seconds' => $eta,
    ];
}
