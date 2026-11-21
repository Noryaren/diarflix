<?php
/**
 * Copy this file to config/config.php and adjust the values.
 * Never commit config/config.php to a public repository.
 */
return [
    'app' => [
        'name'      => 'Diarflix',
        'debug'     => false,
        'timezone'  => 'UTC',
        'base_path' => null,
        'trust_proxy' => true,
        // Login stateless (JWT di localStorage): token hidup selama ini detik.
        'token_ttl' => 31536000,
        // 'jwt_secret' => '',
    ],

    'db' => [
        'dsn'  => 'mysql:host=127.0.0.1;port=3307;dbname=diarflix;charset=utf8mb4',
        'user' => 'diarflix',
        'pass' => 'change-me',
    ],

    'media' => [
        // Folder per film: /diarflix/movie/Judul Film (2020)/video.mkv
        'movie_path'  => '/diarflix/movie',
        // Folder serial: /diarflix/series/Judul/Season 1/...S01E01.mkv
        'series_path' => '/diarflix/series',
        // Standar tombol Convert admin (opsional; sudah ada di default)
        // 'transcode_max_width'  => 854,
        // 'transcode_max_height' => 480,
    ],
];
