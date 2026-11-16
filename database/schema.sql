-- Diarflix database schema
-- MariaDB 10.6+ / MySQL 8.0+
-- Usage: mariadb -u diarflix -p diarflix < database/schema.sql

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(80)     NOT NULL,
    email         VARCHAR(190)    NOT NULL,
    password_hash VARCHAR(255)    NOT NULL,
    is_admin      TINYINT(1)      NOT NULL DEFAULT 0,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS movies (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title         VARCHAR(255)    NOT NULL,
    year          SMALLINT UNSIGNED NULL,
    overview      TEXT            NULL,
    poster        VARCHAR(768)    NULL,   -- upload admin (storage/covers/...)
    folder_poster VARCHAR(768)    NULL,   -- poster.jpg dari folder media
    genres        VARCHAR(255)    NOT NULL DEFAULT '',
    runtime_ms    INT UNSIGNED    NOT NULL DEFAULT 0,  -- diisi player saat pertama nonton
    file_path     VARCHAR(768)    NOT NULL,
    file_size     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    file_mtime    INT UNSIGNED    NOT NULL DEFAULT 0,
    added_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_movies_path (file_path(191)),
    KEY idx_movies_title (title(191)),
    KEY idx_movies_added (added_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS series (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title         VARCHAR(255)    NOT NULL,
    home_path     VARCHAR(768)    NOT NULL,  -- folder serial di /diarflix/series
    year          SMALLINT UNSIGNED NULL,
    overview      TEXT            NULL,
    poster        VARCHAR(768)    NULL,
    folder_poster VARCHAR(768)    NULL,
    genres        VARCHAR(255)    NOT NULL DEFAULT '',
    added_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_series_home (home_path(191)),
    KEY idx_series_title (title(191))
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seasons (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    series_id  BIGINT UNSIGNED NOT NULL,
    season_no  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    name       VARCHAR(190)    NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    UNIQUE KEY uq_seasons_series_no (series_id, season_no),
    CONSTRAINT fk_seasons_series FOREIGN KEY (series_id) REFERENCES series (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS episodes (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    series_id    BIGINT UNSIGNED NOT NULL,
    season_id    BIGINT UNSIGNED NOT NULL,
    episode_no   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    title        VARCHAR(255)    NOT NULL DEFAULT '',
    overview     TEXT            NULL,
    duration_ms  INT UNSIGNED    NOT NULL DEFAULT 0,
    file_path    VARCHAR(768)    NOT NULL,
    file_size    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    file_mtime   INT UNSIGNED    NOT NULL DEFAULT 0,
    added_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_episodes_path (file_path(191)),
    KEY idx_episodes_series (series_id, season_id, episode_no),
    CONSTRAINT fk_episodes_series FOREIGN KEY (series_id) REFERENCES series (id) ON DELETE CASCADE,
    CONSTRAINT fk_episodes_season FOREIGN KEY (season_id) REFERENCES seasons (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS watch_progress (
    user_id     BIGINT UNSIGNED NOT NULL,
    item_type   ENUM('movie','episode') NOT NULL,
    item_id     BIGINT UNSIGNED NOT NULL,
    position_ms INT UNSIGNED    NOT NULL DEFAULT 0,
    duration_ms INT UNSIGNED    NOT NULL DEFAULT 0,
    finished    TINYINT(1)      NOT NULL DEFAULT 0,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, item_type, item_id),
    KEY idx_progress_recent (user_id, updated_at),
    CONSTRAINT fk_progress_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
