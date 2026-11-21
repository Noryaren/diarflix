/* Diarflix SPA — vanilla JS, token in localStorage, hash router. */
(function () {
    'use strict';

    var BASE = (window.DIAR && window.DIAR.base) || '';
    var TOKEN_KEY = 'diarflix_token';
    var app = document.getElementById('app');

    /* ------------------------------------------------------------------ *
     * Auth helpers
     * ------------------------------------------------------------------ */

    function getToken() { return localStorage.getItem(TOKEN_KEY) || ''; }
    function setToken(t) {
        if (t) localStorage.setItem(TOKEN_KEY, t);
        else localStorage.removeItem(TOKEN_KEY);
    }

    function api(path, opts) {
        opts = opts || {};
        var url = BASE + '/api' + path;
        var init = {
            method: opts.method || 'GET',
            headers: {}
        };
        var token = getToken();
        if (token) init.headers['Authorization'] = 'Bearer ' + token;

        if (opts.body !== undefined && !(opts.body instanceof FormData)) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(opts.body);
        } else if (opts.body instanceof FormData) {
            init.body = opts.body;
        }

        return fetch(url, init).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (res.status === 401 && getToken()) {
                    setToken('');
                    navigate('#/login');
                }
                if (!res.ok) {
                    var err = new Error(data.error || 'Terjadi kesalahan (' + res.status + ')');
                    err.status = res.status;
                    throw err;
                }
                return data;
            });
        });
    }

    /* ------------------------------------------------------------------ *
     * Small utilities
     * ------------------------------------------------------------------ */

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function h(html) {
        var t = document.createElement('template');
        t.innerHTML = html.trim();
        return t.content.firstChild;
    }

    function qs(sel, root) { return (root || document).querySelector(sel); }
    function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function fmtTime(ms) {
        if (!ms || ms < 0) ms = 0;
        var s = Math.floor(ms / 1000);
        var hpart = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var sec = s % 60;
        var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
        return hpart > 0 ? hpart + ':' + pad(m) + ':' + pad(sec) : m + ':' + pad(sec);
    }

    function hashStr(s) {
        var hval = 0;
        for (var i = 0; i < s.length; i++) {
            hval = ((hval << 5) - hval) + s.charCodeAt(i);
            hval |= 0;
        }
        return Math.abs(hval);
    }

    var GRADIENTS = [
        'linear-gradient(135deg,#1a1a2e 0%,#16213e 50%,#0f3460 100%)',
        'linear-gradient(135deg,#2b1055 0%,#7597de 100%)',
        'linear-gradient(135deg,#41295a 0%,#2f0743 100%)',
        'linear-gradient(135deg,#141e30 0%,#243b55 100%)',
        'linear-gradient(135deg,#232526 0%,#414345 100%)',
        'linear-gradient(135deg,#3a1c71 0%,#d76d77 50%,#ffaf7b 100%)',
        'linear-gradient(135deg,#0f2027 0%,#203a43 50%,#2c5364 100%)',
        'linear-gradient(135deg,#42275a 0%,#734b6d 100%)'
    ];

    function posterFallback(title, cls) {
        var initials = String(title || '?').trim().split(/\s+/).slice(0, 2)
            .map(function (w) { return w.charAt(0).toUpperCase(); }).join('');
        var g = GRADIENTS[hashStr(title || 'x') % GRADIENTS.length];
        return '<div class="poster-fallback ' + (cls || '') + '" style="background:' + g + '">' +
            '<span>' + esc(initials) + '</span></div>';
    }

    function posterHtml(item, cls) {
        if (item.poster) {
            return '<img class="' + (cls || 'poster-img') + '" src="' + esc(item.poster) + '" alt="' + esc(item.title) + '" loading="lazy">';
        }
        return posterFallback(item.title, cls || 'poster-fallback');
    }

    function yearLabel(item) {
        var bits = [];
        if (item.year) bits.push(esc(item.year));
        if (item.genres) bits.push(esc(item.genres));
        return bits.join(' · ');
    }

    function toast(msg, isErr) {
        var el = h('<div class="toast' + (isErr ? ' toast-err' : '') + '">' + esc(msg) + '</div>');
        document.body.appendChild(el);
        setTimeout(function () { el.classList.add('show'); }, 10);
        setTimeout(function () {
            el.classList.remove('show');
            setTimeout(function () { el.remove(); }, 300);
        }, 3200);
    }

    /* ------------------------------------------------------------------ *
     * Router
     * ------------------------------------------------------------------ */

    var currentCleanup = null;

    function navigate(hash) {
        if (location.hash === hash) route();
        else location.hash = hash;
    }

    function route() {
        if (currentCleanup) { try { currentCleanup(); } catch (e) {} currentCleanup = null; }

        var raw = location.hash.replace(/^#/, '') || '/';
        var qIndex = raw.indexOf('?');
        var path = qIndex === -1 ? raw : raw.slice(0, qIndex);
        var query = {};
        if (qIndex !== -1) {
            raw.slice(qIndex + 1).split('&').forEach(function (pair) {
                if (!pair) return;
                var kv = pair.split('=');
                query[decodeURIComponent(kv[0])] = decodeURIComponent((kv[1] || '').replace(/\+/g, ' '));
            });
        }
        var parts = path.split('/').filter(Boolean);

        if (!getToken()) {
            renderAuth(parts[0] === 'register' ? 'register' : 'login');
            return;
        }

        switch (parts[0]) {
            case undefined:
            case '':
                renderHome();
                break;
            case 'login':
                navigate('#/');
                break;
            case 'register':
                navigate('#/');
                break;
            case 'movies':
                renderGrid('movie');
                break;
            case 'series':
                renderGrid('series');
                break;
            case 'movie':
                renderMovieDetail(parseInt(parts[1], 10));
                break;
            case 'show':
                renderSeriesDetail(parseInt(parts[1], 10));
                break;
            case 'search':
                renderSearch(query.q || '');
                break;
            case 'watch':
                renderPlayer(parts[1], parseInt(parts[2], 10));
                break;
            case 'admin':
                renderAdmin();
                break;
            case 'settings':
                renderSettings();
                break;
            default:
                renderNotFound();
        }
    }

    window.addEventListener('hashchange', route);

    /* ------------------------------------------------------------------ *
     * Auth screens
     * ------------------------------------------------------------------ */

    function renderAuth(mode) {
        var isReg = mode === 'register';
        app.innerHTML =
            '<div class="auth-wrap">' +
            '<div class="auth-card">' +
            '<div class="auth-logo">D<span>FLIX</span></div>' +
            '<h1>' + (isReg ? 'Buat akun' : 'Masuk') + '</h1>' +
            '<form id="auth-form" autocomplete="on">' +
            (isReg ? '<input name="name" type="text" placeholder="Nama" required maxlength="80" autocomplete="name">' : '') +
            '<input name="email" type="email" placeholder="Email" required autocomplete="email">' +
            '<input name="password" type="password" placeholder="Password minimal 6 karakter" required minlength="6" autocomplete="' + (isReg ? 'new-password' : 'current-password') + '">' +
            '<button type="submit" class="btn btn-primary btn-block">' + (isReg ? 'Daftar' : 'Masuk') + '</button>' +
            '<p class="auth-err" id="auth-err" hidden></p>' +
            '</form>' +
            '<p class="auth-switch">' +
            (isReg
                ? 'Sudah punya akun? <a href="#/login">Masuk</a>'
                : 'Belum punya akun? <a href="#/register">Daftar</a>') +
            '</p>' +
            '</div></div>';

        qs('#auth-form').addEventListener('submit', function (ev) {
            ev.preventDefault();
            var fd = new FormData(ev.target);
            var body = {
                email: fd.get('email'),
                password: fd.get('password')
            };
            if (isReg) body.name = fd.get('name');

            var btn = qs('button[type=submit]', ev.target);
            btn.disabled = true;
            btn.textContent = 'Memproses…';

            api(isReg ? '/auth/register' : '/auth/login', { method: 'POST', body: body })
                .then(function (data) {
                    setToken(data.token);
                    navigate('#/');
                    route();
                })
                .catch(function (err) {
                    var el = qs('#auth-err');
                    el.textContent = err.message;
                    el.hidden = false;
                    btn.disabled = false;
                    btn.textContent = isReg ? 'Daftar' : 'Masuk';
                });
        });
    }

    /* ------------------------------------------------------------------ *
     * Chrome: navbar
     * ------------------------------------------------------------------ */

    function navbar(active) {
        var user = window.__user || {};
        return '<header class="nav">' +
            '<div class="nav-inner">' +
            '<a class="nav-logo" href="#/">D<span>FLIX</span></a>' +
            '<nav class="nav-links">' +
            '<a href="#/" class="' + (active === 'home' ? 'active' : '') + '">Beranda</a>' +
            '<a href="#/movies" class="' + (active === 'movies' ? 'active' : '') + '">Film</a>' +
            '<a href="#/series" class="' + (active === 'series' ? 'active' : '') + '">Serial</a>' +
            (user.is_admin ? '<a href="#/admin" class="' + (active === 'admin' ? 'active' : '') + '">Admin</a>' : '') +
            '</nav>' +
            '<div class="nav-right">' +
            '<form class="nav-search" id="nav-search">' +
            '<input type="search" placeholder="Cari film, serial…" name="q" value="' + esc(active === 'search' ? (window.__lastQuery || '') : '') + '">' +
            '</form>' +
            '<div class="nav-user">' +
            '<button class="avatar-btn" id="avatar-btn" aria-label="Menu">' +
            esc(initialsOf(user.name || '?')) +
            '</button>' +
            '<div class="avatar-menu" id="avatar-menu" hidden>' +
            '<div class="avatar-menu-name">' + esc(user.name || '') + '</div>' +
            '<div class="avatar-menu-email">' + esc(user.email || '') + '</div>' +
            '<a href="#/settings">Ganti password</a>' +
            '<button type="button" id="logout-btn">Keluar</button>' +
            '</div></div>' +
            '</div></div></header>';
    }

    function initialsOf(name) {
        var parts = String(name).trim().split(/\s+/);
        var first = parts[0] ? parts[0].charAt(0) : '?';
        var last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';
        return (first + last).toUpperCase();
    }

    function bindNav(active) {
        var form = qs('#nav-search');
        if (form) {
            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                var q = new FormData(form).get('q');
                q = String(q || '').trim();
                if (q) navigate('#/search?q=' + encodeURIComponent(q));
            });
        }

        var btn = qs('#avatar-btn');
        var menu = qs('#avatar-menu');
        if (btn && menu) {
            btn.addEventListener('click', function (ev) {
                ev.stopPropagation();
                menu.hidden = !menu.hidden;
            });
            document.addEventListener('click', function close(ev) {
                if (menu && !menu.hidden && !menu.contains(ev.target) && ev.target !== btn) {
                    menu.hidden = true;
                }
            });
        }

        var out = qs('#logout-btn');
        if (out) {
            out.addEventListener('click', function () {
                api('/auth/logout', { method: 'POST', body: {} }).catch(function () {});
                setToken('');
                window.__user = null;
                navigate('#/login');
                route();
            });
        }
    }

    function ensureUser() {
        if (window.__user) return Promise.resolve(window.__user);
        return api('/me').then(function (data) {
            window.__user = data.user;
            return data.user;
        });
    }

    /* ------------------------------------------------------------------ *
     * Home
     * ------------------------------------------------------------------ */

    function rowHtml(title, items, linkFn, moreHref) {
        if (!items || !items.length) return '';
        var cards = items.map(function (item) {
            var href = linkFn(item);
            return '<a class="card" href="' + href + '" title="' + esc(item.title || '') + '">' +
                '<div class="card-poster">' + posterHtml(item) +
                (item.progress > 0 ? '<div class="card-progress"><i style="width:' + item.progress + '%"></i></div>' : '') +
                '</div>' +
                '<div class="card-title">' + esc(item.title || item.series_title || '') + '</div>' +
                '<div class="card-sub">' + esc(item.subtitle || yearLabel(item) || item.label || '') + '</div>' +
                '</a>';
        }).join('');

        return '<section class="row">' +
            '<div class="row-head"><h2>' + esc(title) + '</h2>' +
            (moreHref ? '<a class="row-more" href="' + moreHref + '">Lihat semua</a>' : '') +
            '</div>' +
            '<div class="row-track">' + cards + '</div>' +
            '</section>';
    }

    function renderHome() {
        ensureUser().then(function (user) {
            return api('/home');
        }).then(function (data) {
            var hero = data.movies && data.movies.length ? data.movies[0] : null;
            var heroHtml = '';
            if (hero) {
                var bg = hero.poster
                    ? 'style="background-image:url(\'' + esc(hero.poster) + '\')"'
                    : '';
                heroHtml =
                    '<section class="hero' + (hero.poster ? 'hero-img' : '') + '" ' + bg + '>' +
                    '<div class="hero-shade"></div>' +
                    '<div class="hero-body">' +
                    '<div class="hero-poster">' + posterHtml(hero) + '</div>' +
                    '<div class="hero-info">' +
                    '<div class="hero-badge">FILM PILIHAN</div>' +
                    '<h1>' + esc(hero.title) + '</h1>' +
                    '<div class="hero-meta">' + yearLabel(hero) + '</div>' +
                    '<p class="hero-overview">' + esc(truncate(hero.overview, 320)) + '</p>' +
                    '<div class="hero-actions">' +
                    '<a class="btn btn-primary" href="#/watch/m/' + hero.id + '">▶ Putar</a>' +
                    '<a class="btn btn-ghost" href="#/movie/' + hero.id + '">Detail</a>' +
                    '</div></div></div></section>';
            }

            var continueItems = (data.continue || []).map(function (c) {
                return {
                    id: c.id, type: c.type, title: c.title, subtitle: c.subtitle || yearLabel(c),
                    poster: c.poster, progress: c.progress,
                    href: c.type === 'movie' ? '#/watch/m/' + c.id : '#/watch/e/' + c.id
                };
            });

            var continueHtml = '';
            if (continueItems.length) {
                continueHtml = '<section class="row"><div class="row-head"><h2>Lanjutkan Menonton</h2></div>' +
                    '<div class="row-track">' + continueItems.map(function (c) {
                        return '<a class="card" href="' + c.href + '">' +
                            '<div class="card-poster">' +
                            (c.poster ? '<img class="poster-img" src="' + esc(c.poster) + '" alt="" loading="lazy">'
                                : posterFallback(c.title)) +
                            '<div class="card-progress"><i style="width:' + c.progress + '%"></i></div>' +
                            '<div class="card-play">▶</div>' +
                            '</div>' +
                            '<div class="card-title">' + esc(c.title) + '</div>' +
                            '<div class="card-sub">' + esc(c.subtitle || '') + '</div></a>';
                    }).join('') + '</div></section>';
            }

            var latestEp = (data.latest_episodes || []).map(function (e) {
                return {
                    id: e.episode_id, title: e.series_title,
                    subtitle: e.label + (e.ep_title ? ' · ' + e.ep_title : ''),
                    poster: e.poster, href: '#/watch/e/' + e.episode_id
                };
            });
            var latestHtml = latestEp.length
                ? '<section class="row"><div class="row-head"><h2>Episode Terbaru</h2></div><div class="row-track">' +
                latestEp.map(function (c) {
                    return '<a class="card" href="' + c.href + '">' +
                        '<div class="card-poster">' +
                        (c.poster ? '<img class="poster-img" src="' + esc(c.poster) + '" alt="" loading="lazy">' : posterFallback(c.title)) +
                        '<div class="card-play">▶</div></div>' +
                        '<div class="card-title">' + esc(c.title) + '</div>' +
                        '<div class="card-sub">' + esc(c.subtitle) + '</div></a>';
                }).join('') + '</div></section>'
                : '';

            var empty = (!data.movies || !data.movies.length) && (!data.series || !data.series.length);

            app.innerHTML = navbar('home') +
                '<main class="page">' +
                heroHtml +
                (empty ? emptyLibrary() :
                    continueHtml +
                    rowHtml('Film Terbaru', data.movies, function (m) { return '#/movie/' + m.id; }, '#/movies') +
                    rowHtml('Serial', data.series, function (s) { return '#/show/' + s.id; }, '#/series') +
                    latestHtml) +
                '</main>';

            bindNav('home');
        }).catch(handleError);
    }

    function emptyLibrary() {
        var user = window.__user || {};
        return '<div class="empty">' +
            '<div class="empty-icon">🎬</div>' +
            '<h2>Belum ada isinya</h2>' +
            '<p>Letakkan file film di <code>/diarflix/movie/Judul (Tahun)/</code> dan serial di <code>/diarflix/series/Judul/</code>, lalu jalankan scan' +
            (user.is_admin ? ' dari menu Admin.' : '.') + '</p>' +
            (user.is_admin ? '<a class="btn btn-primary" href="#/admin">Buka Admin</a>' : '') +
            '</div>';
    }

    function truncate(s, n) {
        s = String(s || '');
        if (s.length <= n) return s;
        return s.slice(0, n).replace(/\s+\S*$/, '') + '…';
    }

    /* ------------------------------------------------------------------ *
     * Grid pages
     * ------------------------------------------------------------------ */

    function renderGrid(type) {
        ensureUser().then(function () {
            var req = type === 'movie' ? api('/movies?limit=200') : api('/series');
            return req;
        }).then(function (data) {
            var items = data.items || [];
            var active = type === 'movie' ? 'movies' : 'series';
            var title = type === 'movie' ? 'Semua Film' : 'Semua Serial';

            var cards = items.map(function (item) {
                var href = type === 'movie' ? '#/movie/' + item.id : '#/show/' + item.id;
                return '<a class="card" href="' + href + '">' +
                    '<div class="card-poster">' + posterHtml(item) + '</div>' +
                    '<div class="card-title">' + esc(item.title) + '</div>' +
                    '<div class="card-sub">' + esc(yearLabel(item)) +
                    (item.episode_count != null ? ' · ' + item.episode_count + ' eps' : '') + '</div></a>';
            }).join('');

            app.innerHTML = navbar(active) +
                '<main class="page">' +
                '<h1 class="page-title">' + title + '</h1>' +
                (items.length
                    ? '<div class="grid">' + cards + '</div>'
                    : emptyLibrary()) +
                '</main>';
            bindNav(active);
        }).catch(handleError);
    }

    /* ------------------------------------------------------------------ *
     * Detail: movie
     * ------------------------------------------------------------------ */

    function renderMovieDetail(id) {
        if (!id) return renderNotFound();
        ensureUser().then(function () {
            return api('/movie/' + id);
        }).then(function (data) {
            var m = data.movie;
            var user = window.__user || {};
            var resume = m.progress && m.progress.position_ms > 10000 && !m.progress.finished
                ? m.progress.position_ms : 0;

            var adminHtml = user.is_admin ? adminMetaPanel('movie', m) : '';

            app.innerHTML = navbar('movies') +
                '<main class="page">' +
                '<div class="detail">' +
                '<div class="detail-poster">' + posterHtml(m) + '</div>' +
                '<div class="detail-info">' +
                '<div class="detail-type">FILM</div>' +
                '<h1>' + esc(m.title) + '</h1>' +
                '<div class="detail-meta">' + yearLabel(m) +
                (m.runtime_ms ? ' · ' + fmtTime(m.runtime_ms) : '') + '</div>' +
                '<p class="detail-overview">' + esc(m.overview || 'Belum ada deskripsi.') + '</p>' +
                '<div class="detail-actions">' +
                '<a class="btn btn-primary" href="#/watch/m/' + m.id + '">' +
                (resume ? '▶ Lanjutkan ' + fmtTime(resume) : '▶ Putar') + '</a>' +
                '</div>' +
                '</div></div>' +
                adminHtml +
                '</main>';
            bindNav('movies');
            if (user.is_admin) bindAdminMeta('movie', m.id);
        }).catch(handleError);
    }

    /* ------------------------------------------------------------------ *
     * Detail: series
     * ------------------------------------------------------------------ */

    function renderSeriesDetail(id) {
        if (!id) return renderNotFound();
        ensureUser().then(function () {
            return api('/series/' + id);
        }).then(function (data) {
            var s = data.series;
            var user = window.__user || {};

            var seasonsHtml = (s.seasons || []).map(function (season, idx) {
                var eps = season.episodes.map(function (ep) {
                    return '<li class="ep' + (ep.finished ? ' ep-done' : '') + '">' +
                        '<a class="ep-play" href="#/watch/e/' + ep.id + '">▶</a>' +
                        '<div class="ep-body">' +
                        '<div class="ep-title">' + ep.episode_no + '. ' + esc(ep.title) + '</div>' +
                        (ep.progress > 0 ? '<div class="ep-progress"><i style="width:' + ep.progress + '%"></i></div>' : '') +
                        '</div>' +
                        '<div class="ep-dur">' + (ep.duration_ms ? fmtTime(ep.duration_ms) : '') + '</div>' +
                        '</li>';
                }).join('');
                return '<div class="season" data-season="' + season.season_no + '"' + (idx > 0 ? ' hidden' : '') + '>' +
                    '<ul class="ep-list">' + eps + '</ul></div>';
            }).join('');

            var tabs = (s.seasons || []).map(function (season, idx) {
                return '<button class="season-tab' + (idx === 0 ? ' active' : '') + '" data-season="' + season.season_no + '">' +
                    esc(season.name || ('Season ' + season.season_no)) + '</button>';
            }).join('');

            var adminHtml = user.is_admin ? adminMetaPanel('series', s) : '';

            app.innerHTML = navbar('series') +
                '<main class="page">' +
                '<div class="detail">' +
                '<div class="detail-poster">' + posterHtml(s) + '</div>' +
                '<div class="detail-info">' +
                '<div class="detail-type">SERIAL</div>' +
                '<h1>' + esc(s.title) + '</h1>' +
                '<div class="detail-meta">' + yearLabel(s) + '</div>' +
                '<p class="detail-overview">' + esc(s.overview || 'Belum ada deskripsi.') + '</p>' +
                '</div></div>' +
                (s.seasons && s.seasons.length
                    ? '<div class="seasons"><div class="season-tabs">' + tabs + '</div>' + seasonsHtml + '</div>'
                    : '<p class="muted">Belum ada episode ter-scan.</p>') +
                adminHtml +
                '</main>';
            bindNav('series');

            qsa('.season-tab').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    qsa('.season-tab').forEach(function (t) { t.classList.remove('active'); });
                    tab.classList.add('active');
                    qsa('.season').forEach(function (div) {
                        div.hidden = div.getAttribute('data-season') !== tab.getAttribute('data-season');
                    });
                });
            });

            if (user.is_admin) bindAdminMeta('series', s.id);
        }).catch(handleError);
    }

    /* ------------------------------------------------------------------ *
     * Search
     * ------------------------------------------------------------------ */

    function renderSearch(q) {
        window.__lastQuery = q;
        ensureUser().then(function () {
            if (!q) return { movies: [], series: [], episodes: [], query: '' };
            return api('/search?q=' + encodeURIComponent(q));
        }).then(function (data) {
            var movies = data.movies || [];
            var series = data.series || [];
            var episodes = data.episodes || [];
            var total = movies.length + series.length + episodes.length;

            var sections = '';
            if (movies.length) {
                sections += '<h2 class="page-title">Film</h2><div class="grid">' +
                    movies.map(function (m) {
                        return '<a class="card" href="#/movie/' + m.id + '">' +
                            '<div class="card-poster">' + posterHtml(m) + '</div>' +
                            '<div class="card-title">' + esc(m.title) + '</div>' +
                            '<div class="card-sub">' + esc(yearLabel(m)) + '</div></a>';
                    }).join('') + '</div>';
            }
            if (series.length) {
                sections += '<h2 class="page-title">Serial</h2><div class="grid">' +
                    series.map(function (s) {
                        return '<a class="card" href="#/show/' + s.id + '">' +
                            '<div class="card-poster">' + posterHtml(s) + '</div>' +
                            '<div class="card-title">' + esc(s.title) + '</div>' +
                            '<div class="card-sub">' + esc(yearLabel(s)) + '</div></a>';
                    }).join('') + '</div>';
            }
            if (episodes.length) {
                sections += '<h2 class="page-title">Episode</h2><div class="grid">' +
                    episodes.map(function (e) {
                        return '<a class="card" href="#/watch/e/' + e.episode_id + '">' +
                            '<div class="card-poster">' +
                            (e.poster ? '<img class="poster-img" src="' + esc(e.poster) + '" alt="" loading="lazy">' : posterFallback(e.series_title)) +
                            '</div>' +
                            '<div class="card-title">' + esc(e.series_title) + '</div>' +
                            '<div class="card-sub">' + esc(e.label + ' · ' + e.ep_title) + '</div></a>';
                    }).join('') + '</div>';
            }

            app.innerHTML = navbar('search') +
                '<main class="page">' +
                '<h1 class="page-title">Hasil: "' + esc(q) + '"' + (q ? ' (' + total + ')' : '') + '</h1>' +
                (q && total === 0 ? '<div class="empty"><div class="empty-icon">🔎</div><p>Tidak ada hasil untuk "' + esc(q) + '".</p></div>' : '') +
                sections +
                '</main>';
            bindNav('search');
        }).catch(handleError);
    }

    /* ------------------------------------------------------------------ *
     * Player
     * ------------------------------------------------------------------ */

    function renderPlayer(kind, id) {
        if (!kind || !id) return renderNotFound();
        var isMovie = kind === 'm';

        ensureUser().then(function () {
            return isMovie ? api('/movie/' + id) : api('/episode/' + id);
        }).then(function (data) {
            var item = isMovie ? data.movie : data.episode;
            var title = isMovie ? item.title : item.series_title;
            var subtitle = isMovie ? '' : 'S' + pad2(item.season_no) + 'E' + pad2(item.episode_no) + ' · ' + item.title;
            var subs = item.subtitles || [];
            var progress = item.progress;

            var tracks = subs.map(function (s, i) {
                return '<track kind="subtitles" src="' + esc(s.url) + '" srclang="' + esc(s.lang) + '" label="' + esc(s.label) + '"' + (i === 0 ? ' default' : '') + '>';
            }).join('');

            var resumeAt = progress && progress.position_ms > 10000 && !progress.finished ? progress.position_ms : 0;

            app.innerHTML =
                '<div class="player-wrap">' +
                '<video id="player" controls autoplay playsinline>' +
                '<source src="' + esc(item.url) + '">' +
                tracks +
                '</video>' +
                '<div class="player-top">' +
                '<a class="player-back" href="' + (isMovie ? '#/movie/' + id : '#/show/' + item.series_id) + '" aria-label="Kembali">‹</a>' +
                '<div class="player-title"><strong>' + esc(title) + '</strong>' +
                (subtitle ? '<span>' + esc(subtitle) + '</span>' : '') + '</div>' +
                '</div>' +
                (resumeAt ? '<div class="player-resume" id="player-resume">' +
                    '<p>Lanjutkan dari ' + fmtTime(resumeAt) + '?</p>' +
                    '<div><button class="btn btn-primary" id="btn-resume">Lanjutkan</button>' +
                    '<button class="btn btn-ghost" id="btn-restart">Mulai dari awal</button></div></div>' : '') +
                '</div>';

            var video = qs('#player');
            var started = false;
            var lastSave = 0;
            var type = isMovie ? 'movie' : 'episode';

            function saveProgress(force) {
                if (!video.duration || !isFinite(video.duration)) return;
                var now = Date.now();
                if (!force && now - lastSave < 10000) return;
                lastSave = now;
                api('/progress', {
                    method: 'POST',
                    body: {
                        item_type: type,
                        item_id: id,
                        position_ms: Math.floor(video.currentTime * 1000),
                        duration_ms: Math.floor(video.duration * 1000)
                    }
                }).catch(function () {});
            }

            function maybeResume() {
                if (started || !resumeAt) return;
                started = true;
                video.currentTime = resumeAt / 1000;
                var box = qs('#player-resume');
                if (box) box.remove();
            }

            var btnResume = qs('#btn-resume');
            if (btnResume) {
                btnResume.addEventListener('click', function () {
                    maybeResume();
                    video.play();
                });
            }
            var btnRestart = qs('#btn-restart');
            if (btnRestart) {
                btnRestart.addEventListener('click', function () {
                    resumeAt = 0;
                    started = true;
                    var box = qs('#player-resume');
                    if (box) box.remove();
                    video.currentTime = 0;
                    video.play();
                });
            }
            if (!resumeAt) {
                video.addEventListener('loadedmetadata', function () { started = true; }, { once: true });
            }

            video.addEventListener('timeupdate', function () { saveProgress(false); });
            video.addEventListener('pause', function () { saveProgress(true); });
            video.addEventListener('ended', function () { saveProgress(true); });

            function onHide() {
                if (document.visibilityState === 'hidden') saveProgress(true);
            }
            document.addEventListener('visibilitychange', onHide);
            window.addEventListener('beforeunload', function () { saveProgress(true); });

            currentCleanup = function () {
                document.removeEventListener('visibilitychange', onHide);
                saveProgress(true);
                try { video.pause(); } catch (e) {}
            };
        }).catch(handleError);
    }

    function pad2(n) { n = parseInt(n, 10) || 0; return n < 10 ? '0' + n : '' + n; }

    /* ------------------------------------------------------------------ *
     * Admin
     * ------------------------------------------------------------------ */

    function adminMetaPanel(type, item) {
        return '<section class="admin-panel">' +
            '<h2>Edit metadata (admin)</h2>' +
            '<form id="meta-form" class="form-grid">' +
            '<label>Judul<input name="title" value="' + esc(item.title) + '" required maxlength="255"></label>' +
            '<label>Tahun<input name="year" type="number" min="1900" max="2100" value="' + esc(item.year || '') + '"></label>' +
            '<label>Genre<input name="genres" value="' + esc(item.genres || '') + '" placeholder="Aksi, Drama"></label>' +
            '<label class="full">Deskripsi<textarea name="overview" rows="5">' + esc(item.overview || '') + '</textarea></label>' +
            '<label class="full">Poster (direct link gambar, mis. dari drive)<input name="poster" type="url" value="' +
            esc(isAbsoluteUrl(item.poster) ? item.poster : '') +
            '" placeholder="https://drive.diaraji.my.id/share/…" inputmode="url"></label>' +
            '<div class="full form-actions">' +
            '<button class="btn btn-primary" type="submit">Simpan</button>' +
            (item.poster ? '<button class="btn btn-danger" type="button" id="poster-del">Hapus poster</button>' : '') +
            '</div>' +
            '<div class="full poster-preview" id="poster-preview">' + posterHtml(item) + '</div>' +
            '</form>' +
            '<p class="muted">Kosongkan field poster untuk memakai poster dari folder media / inisial judul.</p>' +
            '</section>';
    }

    function isAbsoluteUrl(s) {
        return /^https?:\/\//i.test(String(s || ''));
    }

    function bindAdminMeta(type, id) {
        var form = qs('#meta-form');
        if (form) {
            // Live preview saat mengetik URL poster.
            var posterInput = qs('input[name=poster]', form);
            if (posterInput) {
                posterInput.addEventListener('input', function () {
                    var box = qs('#poster-preview');
                    if (!box) return;
                    var v = posterInput.value.trim();
                    box.innerHTML = isAbsoluteUrl(v)
                        ? '<img class="poster-img" src="' + esc(v) + '" alt="">'
                        : posterFallback(qs('input[name=title]', form).value || '?');
                });
            }

            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                var fd = new FormData(form);
                var body = {
                    title: fd.get('title'),
                    year: fd.get('year') || null,
                    genres: fd.get('genres'),
                    overview: fd.get('overview'),
                    poster: String(fd.get('poster') || '').trim()
                };
                api('/admin/' + type + '/' + id, { method: 'PATCH', body: body })
                    .then(function () { toast('Tersimpan'); route(); })
                    .catch(function (e) { toast(e.message, true); });
            });
        }

        var del = qs('#poster-del');
        if (del) {
            del.addEventListener('click', function () {
                if (!confirm('Hapus poster?')) return;
                api('/admin/' + type + '/' + id + '/poster', { method: 'DELETE' })
                    .then(function () { toast('Poster dihapus'); route(); })
                    .catch(function (e) { toast(e.message, true); });
            });
        }
    }

    function renderAdmin() {
        ensureUser().then(function (user) {
            if (!user.is_admin) {
                app.innerHTML = navbar('') + '<main class="page"><div class="empty"><div class="empty-icon">🔒</div><p>Hanya admin.</p></div></main>';
                bindNav('');
                return null;
            }
            return Promise.all([api('/admin/users'), api('/movies?limit=1'), api('/series')]);
        }).then(function (data) {
            if (!data) return;
            var users = data[0].items || [];
            var movieCount = data[1].total || 0;
            var seriesCount = (data[2].items || []).length;

            app.innerHTML = navbar('admin') +
                '<main class="page">' +
                '<h1 class="page-title">Admin</h1>' +
                '<div class="admin-tabs">' +
                '<button class="admin-tab active" data-tab="library">Perpustakaan</button>' +
                '<button class="admin-tab" data-tab="users">Pengguna</button>' +
                '</div>' +

                '<section class="admin-section" id="tab-library">' +
                '<div class="stat-cards">' +
                '<div class="stat"><span>' + movieCount + '</span>Film</div>' +
                '<div class="stat"><span>' + seriesCount + '</span>Serial</div>' +
                '<div class="stat"><span>' + users.length + '</span>User</div>' +
                '</div>' +
                '<div class="scan-box">' +
                '<h2>Scan library</h2>' +
                '<p class="muted">Baca ulang folder <code>/diarflix/movie</code> dan <code>/diarflix/series</code>. File baru masuk, yang hilang dihapus dari DB. Poster &amp; deskripsi yang kamu edit tidak disentuh scan. Video yang belum standar (di atas 480p, codec lain, dst) masuk antrean convert — jalankan lewat tombol di bawah.</p>' +
                '<div class="form-actions">' +
                '<button class="btn btn-primary" id="scan-btn">Scan sekarang</button>' +
                '<label class="chk"><input type="checkbox" id="scan-full"> Full (baca ulang semua)</label>' +
                '</div>' +
                '<div class="form-actions">' +
                '<button class="btn" id="transcode-btn">Convert semua ke x264 480p</button>' +
                '<span class="muted" id="transcode-status"></span>' +
                '</div>' +
                '<pre class="scan-log" id="scan-log" hidden></pre>' +
                '</div></section>' +

                '<section class="admin-section" id="tab-users" hidden>' +
                '<h2>Pengguna</h2>' +
                '<form id="user-form" class="form-grid">' +
                '<label>Nama<input name="name" required maxlength="80"></label>' +
                '<label>Email<input name="email" type="email" required></label>' +
                '<label>Password<input name="password" type="password" minlength="6" required placeholder="minimal 6 karakter"></label>' +
                '<label class="chk"><input type="checkbox" name="is_admin"> Admin</label>' +
                '<div class="full form-actions"><button class="btn btn-primary" type="submit">Tambah user</button></div>' +
                '</form>' +
                '<div class="table-wrap"><table class="table">' +
                '<thead><tr><th>#</th><th>Nama</th><th>Email</th><th>Role</th><th></th></tr></thead>' +
                '<tbody id="user-rows">' +
                users.map(function (u) {
                    return '<tr data-id="' + u.id + '">' +
                        '<td>' + u.id + '</td>' +
                        '<td>' + esc(u.name) + '</td>' +
                        '<td>' + esc(u.email) + '</td>' +
                        '<td>' + (u.is_admin ? '<span class="badge badge-admin">admin</span>' : 'user') + '</td>' +
                        '<td class="row-actions">' +
                        '<button class="btn btn-mini" data-act="edit">Edit</button>' +
                        '<button class="btn btn-mini btn-danger" data-act="del">Hapus</button>' +
                        '</td></tr>';
                }).join('') +
                '</tbody></table></div></section>' +
                '</main>';

            bindNav('admin');
            bindAdmin();
        }).catch(handleError);
    }

    function bindAdmin() {
        qsa('.admin-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                qsa('.admin-tab').forEach(function (t) { t.classList.remove('active'); });
                tab.classList.add('active');
                qs('#tab-library').hidden = tab.dataset.tab !== 'library';
                qs('#tab-users').hidden = tab.dataset.tab !== 'users';
            });
        });

        var scanBtn = qs('#scan-btn');
        if (scanBtn) {
            scanBtn.addEventListener('click', function () {
                var full = qs('#scan-full').checked;
                var log = qs('#scan-log');
                log.hidden = false;
                log.textContent = 'Menjalankan scan…';
                scanBtn.disabled = true;
                api('/admin/scan', { method: 'POST', body: { full: full } })
                    .then(function (data) {
                        log.textContent = data.output || '(tanpa output)';
                        toast('Scan selesai');
                    })
                    .catch(function (e) {
                        log.textContent = e.message;
                        toast('Scan gagal', true);
                    })
                    .then(function () { scanBtn.disabled = false; });
            });
        }

        var tcBtn = qs('#transcode-btn');
        var tcTimer = null;

        function fmtDurSec(s) {
            var h = Math.floor(s / 3600);
            var m = Math.round((s % 3600) / 60);
            return h > 0 ? h + ' jam ' + m + ' mnt' : m + ' mnt';
        }

        function showTc(data) {
            var st = qs('#transcode-status');
            if (!st) return;
            if (data.error) {
                st.textContent = data.error;
                return;
            }
            if (data.running) {
                var p = data.progress || {};
                var txt;
                if (p.current && p.percent !== null && p.percent !== undefined) {
                    txt = p.current + ' — ' + p.percent + '%';
                    if (p.fps) txt += ' · ' + p.fps + ' fps';
                    if (p.eta_seconds) txt += ' · sisa ' + fmtDurSec(p.eta_seconds);
                } else {
                    txt = 'Convert berjalan…';
                }
                if (data.queued > 1) txt += ' · antrean ' + data.queued + ' file';
                st.textContent = txt + ' (klik untuk refresh)';
                tcPoll();
                return;
            }
            if (data.spawned) {
                st.textContent = 'Convert dijalankan (' + data.queued + ' file, satu per satu)';
                tcPoll();
                return;
            }
            st.textContent = data.queued > 0
                ? data.queued + ' file belum standar — klik untuk mulai'
                : 'Antrean kosong — klik Convert untuk memindai library';
        }

        function tcPoll() {
            if (tcTimer) return;
            tcTimer = setTimeout(function () {
                tcTimer = null;
                if (!qs('#transcode-btn')) return;
                api('/admin/transcode', { method: 'POST', body: {} })
                    .then(showTc)
                    .catch(function () {});
            }, 20000);
        }

        if (tcBtn) {
            tcBtn.addEventListener('click', function () {
                var st = qs('#transcode-status');
                tcBtn.disabled = true;
                st.textContent = 'Memulai pindai library…';
                api('/admin/transcode', { method: 'POST', body: { start: true } })
                    .then(function (data) {
                        showTc(data);
                        if (data.error) toast(data.error, true);
                        else if (data.spawned) toast('Convert dijalankan — memindai library…');
                        else if (data.running) toast('Convert sedang berjalan');
                        else toast(data.queued > 0 ? data.queued + ' file dalam antrean' : 'Tidak ada yang perlu di-convert');
                    })
                    .catch(function (e) {
                        st.textContent = e.message;
                        toast('Convert gagal', true);
                    })
                    .then(function () { tcBtn.disabled = false; });
            });

            // Muat status antrean saat halaman admin dibuka.
            api('/admin/transcode', { method: 'POST', body: {} })
                .then(showTc)
                .catch(function () {});
        }

        var userForm = qs('#user-form');
        if (userForm) {
            userForm.addEventListener('submit', function (ev) {
                ev.preventDefault();
                var fd = new FormData(userForm);
                api('/admin/users', {
                    method: 'POST',
                    body: {
                        name: fd.get('name'),
                        email: fd.get('email'),
                        password: fd.get('password'),
                        is_admin: !!fd.get('is_admin')
                    }
                }).then(function () {
                    toast('User ditambahkan');
                    route();
                }).catch(function (e) { toast(e.message, true); });
            });
        }

        var rows = qs('#user-rows');
        if (rows) {
            rows.addEventListener('click', function (ev) {
                var btn = ev.target.closest('button[data-act]');
                if (!btn) return;
                var tr = btn.closest('tr');
                var id = parseInt(tr.dataset.id, 10);
                var name = tr.children[1].textContent;
                var email = tr.children[2].textContent;

                if (btn.dataset.act === 'del') {
                    if (!confirm('Hapus user "' + name + '"?')) return;
                    api('/admin/users/' + id, { method: 'DELETE' })
                        .then(function () { toast('User dihapus'); route(); })
                        .catch(function (e) { toast(e.message, true); });
                    return;
                }

                // Edit prompt-based (kecil & tanpa modal berat).
                var newName = prompt('Nama:', name);
                if (newName === null) return;
                var newEmail = prompt('Email:', email);
                if (newEmail === null) return;
                var newPass = prompt('Password baru (kosongkan untuk tetap):', '');
                if (newPass === null) return;
                var makeAdmin = confirm('Jadikan admin? OK = admin, Batal = user biasa.\n(sekarang: ' + (tr.children[3].textContent.trim() === 'admin' ? 'admin' : 'user') + ')');

                api('/admin/users/' + id, {
                    method: 'PATCH',
                    body: {
                        name: newName,
                        email: newEmail,
                        password: newPass || undefined,
                        is_admin: makeAdmin
                    }
                }).then(function () {
                    toast('User diperbarui');
                    route();
                }).catch(function (e) { toast(e.message, true); });
            });
        }
    }

    /* ------------------------------------------------------------------ *
     * Settings
     * ------------------------------------------------------------------ */

    function renderSettings() {
        ensureUser().then(function (user) {
            app.innerHTML = navbar('') +
                '<main class="page page-narrow">' +
                '<h1 class="page-title">Pengaturan</h1>' +
                '<section class="admin-panel">' +
                '<h2>Ganti password</h2>' +
                '<p class="muted">' + esc(user.email) + '</p>' +
                '<form id="pw-form" class="form-grid">' +
                '<label class="full">Password saat ini<input name="current_password" type="password" required autocomplete="current-password"></label>' +
                '<label class="full">Password baru<input name="new_password" type="password" required minlength="6" autocomplete="new-password"></label>' +
                '<div class="full form-actions"><button class="btn btn-primary" type="submit">Simpan</button></div>' +
                '</form></section></main>';
            bindNav('');

            qs('#pw-form').addEventListener('submit', function (ev) {
                ev.preventDefault();
                var fd = new FormData(ev.target);
                api('/auth/password', {
                    method: 'POST',
                    body: {
                        current_password: fd.get('current_password'),
                        new_password: fd.get('new_password')
                    }
                }).then(function () {
                    toast('Password diganti');
                    ev.target.reset();
                }).catch(function (e) { toast(e.message, true); });
            });
        }).catch(handleError);
    }

    /* ------------------------------------------------------------------ *
     * Misc views
     * ------------------------------------------------------------------ */

    function renderNotFound() {
        app.innerHTML = navbar('') +
            '<main class="page"><div class="empty"><div class="empty-icon">🤷</div>' +
            '<h2>Halaman tidak ditemukan</h2>' +
            '<a class="btn btn-primary" href="#/">Ke Beranda</a></div></main>';
        bindNav('');
    }

    function handleError(err) {
        if (err && err.status === 401) return;
        app.innerHTML = navbar('') +
            '<main class="page"><div class="empty"><div class="empty-icon">⚠️</div>' +
            '<h2>Gagal memuat</h2><p>' + esc(err && err.message ? err.message : 'Kesalahan tidak diketahui') + '</p>' +
            '<button class="btn btn-primary" onclick="location.reload()">Coba lagi</button></div></main>';
        bindNav('');
    }

    /* ------------------------------------------------------------------ *
     * Boot
     * ------------------------------------------------------------------ */

    function boot() {
        if (!getToken()) {
            route();
            return;
        }
        ensureUser().then(function () {
            route();
        }).catch(function () {
            setToken('');
            route();
        });
    }

    boot();
})();
