<?php
declare(strict_types=1);

/**
 * Encode worker: ubah video yang tidak didukung browser (HEVC) menjadi
 * H.264 480p + audio ramah browser, lalu ganti filenya di tempat.
 *
 * Dijalankan di background oleh admin (POST /admin/transcode):
 *
 *   php bin/encode-worker.php
 *
 * Log: storage/encode/worker.log (job) dan storage/encode/ffmpeg.log (progres).
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/transcode.php';
require __DIR__ . '/../app/scanner.php';

set_time_limit(0);
ini_set('memory_limit', '512M');

$queueDir = transcode_queue_dir();
$lock     = @fopen($queueDir . '/worker.lock', 'c');

if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Encode worker sudah berjalan.\n");
    exit(0);
}

function worker_log(string $msg): void
{
    file_put_contents(
        transcode_queue_dir() . '/worker.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n",
        FILE_APPEND
    );
}

function encode_job(array $job): bool
{
    $src  = (string) ($job['path'] ?? '');
    $kind = (string) ($job['kind'] ?? 'movie');

    if ($src === '' || !is_file($src)) {
        worker_log('dilewati (file hilang): ' . $src);
        return true;
    }

    $codec = transcode_video_codec($src);
    if ($codec === null || !in_array($codec, transcode_unsupported_codecs(), true)) {
        worker_log('dilewati (sudah didukung browser): ' . $src);
        return true;
    }

    // Subtitle embedded dikeluarkan ke sidecar SEBELUM file diganti, supaya
    // tetap ada walau proses convert atau scan akhir gagal.
    $extractStats = ['warnings' => []];
    extract_embedded_subtitles($src, $extractStats);
    foreach ($extractStats['warnings'] as $warning) {
        worker_log('subtitle: ' . $warning);
    }

    $dir    = dirname($src);
    $base   = pathinfo($src, PATHINFO_FILENAME);
    $target = $dir . '/' . $base . '.mkv';
    $temp   = $target . '.part';

    $audioArgs = ['-an'];
    $probe     = (string) @shell_exec(
        'ffprobe -v error -select_streams a:0 -show_entries stream=codec_name,channels -of csv=p=0 '
        . escapeshellarg($src) . ' 2>/dev/null'
    );
    $aLine = trim(explode("\n", $probe)[0] ?? '');
    if ($aLine !== '') {
        $aCols  = explode(',', $aLine);
        $aCodec = strtolower($aCols[0] ?? '');
        $aCh    = (int) ($aCols[1] ?? 0);
        if (in_array($aCodec, ['aac', 'mp3'], true) && $aCh > 0 && $aCh <= 2) {
            $audioArgs = ['-map', '0:a:0', '-c:a', 'copy'];
        } else {
            $audioArgs = ['-map', '0:a:0', '-c:a', 'aac', '-b:a', '128k', '-ac', '2'];
        }
    }

    $width = max(320, (int) config('media.transcode_max_width', 1280));

    $args = ['-map', '0:v:0'];
    $args = array_merge($args, $audioArgs);
    $args = array_merge($args, ['-map', '0:s?', '-c:s', 'copy']);
    $args = array_merge($args, [
        '-vf', "scale='min({$width},iw)':-2",
        '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '20',
        '-pix_fmt', 'yuv420p',
        '-f', 'matroska',
        $temp,
    ]);

    $cmd = 'ffmpeg -hide_banner -y -i ' . escapeshellarg($src) . ' '
        . implode(' ', array_map('escapeshellarg', $args));

    worker_log('encode mulai: ' . $src . ' -> ' . $target);
    $started = time();

    $discard = [];
    $rc      = 0;
    exec($cmd . ' 2>> ' . escapeshellarg(transcode_queue_dir() . '/ffmpeg.log'), $discard, $rc);

    if ($rc !== 0 || !is_file($temp)) {
        worker_log("GAGAL encode (rc={$rc}): {$src}");
        @unlink($temp);
        return false;
    }

    $outCodec = strtolower(trim((string) @shell_exec(
        'ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of csv=p=0 '
        . escapeshellarg($temp) . ' 2>/dev/null'
    )));
    $durSrc = (float) trim((string) @shell_exec(
        'ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($src) . ' 2>/dev/null'
    ));
    $durOut = (float) trim((string) @shell_exec(
        'ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($temp) . ' 2>/dev/null'
    ));

    if ($outCodec !== 'h264' || ($durSrc > 0 && $durOut > 0 && abs($durSrc - $durOut) > 10)) {
        worker_log(sprintf('GAGAL verifikasi (codec=%s durasi=%.0f/%.0f): %s', $outCodec, $durSrc, $durOut, $src));
        @unlink($temp);
        return false;
    }

    if ($target === $src) {
        if (!@rename($temp, $src)) {
            worker_log('GAGAL mengganti file: ' . $src);
            @unlink($temp);
            return false;
        }
    } else {
        if (!@rename($temp, $target)) {
            worker_log('GAGAL menulis: ' . $target);
            @unlink($temp);
            return false;
        }
        try {
            $table = $kind === 'episode' ? 'episodes' : 'movies';
            db()->prepare("UPDATE {$table} SET file_path = ? WHERE file_path = ?")
                ->execute([$target, $src]);
        } catch (Throwable $e) {
            worker_log('update DB file_path gagal: ' . $e->getMessage());
        }
        @unlink($src);
    }

    worker_log(sprintf('selesai (%ds): %s', time() - $started, $target));
    return true;
}

worker_log('worker mulai (pid ' . getmypid() . ')');

$processed = 0;
$failed    = 0;
$guard     = 0;

while (++$guard <= 500) {
    $markers = glob($queueDir . '/*.queued') ?: [];
    usort($markers, static fn ($a, $b) => (int) @filemtime($a) <=> (int) @filemtime($b));

    if ($markers === []) {
        break;
    }

    foreach ($markers as $marker) {
        $job = json_decode((string) @file_get_contents($marker), true);
        if (!is_array($job) || !isset($job['path'])) {
            @unlink($marker);
            continue;
        }

        $ok = false;
        try {
            $ok = encode_job($job);
        } catch (Throwable $e) {
            worker_log('error: ' . $e->getMessage());
            $ok = false;
        }

        if ($ok) {
            if (!@unlink($marker)) {
                @rename($marker, preg_replace('/\.queued$/', '.failed', $marker) ?: $marker . '.failed');
            }
            $processed++;
        } else {
            @rename($marker, preg_replace('/\.queued$/', '.failed', $marker) ?: $marker . '.failed');
            $failed++;
        }
    }
}

if ($guard > 500) {
    worker_log('berhenti: guard 500 iterasi tercapai');
}

if ($processed > 0) {
    worker_log('scan akhir untuk mendaftarkan hasil convert…');
    $out = [];
    $rc  = 0;
    exec(
        escapeshellarg(transcode_php_cli()) . ' '
        . escapeshellarg(ROOT_PATH . '/bin/scan.php') . ' --quiet 2>&1',
        $out,
        $rc
    );
    worker_log('scan akhir selesai (rc=' . $rc . ')');
    if ($out !== []) {
        worker_log(implode("\n", $out));
    }
}

worker_log("worker selesai ({$processed} berhasil, {$failed} gagal)");

flock($lock, LOCK_UN);
fclose($lock);
exit($failed > 0 ? 1 : 0);
