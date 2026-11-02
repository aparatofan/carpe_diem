/*
 * Carpe Diem – photo albums (6.6)
 *
 *   sdcAlbum.renderCollage(el, photos, { date, title, onOpen })  – magazine collage for the day view
 *   sdcAlbum.openViewer(photos, index, { date, onOpenDay })       – full-screen viewer
 *   sdcAlbum.openAlbum(photos, { date, title, onOpenDay })        – the collage in its own window (6.9)
 *   sdcAlbum.createEditor(gridEl, hiddenInput, onChange)          – photo manager in the edit form
 *
 * A photo is { id, url, large, thumb, w, h, caption }. The first photo is the cover.
 * Everything is built with DOM methods and textContent, never from caption HTML.
 */
(function () {
    'use strict';

    const MAX_TILES = 24;          // more than this → the last tile shows "+N"
    const MONO_KEY  = 'sdc_album_mono';

    // ---------- small helpers ----------
    function el(tag, cls, text) {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }

    // Same layout every time for the same day: a tiny seeded random generator.
    function seeded(seedStr) {
        let h = 2166136261;
        for (let i = 0; i < seedStr.length; i++) { h ^= seedStr.charCodeAt(i); h = Math.imul(h, 16777619); }
        return function () {
            h += 0x6D2B79F5;
            let t = h;
            t = Math.imul(t ^ (t >>> 15), t | 1);
            t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
            return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
        };
    }
    const pick = (rnd, list) => list[Math.floor(rnd() * list.length)];

    function shape(p) {
        if (!p.w || !p.h) return 'unknown';
        const r = p.w / p.h;
        if (r > 1.15) return 'land';
        if (r < 0.87) return 'port';
        return 'square';
    }

    function niceDate(dateStr) {
        const d = new Date(dateStr + 'T12:00:00');
        return {
            day: String(d.getDate()).padStart(2, '0'),
            month: d.toLocaleDateString('en-GB', { month: 'long' }),
            year: String(d.getFullYear()),
            weekday: d.toLocaleDateString('en-GB', { weekday: 'long' }),
            long: d.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
        };
    }

    function readMono() {
        try { const v = localStorage.getItem(MONO_KEY); return v === null ? true : v === '1'; } catch (e) { return true; }
    }
    function writeMono(on) {
        try { localStorage.setItem(MONO_KEY, on ? '1' : '0'); } catch (e) { /* private window – fine */ }
    }

    // ---------- tile sizes (columns × rows) ----------
    // 12-column grid on wide screens, 6 on phones. Rows are square cells.
    const SIZES = {
        12: {
            coverOnly: { land: [8, 5], port: [5, 7], square: [6, 6], unknown: [7, 5] },
            cover:     { land: [6, 4], port: [4, 6], square: [5, 5], unknown: [5, 4] },
            land:   [[4, 3], [3, 2], [5, 3], [4, 3], [3, 2]],
            port:   [[3, 4], [2, 3], [3, 4], [3, 5], [2, 3]],
            square: [[3, 3], [2, 2], [4, 4], [3, 3]],
            unknown:[[3, 3], [4, 3], [3, 4], [2, 2]],
            date: [3, 3]
        },
        6: {
            coverOnly: { land: [6, 4], port: [4, 6], square: [5, 5], unknown: [6, 4] },
            cover:     { land: [6, 4], port: [4, 5], square: [4, 4], unknown: [6, 4] },
            land:   [[3, 2], [4, 3], [3, 2]],
            port:   [[2, 3], [3, 4], [2, 3]],
            square: [[2, 2], [3, 3], [2, 2]],
            unknown:[[3, 3], [2, 2], [3, 2]],
            date: [2, 3]
        }
    };

    // Composed pages for small albums on wide screens (the free flow leaves holes there).
    // Each entry: [first column, columns, first row, rows]. The date owns columns 10–12, rows 1–3.
    const TEMPLATES = {
        2: [[1, 7, 1, 6], [8, 5, 4, 5]],
        3: [[1, 6, 1, 6], [7, 3, 1, 4], [7, 6, 5, 4]],
        4: [[1, 6, 1, 5], [7, 3, 1, 4], [7, 6, 5, 4], [2, 5, 6, 3]]
    };

    // =====================================================================
    // COLLAGE
    // =====================================================================
    function renderCollage(container, photos, opts) {
        opts = opts || {};
        container.innerHTML = '';
        if (!photos || !photos.length) return;

        const date = niceDate(opts.date || new Date().toISOString().slice(0, 10));
        const rnd = seeded((opts.date || '') + '|' + photos.length);

        const root = el('section', 'sdc-collage');
        if (readMono()) root.classList.add('is-mono');
        root.setAttribute('aria-label', (opts.title ? opts.title + ' – ' : '') + photos.length + (photos.length === 1 ? ' photo' : ' photos'));

        const stage = el('div', 'sdc-collage-stage');
        const grid = el('div', 'sdc-collage-grid');
        stage.appendChild(grid);

        // Date in the corner – always the top-right cell block.
        const dateBox = el('div', 'sdc-collage-date');
        dateBox.appendChild(el('span', 'sdc-cd-day', date.day));
        dateBox.appendChild(el('span', 'sdc-cd-month', date.month));
        dateBox.appendChild(el('span', 'sdc-cd-year', date.year));
        dateBox.appendChild(el('span', 'sdc-cd-weekday', date.weekday));
        grid.appendChild(dateBox);

        const visible = photos.slice(0, MAX_TILES);
        const hidden = photos.length - visible.length;
        const tiles = [];
        // Each tile gets its own size pick and tiny offset, decided once.
        const picks = visible.map(() => ({ k: rnd(), dx: Math.round((rnd() - 0.5) * 12), dy: Math.round((rnd() - 0.5) * 12) }));

        visible.forEach((p, i) => {
            const fig = el('figure', 'sdc-collage-tile' + (i === 0 ? ' is-cover' : ''));
            const btn = el('button', 'sdc-collage-open');
            btn.type = 'button';
            btn.setAttribute('aria-label', 'Open photo ' + (i + 1) + (p.caption ? ': ' + p.caption : ''));
            const img = el('img');
            img.loading = i < 4 ? 'eager' : 'lazy';
            img.decoding = 'async';
            img.alt = p.caption || '';
            img.src = p.large || p.url;
            img.addEventListener('load', function () {
                // Old single pictures have no stored size – learn it now and relayout once.
                if (!p.w || !p.h) { p.w = img.naturalWidth; p.h = img.naturalHeight; scheduleLayout(); }
            });
            btn.appendChild(img);
            if (i === visible.length - 1 && hidden > 0) {
                fig.classList.add('has-more');
                btn.appendChild(el('span', 'sdc-collage-more', '+' + hidden));
            }
            btn.addEventListener('click', () => openViewer(photos, i, { date: opts.date }));
            fig.appendChild(btn);
            fig.style.setProperty('--dx', picks[i].dx + 'px');
            fig.style.setProperty('--dy', picks[i].dy + 'px');
            grid.appendChild(fig);
            tiles.push({ fig: fig, p: p, pick: picks[i] });
        });

        // Decorations: one ring, two hairlines, a pair of chevrons.
        const deco = el('div', 'sdc-collage-deco');
        deco.setAttribute('aria-hidden', 'true');
        const ring = el('span', 'sdc-deco-ring');
        ring.style.left = (8 + rnd() * 40) + '%';
        ring.style.top = (25 + rnd() * 45) + '%';
        deco.appendChild(ring);
        const ring2 = el('span', 'sdc-deco-ring is-small');
        ring2.style.left = (55 + rnd() * 30) + '%';
        ring2.style.top = (10 + rnd() * 60) + '%';
        deco.appendChild(ring2);
        for (let i = 0; i < 2; i++) {
            const line = el('span', 'sdc-deco-line');
            line.style.left = (rnd() * 55) + '%';
            line.style.top = (15 + i * 45 + rnd() * 25) + '%';
            line.style.width = (18 + rnd() * 18) + '%';
            deco.appendChild(line);
        }
        const chevL = el('span', 'sdc-deco-chev', '«'); chevL.style.left = '0'; chevL.style.top = (35 + rnd() * 20) + '%';
        const chevR = el('span', 'sdc-deco-chev', '»'); chevR.style.right = '0'; chevR.style.top = (55 + rnd() * 25) + '%';
        deco.appendChild(chevL); deco.appendChild(chevR);
        stage.appendChild(deco);

        // Header above the photos: title, count, B&W switch.
        const head = el('div', 'sdc-collage-head');
        const label = el('div', 'sdc-collage-label');
        if (opts.title) label.appendChild(el('h4', 'sdc-collage-title', opts.title));
        label.appendChild(el('span', 'sdc-collage-count', photos.length + (photos.length === 1 ? ' photo' : ' photos')));
        head.appendChild(label);
        const monoBtn = el('button', 'sdc-collage-mono');
        monoBtn.type = 'button';
        const setMonoLabel = () => {
            const on = root.classList.contains('is-mono');
            monoBtn.textContent = on ? 'Show in colour' : 'Black and white';
            monoBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
        };
        monoBtn.addEventListener('click', () => {
            root.classList.toggle('is-mono');
            writeMono(root.classList.contains('is-mono'));
            setMonoLabel();
        });
        setMonoLabel();
        head.appendChild(monoBtn);
        root.appendChild(head);
        root.appendChild(stage);

        container.appendChild(root);

        // ---- layout (needs the real width) ----
        let raf = 0;
        function scheduleLayout() { cancelAnimationFrame(raf); raf = requestAnimationFrame(layout); }
        // Shake guard (6.9.1): a taller collage can make the window show a scrollbar,
        // which makes the grid narrower; the narrower layout can then be short enough
        // for the scrollbar to vanish – and round it goes. When the width flips
        // straight back to the one before (a scrollbar's worth), keep the narrower
        // layout, which fits either way. A real window resize gives new widths.
        let lastW = 0, prevW = 0;
        function layout() {
            const width = grid.clientWidth;
            if (!width) return;
            if (width === prevW && width > lastW && width - lastW <= 40) return;
            prevW = lastW;
            lastW = width;
            const cols = width < 560 ? 6 : 12;
            const S = SIZES[cols];
            const gap = cols === 12 ? 10 : 6;
            const cell = (width - gap * (cols - 1)) / cols;
            grid.style.setProperty('--cols', cols);
            grid.style.setProperty('--cell', cell + 'px');
            grid.style.setProperty('--gap', gap + 'px');

            dateBox.style.gridColumn = (cols - S.date[0] + 1) + ' / span ' + S.date[0];
            dateBox.style.gridRow = '1 / span ' + S.date[1];

            const template = cols === 12 ? TEMPLATES[tiles.length] : null;
            tiles.forEach((t, i) => {
                if (template) {
                    const c = template[i];
                    t.fig.style.gridColumn = c[0] + ' / span ' + c[1];
                    t.fig.style.gridRow = c[2] + ' / span ' + c[3];
                    return;
                }
                const sh = shape(t.p);
                let size;
                if (i === 0) size = (tiles.length === 1 ? S.coverOnly : S.cover)[sh];
                else {
                    const list = S[sh];
                    size = list[Math.floor(t.pick.k * list.length)];
                    if (tiles.length <= 3) size = [Math.min(cols, size[0] + 1), size[1] + 1]; // few photos → bigger
                }
                t.fig.style.gridColumn = 'span ' + Math.min(cols, size[0]);
                t.fig.style.gridRow = 'span ' + size[1];
            });
        }
        layout();
        if (window.ResizeObserver) {
            const ro = new ResizeObserver(scheduleLayout);
            ro.observe(grid);
        } else {
            window.addEventListener('resize', scheduleLayout);
        }
    }

    // =====================================================================
    // FULL-SCREEN VIEWER
    // =====================================================================
    let viewer = null;
    let state = { photos: [], index: 0, opts: {} };

    function buildViewer() {
        const host = document.getElementById('sdc-app') || document.body; // the lock removes #sdc-app
        const v = el('div', 'sdc-viewer');
        v.setAttribute('role', 'dialog');
        v.setAttribute('aria-modal', 'true');
        v.setAttribute('aria-label', 'Photo viewer');
        v.innerHTML =
            '<div class="sdc-viewer-top">' +
                '<span class="sdc-viewer-date"></span>' +
                '<span class="sdc-viewer-count"></span>' +
                '<span class="sdc-viewer-actions">' +
                    '<button type="button" class="sdc-viewer-openday">Open day</button>' +
                    '<button type="button" class="sdc-viewer-close" aria-label="Close">&times;</button>' +
                '</span>' +
            '</div>' +
            '<div class="sdc-viewer-stage">' +
                '<button type="button" class="sdc-viewer-nav is-prev" aria-label="Previous photo">&#8249;</button>' +
                '<figure class="sdc-viewer-figure"><img alt=""><figcaption class="sdc-viewer-caption"></figcaption></figure>' +
                '<button type="button" class="sdc-viewer-nav is-next" aria-label="Next photo">&#8250;</button>' +
            '</div>' +
            '<div class="sdc-viewer-strip"></div>';
        host.appendChild(v);

        v.querySelector('.sdc-viewer-close').addEventListener('click', closeViewer);
        v.querySelector('.is-prev').addEventListener('click', () => go(-1));
        v.querySelector('.is-next').addEventListener('click', () => go(1));
        v.querySelector('.sdc-viewer-openday').addEventListener('click', () => {
            const cb = state.opts.onOpenDay;
            closeViewer();
            if (cb) cb(state.opts.date);
        });
        v.querySelector('.sdc-viewer-stage').addEventListener('click', e => {
            if (e.target.classList.contains('sdc-viewer-stage')) closeViewer();
        });

        // Swipe on phones
        let sx = null, sy = null;
        v.addEventListener('touchstart', e => { if (e.touches.length === 1) { sx = e.touches[0].clientX; sy = e.touches[0].clientY; } }, { passive: true });
        v.addEventListener('touchend', e => {
            if (sx === null) return;
            const dx = e.changedTouches[0].clientX - sx, dy = e.changedTouches[0].clientY - sy;
            sx = null;
            if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.3) go(dx < 0 ? 1 : -1);
            else if (dy > 90 && Math.abs(dy) > Math.abs(dx) * 1.5) closeViewer();
        });
        return v;
    }

    function onKey(e) {
        if (!viewer || !viewer.classList.contains('is-open')) return;
        if (e.key === 'Escape') { e.stopPropagation(); closeViewer(); }
        else if (e.key === 'ArrowLeft') go(-1);
        else if (e.key === 'ArrowRight') go(1);
    }

    function openViewer(photos, index, opts) {
        if (!photos || !photos.length) return;
        if (!viewer || !viewer.isConnected) viewer = buildViewer();
        state = { photos: photos, index: Math.max(0, Math.min(index || 0, photos.length - 1)), opts: opts || {} };

        const date = state.opts.date ? niceDate(state.opts.date).long : '';
        viewer.querySelector('.sdc-viewer-date').textContent = date;
        viewer.querySelector('.sdc-viewer-openday').style.display = state.opts.onOpenDay ? '' : 'none';
        viewer.classList.toggle('is-single', photos.length === 1);

        const strip = viewer.querySelector('.sdc-viewer-strip');
        strip.innerHTML = '';
        if (photos.length > 1) {
            photos.forEach((p, i) => {
                const b = el('button', 'sdc-viewer-thumb');
                b.type = 'button';
                b.setAttribute('aria-label', 'Photo ' + (i + 1));
                const im = el('img'); im.alt = ''; im.loading = 'lazy'; im.src = p.thumb || p.large || p.url;
                b.appendChild(im);
                b.addEventListener('click', () => show(i));
                strip.appendChild(b);
            });
        }

        viewer.classList.add('is-open');
        document.documentElement.classList.add('sdc-viewer-lock');
        document.addEventListener('keydown', onKey, true);
        show(state.index);
        viewer.querySelector('.sdc-viewer-close').focus();
    }

    function show(i) {
        const n = state.photos.length;
        state.index = (i + n) % n;
        const p = state.photos[state.index];
        const img = viewer.querySelector('.sdc-viewer-figure img');
        const fig = viewer.querySelector('.sdc-viewer-figure');
        fig.classList.remove('is-shown');
        img.onload = () => fig.classList.add('is-shown');
        img.src = p.url;
        img.alt = p.caption || ('Photo ' + (state.index + 1));
        if (img.complete) fig.classList.add('is-shown');
        const cap = viewer.querySelector('.sdc-viewer-caption');
        cap.textContent = p.caption || '';
        cap.style.display = p.caption ? '' : 'none';
        viewer.querySelector('.sdc-viewer-count').textContent = n > 1 ? (state.index + 1) + ' / ' + n : '';

        viewer.querySelectorAll('.sdc-viewer-thumb').forEach((b, k) => {
            b.classList.toggle('is-active', k === state.index);
            if (k === state.index && b.scrollIntoView) b.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
        });
        // Warm up the neighbours
        [1, -1].forEach(d => { const q = state.photos[(state.index + d + n) % n]; if (q) { const im = new Image(); im.src = q.url; } });
    }

    function go(d) { if (state.photos.length > 1) show(state.index + d); }

    function closeViewer() {
        if (!viewer) return;
        viewer.classList.remove('is-open');
        viewer.querySelector('.sdc-viewer-figure img').removeAttribute('src');
        document.documentElement.classList.remove('sdc-viewer-lock');
        document.removeEventListener('keydown', onKey, true);
    }

    // =====================================================================
    // PHOTO MANAGER (edit form)
    // =====================================================================
    function fromAttachment(a) {
        const s = a.sizes || {};
        const size = k => (s[k] && s[k].url) ? s[k].url : '';
        return {
            id: a.id || 0,
            url: a.url,
            large: size('large') || size('full') || a.url,
            thumb: size('medium') || size('thumbnail') || size('large') || a.url,
            w: a.width || 0,
            h: a.height || 0,
            caption: a.caption || ''
        };
    }

    function createEditor(grid, hidden, onChange) {
        let photos = [];
        let dragFrom = null;

        function sync(changed) {
            hidden.value = JSON.stringify(photos);
            if (changed && onChange) onChange(photos);
        }

        function render() {
            grid.innerHTML = '';
            if (!photos.length) {
                const empty = el('button', 'sdc-photo-empty');
                empty.type = 'button';
                empty.appendChild(el('strong', null, 'Start an album for this day'));
                empty.appendChild(el('span', null, 'Add one photo or a whole set.'));
                empty.addEventListener('click', () => { const b = document.getElementById('sdc-upload-btn'); if (b) b.click(); });
                grid.appendChild(empty);
                return;
            }
            photos.forEach((p, i) => {
                const tile = el('div', 'sdc-photo-tile' + (i === 0 ? ' is-cover' : ''));
                tile.draggable = true;
                tile.dataset.index = i;

                const frame = el('div', 'sdc-photo-frame');
                const img = el('img'); img.alt = ''; img.src = p.thumb || p.large || p.url; img.draggable = false;
                frame.appendChild(img);
                if (i === 0) frame.appendChild(el('span', 'sdc-photo-badge', 'Cover'));

                const tools = el('div', 'sdc-photo-tools');
                if (i > 0) {
                    const cover = el('button', 'sdc-photo-tool', '★');
                    cover.type = 'button'; cover.title = 'Make this the cover'; cover.setAttribute('aria-label', 'Make photo ' + (i + 1) + ' the cover');
                    cover.addEventListener('click', () => { photos.unshift(photos.splice(i, 1)[0]); render(); sync(true); });
                    tools.appendChild(cover);
                }
                const del = el('button', 'sdc-photo-tool is-remove', '×');
                del.type = 'button'; del.title = 'Remove from this day'; del.setAttribute('aria-label', 'Remove photo ' + (i + 1));
                del.addEventListener('click', () => { photos.splice(i, 1); render(); sync(true); });
                tools.appendChild(del);
                frame.appendChild(tools);
                tile.appendChild(frame);

                const cap = el('input', 'sdc-photo-caption');
                cap.type = 'text'; cap.maxLength = 300; cap.placeholder = 'Caption'; cap.value = p.caption || '';
                cap.setAttribute('aria-label', 'Caption for photo ' + (i + 1));
                cap.addEventListener('input', () => { p.caption = cap.value; sync(true); });
                tile.appendChild(cap);

                // Drag to reorder
                tile.addEventListener('dragstart', e => {
                    if (e.target === cap) { e.preventDefault(); return; }
                    dragFrom = i; tile.classList.add('is-dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    try { e.dataTransfer.setData('text/plain', String(i)); } catch (err) { /* old browsers */ }
                });
                tile.addEventListener('dragend', () => { tile.classList.remove('is-dragging'); dragFrom = null; grid.querySelectorAll('.is-drop').forEach(t => t.classList.remove('is-drop')); });
                tile.addEventListener('dragover', e => { if (dragFrom === null) return; e.preventDefault(); tile.classList.add('is-drop'); });
                tile.addEventListener('dragleave', () => tile.classList.remove('is-drop'));
                tile.addEventListener('drop', e => {
                    e.preventDefault();
                    if (dragFrom === null || dragFrom === i) return;
                    const moved = photos.splice(dragFrom, 1)[0];
                    photos.splice(i, 0, moved);
                    dragFrom = null;
                    render(); sync(true);
                });
                grid.appendChild(tile);
            });
        }

        return {
            set(list) { photos = Array.isArray(list) ? list.map(p => Object.assign({}, p)) : []; render(); sync(false); },
            get() { return photos.slice(); },
            addAttachments(attachments) {
                const have = new Set(photos.map(p => p.url));
                attachments.forEach(a => { const p = fromAttachment(a); if (p.url && !have.has(p.url)) { photos.push(p); have.add(p.url); } });
                render(); sync(true);
            }
        };
    }

    // =====================================================================
    // ALBUM WINDOW (6.9) – the day's collage on its own, opened from the
    // month-view thumbnail. Looks like the day window; photos open the viewer.
    // =====================================================================
    let albumPop = null;
    let albumOpts = {};

    function onAlbumKey(e) {
        if (e.key !== 'Escape' || !albumPop || albumPop.style.display === 'none') return;
        if (viewer && viewer.classList.contains('is-open')) return; // Esc closes the photo first
        e.stopPropagation();
        closeAlbum();
    }

    function buildAlbum() {
        const host = document.getElementById('sdc-app') || document.body; // the lock removes #sdc-app
        const pop = el('div', 'sdc-modal sdc-album-pop');
        pop.tabIndex = -1; // takes focus on open, so Esc and screen readers land here
        pop.setAttribute('role', 'dialog');
        pop.setAttribute('aria-modal', 'true');
        const box = el('div', 'sdc-modal-content sdc-album-pop-content');
        const bar = el('div', 'sdc-modal-topbar');
        bar.appendChild(el('h3', 'sdc-album-pop-title'));
        const actions = el('div', 'sdc-modal-actions');
        const openDay = el('button', 'button button-primary sdc-album-openday', 'Open day');
        openDay.type = 'button';
        const close = el('button', 'button sdc-album-close', 'Close');
        close.type = 'button';
        actions.appendChild(openDay);
        actions.appendChild(close);
        bar.appendChild(actions);
        box.appendChild(bar);
        box.appendChild(el('div', 'sdc-album-pop-body'));
        pop.appendChild(box);
        host.appendChild(pop);

        close.addEventListener('click', closeAlbum);
        openDay.addEventListener('click', () => {
            const cb = albumOpts.onOpenDay;
            const date = albumOpts.date;
            closeAlbum();
            if (cb) cb(date);
        });
        pop.addEventListener('click', e => { if (e.target === pop) closeAlbum(); });
        return pop;
    }

    function openAlbum(photos, opts) {
        if (!photos || !photos.length) return;
        if (!albumPop || !albumPop.isConnected) albumPop = buildAlbum();
        albumOpts = opts || {};
        albumPop.querySelector('.sdc-album-pop-title').textContent = albumOpts.date ? 'Date: ' + albumOpts.date : 'Photos';
        albumPop.querySelector('.sdc-album-openday').style.display = albumOpts.onOpenDay ? '' : 'none';
        albumPop.style.display = 'block';
        albumPop.scrollTop = 0;
        // Built once the window is on screen, so the collage can measure its width.
        renderCollage(albumPop.querySelector('.sdc-album-pop-body'), photos, { date: albumOpts.date, title: albumOpts.title || '' });
        document.addEventListener('keydown', onAlbumKey, true);
        albumPop.focus({ preventScroll: true });
    }

    function closeAlbum() {
        if (!albumPop) return;
        albumPop.style.display = 'none';
        albumPop.querySelector('.sdc-album-pop-body').innerHTML = '';
        document.removeEventListener('keydown', onAlbumKey, true);
    }

    window.sdcAlbum = { renderCollage: renderCollage, openViewer: openViewer, closeViewer: closeViewer, openAlbum: openAlbum, closeAlbum: closeAlbum, createEditor: createEditor };
})();
