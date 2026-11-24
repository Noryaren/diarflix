# Diarflix

**Personal media player — 100% gratis untuk kamu self-host sendiri.**

Diarflix adalah bioskop pribadi ala Netflix: tampilan hero + row poster, film & serial,
search, progres nonton yang nyambung antar device, dan multi-user. Semua berjalan di
server milikmu sendiri — tanpa langganan, tanpa biaya lisensi, tanpa iklan. Kode ini
bebas kamu pakai, ubah, dan host untuk kebutuhan pribadi selamanya.

> Filosofi: **100% gratis, ringan, dan sepenuhnya milikmu.**
> Tidak ada telemetri, tidak ada akun wajib dari vendor, tidak ada fitur yang
> dikunci di balik pembayaran. Server kecil pun sanggup (diuji di Atom N550, 2 GB RAM).

## Fitur

- **Tampilan Netflix** — hero film pilihan, row horizontal, kartu poster dengan
  progress bar, halaman detail, dan player fullscreen
- **Film + serial** — scanner baca nama folder/file (tanpa API eksternal):
  - Film: `/diarflix/movie/Judul Film (2020)/video.mkv`
  - Serial: `/diarflix/series/Judul/Season 1/Judul.S01E01.mkv`
- **Subtitle otomatis** — file `.srt`/`.vtt` yang sebasename dengan video langsung
  muncul di player (SRT dikonversi ke VTT on-the-fly)
- **Progres nonton** — autosave tiap 10 detik + saat pause; row *Lanjutkan
  Menonton* tersimpan di database, bukan di browser
- **Search** — cari judul film, serial, maupun episode
- **Multi-user** — register terbuka, masing-masing punya progres sendiri
- **Superadmin** — scan library dari web, CRUD user, edit deskripsi/judul/tahun/
  genre, upload poster (opsional — tanpa poster tetap tampil inisial judul)
- **Direct play** — streaming via HTTP Range (seek mulus), tanpa transcode saat
  nonton; ffmpeg hanya untuk ekstraksi subtitle

## Kebutuhan

- PHP 8.5-FPM (8.1+ juga jalan) dengan ekstensi PDO MySQL
- MariaDB/MySQL (bisa dari container Docker)
- Caddy (atau web server apa pun yang bisa `php_fastcgi`)
- ffmpeg + ffprobe (opsional — ekstraksi subtitle)
- Penyimpanan untuk file media (MP4 H.264 paling kompatibel dengan browser)

## Instalasi singkat

```bash
# 1. Kode ke server
rsync -a --exclude config/config.php --exclude storage/jwt.secret \
  ./ diar@server:/home/diarflix/
sudo chown -R www-data:www-data /home/diarflix/storage

# 2. Folder media
sudo mkdir -p /diarflix/movie /diarflix/series
sudo chmod -R a+rX /diarflix

# 3. Database (contoh pakai container mariadb-main, port 3307)
docker exec -i mariadb-main mariadb -uroot -p"PASS" <<'SQL'
CREATE DATABASE diarflix CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'diarflix'@'%' IDENTIFIED BY 'ganti-ini';
GRANT ALL ON diarflix.* TO 'diarflix'@'%';
SQL
docker exec -i mariadb-main mariadb -uroot -p"PASS" diarflix < database/schema.sql

# 4. Konfigurasi
cp config/config.example.php config/config.php   # isi password DB

# 5. Superadmin pertama
php bin/create-user.php "Nama Kamu" kamu@email.id

# 6. Scan library
php bin/scan.php
```

Tambahkan blok situs sesuai `deploy/Caddyfile.example` ke `/etc/caddy/Caddyfile`
(domain `movie.diaraji.my.id`), lalu `sudo systemctl reload caddy`.
Pastikan hostname tersebut juga terdaftar di Cloudflare Tunnel.

## Cara pakai

| Role | Akses |
|---|---|
| Visitor | Register / login |
| User | Nonton, search, progres nonton |
| Admin | Semua user + scan library + CRUD user + edit metadata & poster |

- **Login:** stateless JWT disimpan di `localStorage` browser — cocok di belakang
  Cloudflare Tunnel (tanpa cookie, tanpa CSRF).
- **Scan ulang:** menu **Admin → Perpustakaan** (atau `php bin/scan.php`).
  File baru masuk, yang dihapus dari disk ikut hilang dari DB; poster & deskripsi
  yang kamu edit tidak disentuh scan.
- **Poster:** admin upload per judul, atau letakkan `poster.jpg` di folder media.

## Struktur proyek

```
bin/          create-user.php, scan.php (CLI)
config/       config.example.php → salin jadi config.php
database/     schema.sql
deploy/       Caddyfile.example, contoh tuning PHP-FPM
app/          bootstrap, helpers, db, auth (JWT), api, scanner, stream
public/       index.php (front controller) + assets (SPA)
storage/      jwt.secret, covers/ (poster upload)
```

## Catatan teknis

- **Direct play saat nonton.** File diserve apa adanya dari disk (Range request,
  seek mulus) — tidak ada transcode di jalur streaming. ffmpeg dipakai terpisah
  untuk ekstraksi subtitle embedded ke sidecar SRT.
- **Tanpa session cookie** — auth sepenuhnya JWT, aman dari cookie-rewriting CDN.
- **Ringan** — tanpa daemon tambahan: cukup PHP-FPM + MariaDB yang memang sudah
  ada di server; proses scan dijalankan on-demand lewat `exec`.

## Lisensi & semangat proyek

Personal media player untuk self-hosting pribadi. **100% gratis** — tanpa biaya
langganan, tanpa fitur premium, tanpa pembatasan penggunaan selama kamu host
sendiri. Silakan fork, modifikasi, dan pakai untuk rumahmu.
