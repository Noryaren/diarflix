<?php
declare(strict_types=1);

/**
 * Antrean convert video ke standar x264 480p (H.264 8-bit + audio browser).
 *
 * Tombol Convert di admin menjalankan bin/encode-worker.php di background.
 * Worker pertama memindai SELURUH library (probe tiap file), menulis penanda
 * antrean (`.queued` di storage/encode) untuk file yang belum standar, lalu
 * meng-encode satu per satu supaya beban CPU server tetap terkendali.
 * File yang sudah 480p x264 (atau lebih kecil) dilewati.
 */

function transcode_queue_dir(): string
{
    $dir = storage_path('encode');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/** Tinggi maksimal standar 480p (file dengan tinggi ≤ ini dianggap sudah sesuai). */
function transcode_max_height(): int
{
    return max(90, (int) config('media.transcode_max_height', 480));
}

function transcode_marker_path(string $path): string
{
    return transcode_queue_dir() . '/' . md5($path) . '.queued';
}

function transcode_failed_marker_path(string $path): string
{
    return transcode_queue_dir() . '/' . md5($path) . '.failed';
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

/**
 * Probe info video pertama (codec, lebar, tinggi) + audio pertama via ffprobe.
 * Mengembalikan null kalau ffprobe tidak ada atau file tidak terbaca.
 */
function transcode_probe(string $path): ?array
{
    if (!transcode_ffprobe_available()) {
        return null;
    }

    $out = (string) @shell_exec(
        'ffprobe -v error -show_entries stream=codec_type,codec_name,width,height,pix_fmt'
        . ':stream_disposition=attached_pic '
        . '-of json ' . escapeshellarg($path) . ' 2>/dev/null'
    );

    $j = json_decode($out, true);
    if (!is_array($j)) {
        return null;
    }

    $video = null;
    $audio = null;
    foreach (($j['streams'] ?? []) as $stream) {
        $type = (string) ($stream['codec_type'] ?? '');
        if ($type === 'video' && $video === null) {
            // Lewati gambar sampul (attached_pic), bukan stream video utama.
            if ((int) ($stream['disposition']['attached_pic'] ?? 0) === 1) {
                continue;
            }
            $video = [
                'codec'   => strtolower((string) ($stream['codec_name'] ?? '')),
                'width'   => (int) ($stream['width'] ?? 0),
                'height'  => (int) ($stream['height'] ?? 0),
                'pix_fmt' => strtolower((string) ($stream['pix_fmt'] ?? '')),
            ];
        } elseif ($type === 'audio' && $audio === null) {
            $audio = strtolower((string) ($stream['codec_name'] ?? ''));
        }
    }

    if ($video === null || $video['codec'] === '') {
        return null;
    }

    return [
        'video' => $video,
        'audio' => $audio,
        'ext'   => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
    ];
}

/**
 * Alasan file harus di-convert, atau null kalau sudah standar
 * (x264 + tinggi ≤ 480p + 8-bit + audio ramah browser) dan boleh dilewati.
 */
function transcode_convert_reason(array $info): ?string
{
    $v = $info['video'];

    if ($v['codec'] !== 'h264') {
        return 'codec ' . $v['codec'];
    }
    if ($v['height'] <= 0 || $v['width'] <= 0) {
        return 'resolusi tidak terbaca';
    }
    if ($v['height'] > transcode_max_height()) {
        return $v['width'] . 'x' . $v['height'] . ' (di atas 480p)';
    }

    // Container yang tidak bisa diputar browser (AVI, TS, dll) → remux ke MKV.
    $ext = (string) ($info['ext'] ?? '');
    if ($ext !== '' && !in_array($ext, ['mp4', 'mkv', 'webm', 'm4v', 'mov'], true)) {
        return 'container .' . $ext;
    }

    // 10-bit / 4:2:2 / 4:4:4 tidak diputar browser walau codec-nya h264.
    $pix = (string) ($v['pix_fmt'] ?? '');
    if ($pix !== '' && !in_array($pix, ['yuv420p', 'yuvj420p'], true)) {
        return 'pixel format ' . $pix;
    }

    // Audio codec non-umum juga bikin playback tidak sempurna.
    $audio = $info['audio'] ?? null;
    if ($audio !== null && !in_array($audio, ['aac', 'mp3', 'opus', 'vorbis'], true)) {
        return 'audio ' . $audio;
    }

    return null;
}

/**
 * Deteksi + antrekan saat scan. Mengembalikan true kalau file sedang/masuk
 * antrean convert 480p. File yang sudah standar (x264 ≤480p) dilewati.
 * Ekstraksi subtitle tidak ikut ditunda: srt dikeluarkan dari file asli
 * sebelum convert supaya tidak ikut hilang.
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
            $stats['warnings'][] = 'ffprobe tidak tersedia — deteksi convert nonaktif';
            $warned = true;
        }
        return false;
    }

    $info = transcode_probe($path);
    if ($info === null) {
        return false;
    }

    $reason = transcode_convert_reason($info);
    if ($reason === null) {
        return false;
    }

    if (!transcode_queue_file($path, $kind, $reason)) {
        $stats['warnings'][] = $label . ': gagal menulis antrean convert';
        return false;
    }

    $stats['warnings'][] = $label . ': ' . $reason
        . ' — masuk antrean convert 480p (klik tombol Convert di admin)';
    return true;
}

/** Tulis penanda antrean `.queued` untuk satu file. */
function transcode_queue_file(string $path, string $kind, string $reason): bool
{
    $data = json_encode([
        'path'      => $path,
        'kind'      => $kind,
        'reason'    => $reason,
        'queued_at' => date('c'),
    ], JSON_UNESCAPED_SLASHES);

    // Buang sisa kegagalan percobaan sebelumnya.
    @unlink(transcode_failed_marker_path($path));

    return $data !== false && @file_put_contents(transcode_marker_path($path), $data) !== false;
}

/**
 * Pindai SELURUH library dari DB dan antrekan file yang belum standar.
 * Dipanggil worker tiap kali tombol Convert ditekan supaya file lama yang
 * tidak berubah (tidak kena deteksi scan) tetap ikut convert.
 * Mengembalikan laporan jumlah.
 */
function transcode_queue_library(?array &$report = null): array
{
    $report = [
        'checked'    => 0,
        'queued'     => 0,
        'skipped'    => 0,
        'missing'    => 0,
        'unprobeable'=> 0,
    ];

    if (!transcode_ffprobe_available()) {
        return $report;
    }

    $items = [];
    foreach (['movies' => 'movie', 'episodes' => 'episode'] as $table => $kind) {
        foreach (db()->query("SELECT file_path FROM {$table}") as $row) {
            $items[] = [(string) $row['file_path'], $kind];
        }
    }

    foreach ($items as [$path, $kind]) {
        $report['checked']++;

        if (!is_file($path)) {
            $report['missing']++;
            continue;
        }

        if (transcode_is_queued($path)) {
            $report['queued']++;
            continue;
        }

        $info = transcode_probe($path);
        if ($info === null) {
            $report['unprobeable']++;
            continue;
        }

        $reason = transcode_convert_reason($info);
        if ($reason === null) {
            // Sudah standar — buang penanda lama (termasuk sisa kegagalan).
            @unlink(transcode_marker_path($path));
            @unlink(transcode_failed_marker_path($path));
            $report['skipped']++;
            continue;
        }

        if (transcode_queue_file($path, $kind, $reason)) {
            $report['queued']++;
        }
    }

    return $report;
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
    // Worker selalu boleh dijalankan: deteksi library (antrean kosong atau
    // tidak) dilakukan di dalam worker, bukan di sini.
    if (transcode_worker_running()) {
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

    $ffline = null;
    if ($chunk !== '') {
        $norm = str_replace("\r", "\n", $chunk);
        if (preg_match_all('/^frame=.*$/m', $norm, $ml)) {
            $ffline = trim($ml[0][array_key_last($ml[0])]);
        }
    }

    $currentLabel = null;
    if ($current !== null) {
        $parts = preg_split('#[\\\\/]+#', $current);
        $n = count($parts);
        $currentLabel = $n >= 2 ? ($parts[$n - 2] . '/' . $parts[$n - 1]) : $parts[$n - 1];
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
        'current'     => $currentLabel,
        'frame'       => $frame,
        'fps'         => $fps,
        'total'       => $total,
        'percent'     => $percent,
        'eta_seconds' => $eta,
        'ffmpeg_line' => $ffline,
    ];
}
