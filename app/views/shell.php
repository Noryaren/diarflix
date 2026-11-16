<?php
declare(strict_types=1);

// Rendered without a user identity: the browser only holds the token in
// localStorage, so the SPA asks /api/me for the current user.
$nonce     = csp_nonce();
$name      = (string) config('app.name', 'Diarflix');
$pageTitle = $name . ' — Nonton film & serial di server sendiri';

$clientConfig = [
    'base'    => base_path(),
    'version' => '1.0.0',
];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#141414">
    <meta name="description" content="<?= e($name) ?> — bioskop pribadi ala Netflix di server sendiri. Film, serial, dan progres nonton tersimpan lokal.">
    <meta property="og:title" content="<?= e($pageTitle) ?>">
    <meta property="og:description" content="Bioskop pribadi dengan tampilan ala Netflix. Ringan untuk server kecil.">
    <meta property="og:type" content="website">
    <title><?= e($pageTitle) ?></title>
    <link rel="icon" href="<?= e(url('/assets/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(url('/assets/app.css')) ?>?v=<?= e((string) filemtime(ROOT_PATH . '/public/assets/app.css')) ?>">
    <script nonce="<?= e($nonce) ?>" data-cfasync="false">
        window.DIAR = <?= json_encode($clientConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    </script>
</head>
<body>
    <div id="app" aria-live="polite">
        <div class="boot-screen">
            <div class="boot-logo" aria-hidden="true">D</div>
            <p>Memuat <?= e($name) ?>…</p>
        </div>
    </div>

    <noscript>
        <div class="noscript-banner">
            <?= e($name) ?> butuh JavaScript untuk memutar video.
            Aktifkan JavaScript lalu muat ulang halaman.
        </div>
    </noscript>

    <script src="<?= e(url('/assets/app.js')) ?>?v=<?= e((string) filemtime(ROOT_PATH . '/public/assets/app.js')) ?>" nonce="<?= e($nonce) ?>" data-cfasync="false" defer></script>
</body>
</html>
