document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('sdc-calendar');
    if(!calendarEl || !window.sdcLock) return;

    // Nothing is built until the PIN has been accepted; the calendar data
    // arrives from the server only at that moment.
    window.sdcLock.onUnlock(function(eventsJson, draft) {
        sdcStartApp(calendarEl, eventsJson, draft);
    });
});

function sdcStartApp(calendarEl, eventsJson, pendingDraft) {
    // --- FILTER LOGIC ---
    const masterEvents = JSON.parse(eventsJson);
    let activeFilter = 'all';

    const iconMap = {
        'weight': '⚖️', 'shower': '🚿', 'sport': '🚴', 'ykw': '🤫', 'reading': '👓', 'ministry': '👣',
        'highlights': '⭐', 'daily_text': '📝', 'talking_head': '🗣️',
        'podcasts': '🎧', 'books': '📖', 'films': '🎬', 'series': '📺',
        'lessons': '🌳', 'posts_entries': '✍️',
        'image': '' // the 🖼️ button is drawn by eventContent, not the title
    };

    // --- READING STREAK ---
    // Days with the reading box ticked, taken from the calendar data loaded at unlock.
    const readingDays = new Set();
    const ministryDays = new Set(); // 6.9: days with the ministry box ticked
    function rebuildReadingDays() {
        readingDays.clear();
        ministryDays.clear();
        masterEvents.forEach(evt => {
            const day = String(evt.start).slice(0, 10);
            if (evt.extendedProps && evt.extendedProps.reading) readingDays.add(day);
            if (evt.extendedProps && evt.extendedProps.ministry) ministryDays.add(day);
        });
    }
    rebuildReadingDays();
    function shiftDate(dateStr, days) {
        const t = new Date(dateStr + 'T12:00:00Z');
        t.setUTCDate(t.getUTCDate() + days);
        return t.toISOString().slice(0, 10);
    }
    // Consecutive reading days ending on dateStr, counting dateStr itself only if tickedToday.
    function readingStreakAt(dateStr, tickedToday) {
        let n = tickedToday ? 1 : 0;
        let d = shiftDate(dateStr, -1);
        while (readingDays.has(d)) { n++; d = shiftDate(d, -1); }
        return n;
    }
    function updateReadingStreak() {
        const el = document.getElementById('sdc-reading-streak');
        const box = document.getElementById('sdc_reading');
        const dateEl = document.getElementById('sdc_date_field');
        if (!el || !box || !dateEl || !dateEl.value) return;
        const n = readingStreakAt(dateEl.value, box.checked);
        const before = readingStreakAt(dateEl.value, false);
        if (box.checked) {
            el.textContent = n >= 2 ? '🔥 ' + n + '-day streak' : '🌱 Day 1 – well done';
        } else {
            el.textContent = before >= 1 ? '🔥 ' + before + (before === 1 ? ' day' : ' days') + ' – keep it going' : 'Even a few pages counts';
        }
    }
    const readingBox = document.getElementById('sdc_reading');
    if (readingBox) readingBox.addEventListener('change', updateReadingStreak);

    // --- MINISTRY COUNT ---
    // How many times in the month of dateStr; dateStr itself counts only if tickedToday.
    const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    function ministryCountInMonth(dateStr, tickedToday) {
        const prefix = dateStr.slice(0, 7);
        let n = tickedToday ? 1 : 0;
        ministryDays.forEach(d => { if (d !== dateStr && d.slice(0, 7) === prefix) n++; });
        return n;
    }
    function ministryMonthLabel(dateStr, n) {
        return n + '× in ' + MONTHS[parseInt(dateStr.slice(5, 7), 10) - 1];
    }
    function updateMinistryCount() {
        const el = document.getElementById('sdc-ministry-count');
        const box = document.getElementById('sdc_ministry');
        const dateEl = document.getElementById('sdc_date_field');
        if (!el || !box || !dateEl || !dateEl.value) return;
        const n = ministryCountInMonth(dateEl.value, box.checked);
        el.textContent = n > 0 ? ministryMonthLabel(dateEl.value, n) : '';
    }
    const ministryBox = document.getElementById('sdc_ministry');
    if (ministryBox) ministryBox.addEventListener('change', updateMinistryCount);

    function getFilteredEvents() {
        if(activeFilter === 'all') return masterEvents;
        const filtered = [];
        masterEvents.forEach(evt => {
            if(evt.extendedProps.type === 'holiday' || evt.extendedProps.type === 'event') { filtered.push(evt); return; }
            if(evt.extendedProps[activeFilter]) {
                const newEvt = { ...evt };
                newEvt.title = iconMap[activeFilter];
                // While one habit is filtered, keep the row to that habit only.
                if(activeFilter !== 'image') newEvt.extendedProps = { ...evt.extendedProps, hidePic: true };
                filtered.push(newEvt);
            }
        });
        return filtered;
    }

    // --- RENDER FILTER BAR ---
    const filterContainer = document.getElementById('sdc-filter-bar-container');
    if(filterContainer) {
        const filters = [
            { id: 'all', label: 'All', icon: '' },
            { id: 'shower', label: 'Shower', icon: '🚿' },
            { id: 'sport', label: 'Sport', icon: '🚴' },
            { id: 'ykw', label: 'YKW', icon: '🤫' },
            { id: 'reading', label: 'Reading', icon: '👓' },
            { id: 'ministry', label: 'Ministry', icon: '👣' },
            { id: 'weight', label: 'Weight', icon: '⚖️' },
            { id: 'daily_text', label: 'Text', icon: '📝' },
            { id: 'highlights', label: 'Highlights', icon: '⭐' },
            { id: 'talking_head', label: 'Talking', icon: '🗣️' },
            { id: 'podcasts', label: 'Podcasts', icon: '🎧' },
            { id: 'books', label: 'Books', icon: '📖' },
            { id: 'films', label: 'Films', icon: '🎬' },
            { id: 'series', label: 'Series', icon: '📺' },
            { id: 'lessons', label: 'Lessons', icon: '🌳' },
            { id: 'posts_entries', label: 'Posts', icon: '✍️' },
            { id: 'image', label: 'Pictures', icon: '🖼️' }
        ];

        filters.forEach(f => {
            const btn = document.createElement('div');
            btn.className = 'sdc-filter-btn' + (f.id === 'all' ? ' active' : '');
            btn.innerHTML = (f.icon ? f.icon + ' ' : '') + f.label;
            btn.dataset.filter = f.id;

            btn.addEventListener('click', function() {
                document.querySelectorAll('.sdc-filter-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                activeFilter = this.dataset.filter;
                refreshCalendarEvents();
            });
            filterContainer.appendChild(btn);
        });
    }

    function refreshCalendarEvents() {
        calendar.removeAllEvents();
        calendar.addEventSource(getFilteredEvents());
    }

    // After a save the server sends the whole calendar again; swap it in
    // without reloading the page (the day window stays open).
    function replaceCalendarEvents(events) {
        masterEvents.length = 0;
        events.forEach(e => masterEvents.push(e));
        rebuildReadingDays();
        refreshCalendarEvents();
    }

    // --- CSV EXPORT LOGIC ---
    const exportBtn = document.getElementById('sdc-export-btn');
    if(exportBtn) {
        exportBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const monthVal = document.getElementById('sdc-export-month').value;
            if(!monthVal) { alert('Please select a month first.'); return; }

            exportBtn.textContent = 'Generating...';
            exportBtn.disabled = true;

            jQuery.ajax({
                url: sdcVars.ajax_url,
                type: 'POST',
                data: { action: 'sdc_download_report', security: sdcVars.nonce, month: monthVal },
                success: function(res) {
                    exportBtn.textContent = 'Download CSV';
                    exportBtn.disabled = false;

                    if(res.success) {
                        const blob = new Blob([res.data], { type: 'text/csv;charset=utf-8;' });
                        const link = document.createElement("a");
                        const url = URL.createObjectURL(blob);

                        link.setAttribute("href", url);
                        link.setAttribute("download", "second_brain_report_" + monthVal + ".csv");
                        link.style.visibility = 'hidden';

                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                    } else {
                        alert(res.data);
                    }
                },
                error: function() {
                    exportBtn.textContent = 'Download CSV';
                    exportBtn.disabled = false;
                    alert('Server error generating report.');
                }
            });
        });
    }

    // --- CALENDAR INIT ---
    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        firstDay: 1,
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth' },
        events: masterEvents,
        eventOrder: '-duration',
        dateClick: function(info) { openModalForDate(info.dateStr, 'standard'); },
        // Days with photos get a small round cover thumbnail (and a count) at the end of their icon row.
        eventContent: function(arg) {
            const p = arg.event.extendedProps || {};
            const photos = p.photos || [];
            if (p.type !== 'content' || !photos.length || p.hidePic) return true; // default look
            const wrap = document.createElement('div');
            wrap.className = 'fc-event-title';
            if (arg.event.title) wrap.appendChild(document.createTextNode(arg.event.title));
            const pic = document.createElement('span');
            pic.className = 'sdc-pic-btn';
            pic.title = (p.album_title ? p.album_title + ' – ' : '') + photos.length + (photos.length === 1 ? ' photo' : ' photos');
            pic.setAttribute('role', 'button');
            const img = document.createElement('img');
            img.src = photos[0].thumb || photos[0].large || photos[0].url;
            img.alt = '';
            img.loading = 'lazy';
            pic.appendChild(img);
            if (photos.length > 1) {
                const n = document.createElement('span');
                n.className = 'sdc-pic-count';
                n.textContent = photos.length;
                pic.appendChild(n);
            }
            wrap.appendChild(pic);
            return { domNodes: [wrap] };
        },
        eventClick: function(info) {
            const dateStr = info.event.startStr || info.event.start.toISOString().slice(0,10);
            // Clicking the cover thumbnail opens the photos; the rest of the row still opens the day.
            if (info.jsEvent && info.jsEvent.target && info.jsEvent.target.closest('.sdc-pic-btn')) {
                info.jsEvent.preventDefault();
                info.jsEvent.stopPropagation();
                showDayPicture(dateStr, info.event.extendedProps);
                return;
            }
            if (info.event.id && info.event.id.startsWith('holiday-')) {
                openModalForDate(dateStr, 'holiday_view_only');
            } else if (info.event.id && info.event.id.startsWith('event-')) {
                openModalForDate(dateStr, 'event_view_only');
            } else {
                openModalForDate(dateStr, 'standard');
            }
        }
    });
    calendar.render();

    // --- PHOTO VIEWER (month view cover thumbnail) ---
    // The viewer lives inside #sdc-app, so the lock removes it together with everything else.
    function showDayPicture(dateStr, props) {
        const photos = (props && props.photos) || [];
        if (!photos.length || !window.sdcAlbum) return;
        // 6.9: open the whole album (the collage from the day view), not just the first photo.
        window.sdcAlbum.openAlbum(photos, {
            date: dateStr,
            title: (props && props.album_title) || '',
            onOpenDay: d => openModalForDate(d, 'standard')
        });
    }

    // --- MODAL & EDITOR LOGIC ---

    const modal = document.getElementById('sdc-modal');
    const closeBtn = document.querySelector('.sdc-close-btn');
    const prevDayBtn = document.getElementById('sdc-btn-prev-day');
    const nextDayBtn = document.getElementById('sdc-btn-next-day');

    const titleEl = document.getElementById('sdc-modal-date-title');
    const loading = document.getElementById('sdc-loading');
    const holidayList = document.getElementById('sdc-holiday-list');
    const holidayDisplay = document.getElementById('sdc-holiday-display-area');
    const viewImageWrapper = document.getElementById('sdc-view-image-wrapper');

    const tabs = document.querySelectorAll('.sdc-tab-btn');
    const panes = document.querySelectorAll('.sdc-tab-pane');
    const contentTabBtn = document.getElementById('sdc-tab-btn-content');
    const holidaysTabBtn = document.getElementById('sdc-tab-btn-holidays');
    const addHolidayWrapper = document.getElementById('sdc-add-holiday-wrapper');
    const activeHolidaysTitle = document.getElementById('sdc-active-holidays-title');
    const eventList = document.getElementById('sdc-event-list');
    const eventDisplay = document.getElementById('sdc-event-display-area');
    const eventsTabBtn = document.getElementById('sdc-tab-btn-events');
    const addEventWrapper = document.getElementById('sdc-add-event-wrapper');
    const activeEventsTitle = document.getElementById('sdc-active-events-title');

    const editors = {};

    // Track deleted IDs for books and series
    let deletedBookIds = [];
    let deletedSeriesIds = [];

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('active'));
            panes.forEach(p => p.style.display = 'none');
            tab.classList.add('active');
            const activePane = document.querySelector('#sdc-tab-' + tab.dataset.tab + '-area');
            if(activePane) activePane.style.display = 'block';
            syncSaveButtons();
        });
    });

    // Save Content / Cancel sit in the top bar. Show them only while the
    // Day Content form is actually on screen (edit mode + Day Content tab).
    function syncSaveButtons() {
        const editMode = document.getElementById('sdc-edit-mode');
        const show = !!(editMode && editMode.offsetParent !== null);
        ['sdc-save-btn', 'sdc-cancel-edit-btn'].forEach(id => {
            const b = document.getElementById(id);
            if (b) b.style.display = show ? 'inline-block' : 'none';
        });
    }

    const closeModal = () => {
        modal.style.display = 'none';
        dirty = false;
        destroyEditors();
    };

    // --- WRITING STATE (used by the PIN lock) ---
    // "dirty" = something was typed/changed in the Day Content form and not saved yet.
    let dirty = false;
    let editVersion = 0; // goes up with every change, so a save knows if you kept typing meanwhile
    let savedWhileEditing = false; // SAVE no longer closes the window; Cancel then reloads the saved day
    const markDirty = () => { dirty = true; editVersion++; };

    const contentFormEl = document.getElementById('sdc-content-form');
    if (contentFormEl) {
        contentFormEl.addEventListener('input', markDirty);
        contentFormEl.addEventListener('change', markDirty);
        contentFormEl.addEventListener('click', function(e) {
            if (e.target.closest('.sdc-remove-row-btn')) markDirty();
        });
    }

    function focusIsInTextField() {
        const el = document.activeElement;
        if (!el || !modal.contains(el)) return false;
        if (el.isContentEditable || el.tagName === 'TEXTAREA') return true;
        return el.tagName === 'INPUT' && /^(text|number|url|search|email|)$/i.test(el.type || '');
    }

    function collectRows(selector, withStatus) {
        const out = [];
        document.querySelectorAll(selector + ' .sdc-item-row').forEach(row => {
            const title = row.querySelector('.sdc-item-title').value.trim();
            if (!title) return;
            const item = { id: row.dataset.id || '', title: title, rating: row.querySelector('.sdc-item-rating').value };
            if (withStatus) item.status = row.querySelector('.sdc-item-status').value;
            out.push(item);
        });
        return out;
    }

    function collectDraft() {
        if (!dirty || modal.style.display === 'none') return null;
        const date = document.getElementById('sdc_date_field').value;
        if (!date) return null;
        updateEditorContent();
        const val = id => { const el = document.getElementById(id); return el ? el.value : ''; };
        const chk = id => { const el = document.getElementById(id); return !!(el && el.checked); };
        const fields = {
            sdc_photos: photoEditor ? photoEditor.get() : [], sdc_album_title: val('sdc_album_title'),
            sdc_ykw: chk('sdc_ykw'), sdc_sport: chk('sdc_sport'), sdc_shower: chk('sdc_shower'), sdc_reading: chk('sdc_reading'), sdc_ministry: chk('sdc_ministry'),
            sdc_weight: val('sdc_weight')
        };
        ['sdc_daily_text','sdc_highlights','sdc_talking_head','sdc_podcasts','sdc_lessons','sdc_posts_entries'].forEach(id => {
            fields[id] = editors[id] ? editors[id].getData() : val(id);
        });
        return {
            date: date,
            fields: fields,
            books: collectRows('#sdc-books-rows', true),
            films: collectRows('#sdc-films-rows', false),
            series: collectRows('#sdc-series-rows', true),
            deleted_books: deletedBookIds.slice(),
            deleted_series: deletedSeriesIds.slice()
        };
    }

    window.sdcLock.registerEditor({
        isWriting: () => modal.style.display !== 'none' && (dirty || focusIsInTextField()),
        collectDraft: collectDraft
    });

    // --- RESTORING A RESCUED DRAFT ---
    const draftBanner = document.getElementById('sdc-draft-banner');

    function applyDraft(draft) {
        const f = draft.fields || {};
        const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = (v === null || v === undefined) ? '' : v; };
        const setChk = (id, v) => { const el = document.getElementById(id); if (el) el.checked = !!v; };

        // Drafts saved before 6.6 carry a single picture instead of a photo list.
        let draftPhotos = Array.isArray(f.sdc_photos) ? f.sdc_photos : [];
        if (!draftPhotos.length && f.sdc_image_url) draftPhotos = [{ url: f.sdc_image_url, caption: f.sdc_image_caption || '' }];
        if (photoEditor) photoEditor.set(draftPhotos);
        setVal('sdc_album_title', f.sdc_album_title);
        setVal('sdc_weight', f.sdc_weight);
        setChk('sdc_ykw', f.sdc_ykw); setChk('sdc_sport', f.sdc_sport); setChk('sdc_shower', f.sdc_shower); setChk('sdc_reading', f.sdc_reading); setChk('sdc_ministry', f.sdc_ministry);
        updateReadingStreak();
        updateMinistryCount();

        const textIds = ['sdc_daily_text','sdc_highlights','sdc_talking_head','sdc_podcasts','sdc_lessons','sdc_posts_entries'];
        textIds.forEach(id => setVal(id, f[id]));

        ['sdc-books-rows','sdc-films-rows','sdc-series-rows'].forEach(id => {
            const c = document.getElementById(id); if (c) c.innerHTML = '';
        });
        (draft.books || []).forEach(b => addBookRow(b));
        (draft.films || []).forEach(x => addFilmRow(x));
        (draft.series || []).forEach(s => addSeriesRow(s));
        deletedBookIds = (draft.deleted_books || []).map(String);
        deletedSeriesIds = (draft.deleted_series || []).map(String);

        showEdit();
        // Editors are created asynchronously; put the text in once they exist.
        editorsReady().then(() => {
            textIds.forEach(id => { if (editors[id]) editors[id].setData(f[id] || ''); });
            dirty = true;
        });
        dirty = true;

        if (draftBanner) {
            const when = document.getElementById('sdc-draft-when');
            if (when && draft.saved) {
                const d = new Date(draft.saved * 1000);
                when.textContent = ' (' + d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + ')';
            }
            draftBanner.style.display = 'flex';
        }
    }

    const discardBtn = document.getElementById('sdc-draft-discard');
    if (discardBtn) {
        discardBtn.addEventListener('click', function() {
            if (!confirm('Throw away the restored text?')) return;
            const date = document.getElementById('sdc_date_field').value;
            jQuery.post(sdcVars.ajax_url, { action: 'sdc_discard_draft', security: sdcVars.nonce })
                .always(() => { dirty = false; openModalForDate(date, 'standard'); });
        });
    }

    // --- DAY NAVIGATION (PREVIOUS / NEXT) ---
    function sdcGetDateAdjusted(dateStr, days) {
        const date = new Date(dateStr + 'T00:00:00');
        date.setDate(date.getDate() + days);
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    function sdcNavigateDay(deltaDays) {
        const current = document.getElementById('sdc_date_field')?.value;
        if (!current) return;
        const next = sdcGetDateAdjusted(current, deltaDays);
        openModalForDate(next, 'standard');
    }

    if (prevDayBtn) prevDayBtn.addEventListener('click', () => sdcNavigateDay(-1));
    if (nextDayBtn) nextDayBtn.addEventListener('click', () => sdcNavigateDay(1));

    if(closeBtn) closeBtn.onclick = closeModal;
    window.onclick = (e) => { if (e.target == modal) closeModal(); };

    const editTopBtn = document.getElementById('sdc-btn-switch-to-edit-top');

    const showView = () => {
        document.getElementById('sdc-view-mode').style.display='block';
        document.getElementById('sdc-edit-mode').style.display='none';
        if(editTopBtn) editTopBtn.style.display = 'inline-block';
        syncSaveButtons();
    };

    const showEdit = () => {
        document.getElementById('sdc-view-mode').style.display='none';
        document.getElementById('sdc-edit-mode').style.display='block';
        if(editTopBtn) editTopBtn.style.display = 'none';
        syncSaveButtons();
        initEditors();
    };

    if(editTopBtn) editTopBtn.addEventListener('click', showEdit);
    if(document.getElementById('sdc-btn-switch-to-edit')) document.getElementById('sdc-btn-switch-to-edit').addEventListener('click', showEdit);
    if(document.getElementById('sdc-cancel-edit-btn')) document.getElementById('sdc-cancel-edit-btn').addEventListener('click', () => {
        dirty = false;
        // After a SAVE the read view is behind (photos, habits…), so load the day afresh.
        if (savedWhileEditing) openModalForDate(document.getElementById('sdc_date_field').value, 'standard');
        else showView();
    });

    // --- TEXT EDITORS (Quill 2 – the same editor as TBT Notes) ---
    // Each .sdc-editor textarea gets a Quill editor with: bold, italic,
    // strikethrough and five highlighter colours. The colours keep the order
    // used in Notes, so Alt+1…5 mean the same colour in both places.
    const HL_COLORS = [
        { key: 'blue',   label: 'Blue' },
        { key: 'red',    label: 'Red' },
        { key: 'yellow', label: 'Yellow' },
        { key: 'pink',   label: 'Pink' },
        { key: 'green',  label: 'Green' }
    ];
    // Formats the editor keeps. Lists, links, headings and quotes are not on the
    // toolbar but stay allowed, so older entries written in CKEditor keep them.
    // Anything else (pasted colours, fonts, sizes) is dropped on paste.
    const EDITOR_FORMATS = ['bold', 'italic', 'strike', 'underline', 'link', 'list', 'indent', 'header', 'blockquote', 'highlight'];

    let highlightRegistered = false;
    function registerHighlight() {
        if (highlightRegistered || !window.Quill) return;
        const Parchment = window.Quill.import('parchment');
        // Stored as <span class="sdc-hl-yellow">…</span>.
        window.Quill.register(new Parchment.ClassAttributor('highlight', 'sdc-hl', {
            scope: Parchment.Scope.INLINE,
            whitelist: HL_COLORS.map(c => c.key)
        }), true);
        highlightRegistered = true;
    }

    function buildEditorToolbar() {
        const bar = document.createElement('div');
        bar.className = 'sdc-rte-toolbar';
        const group = () => { const g = document.createElement('span'); g.className = 'ql-formats'; bar.appendChild(g); return g; };
        const button = (g, cls, title) => {
            const b = document.createElement('button');
            b.type = 'button'; // never submit the day form
            b.className = cls;
            b.title = title;
            b.setAttribute('aria-label', title);
            g.appendChild(b);
            return b;
        };
        const g1 = group();
        button(g1, 'ql-bold', 'Bold (Ctrl+B)');
        button(g1, 'ql-italic', 'Italic (Ctrl+I)');
        button(g1, 'ql-strike', 'Strikethrough');
        const g2 = group();
        g2.classList.add('sdc-hl-group');
        HL_COLORS.forEach((c, i) => {
            const b = button(g2, 'sdc-hl-btn', 'Highlight: ' + c.label + ' (Alt+' + (i + 1) + ')');
            b.dataset.color = c.key;
        });
        const clear = button(g2, 'sdc-hl-clear', 'No highlight (Alt+0)');
        clear.dataset.color = '';
        clear.textContent = '✕';
        return bar;
    }

    // Wraps one textarea in a Quill editor. Returns the small interface the rest
    // of this file uses: getData / setData / updateSourceElement / destroy.
    function createEditor(textarea) {
        registerHighlight();
        const wrap = document.createElement('div');
        wrap.className = 'sdc-rte';
        const bar = buildEditorToolbar();
        const box = document.createElement('div');
        wrap.appendChild(bar);
        wrap.appendChild(box);
        textarea.style.display = 'none';
        textarea.insertAdjacentElement('afterend', wrap);

        const quill = new window.Quill(box, {
            theme: 'snow',
            modules: { toolbar: bar },
            formats: EDITOR_FORMATS
        });

        function setHtml(html) {
            const delta = (html && String(html).trim()) ? quill.clipboard.convert({ html: String(html) }) : [];
            quill.setContents(delta, 'silent');
            quill.history.clear();
        }

        function getHtml() {
            if (!quill.getText().trim()) return '';
            // Quill 2.0.3 writes every space as &nbsp;, which would stop long
            // lines from wrapping in the day view – turn them back into spaces.
            // Empty lines keep a <br> so they still show as blank lines.
            return quill.getSemanticHTML()
                .replace(/&nbsp;/g, ' ')
                .replace(/<p><\/p>/g, '<p><br></p>');
        }

        setHtml(textarea.value);

        // Highlighting: with text selected, colour it (same colour again removes it);
        // with nothing selected, the next words you type come out in that colour.
        let lastRange = null;
        function applyHighlight(color) {
            const range = quill.getSelection() || lastRange;
            if (!range) return;
            if (range.length > 0) {
                let value = color || false;
                if (color && quill.getFormat(range.index, range.length).highlight === color) value = false;
                quill.formatText(range.index, range.length, 'highlight', value, 'user');
                quill.setSelection(range.index, range.length, 'silent');
            } else {
                quill.focus();
                quill.setSelection(range.index, 0, 'silent');
                quill.format('highlight', color || false, 'user');
            }
            syncSwatches();
        }

        const swatches = bar.querySelectorAll('.sdc-hl-btn, .sdc-hl-clear');
        swatches.forEach(b => {
            b.addEventListener('mousedown', e => e.preventDefault()); // keep the text selection
            b.addEventListener('click', () => applyHighlight(b.dataset.color));
        });
        function syncSwatches() {
            const range = quill.getSelection();
            const current = range ? (quill.getFormat(range).highlight || '') : '';
            swatches.forEach(b => b.classList.toggle('is-active', !!b.dataset.color && b.dataset.color === current));
        }

        // Alt+1…5 = colours, Alt+0 = no highlight. Keyed off the physical key, so
        // it works whatever character Alt would type; Ctrl+Alt (AltGr) is left alone.
        const shortcut = {};
        HL_COLORS.forEach((c, i) => { shortcut['Digit' + (i + 1)] = c.key; shortcut['Numpad' + (i + 1)] = c.key; });
        shortcut.Digit0 = ''; shortcut.Numpad0 = '';
        quill.root.addEventListener('keydown', e => {
            if (!e.altKey || e.ctrlKey || e.metaKey || !(e.code in shortcut)) return;
            e.preventDefault();
            e.stopPropagation();
            applyHighlight(shortcut[e.code]);
        });

        quill.on('selection-change', range => { if (range) lastRange = range; syncSwatches(); });
        quill.on('text-change', (delta, old, source) => {
            syncSwatches();
            if (source !== 'user') return;
            // Typing in the editor counts as unsaved writing.
            markDirty();
            if (window.sdcLock.activity) window.sdcLock.activity();
        });

        return {
            quill: quill,
            getData: getHtml,
            setData: setHtml,
            updateSourceElement: () => { textarea.value = getHtml(); },
            destroy: () => {
                textarea.value = getHtml();
                wrap.remove();
                textarea.style.display = '';
                return Promise.resolve();
            }
        };
    }

    let editorsPromise = Promise.resolve();
    const editorsReady = () => editorsPromise;

    function initEditors() {
        if (Object.keys(editors).length > 0) return editorsPromise;
        if (!window.Quill) { console.error('Carpe Diem: the text editor did not load.'); return editorsPromise; }
        document.querySelectorAll('.sdc-editor').forEach(el => {
            try { editors[el.id] = createEditor(el); }
            catch (error) { console.error(error); }
        });
        return editorsPromise;
    }

    function destroyEditors() {
        for (const id in editors) {
            if (editors[id]) editors[id].destroy();
            delete editors[id];
        }
    }

    function updateEditorContent() {
        for (const id in editors) {
            if (editors[id]) { editors[id].updateSourceElement(); }
        }
    }

    // --- PHOTO ALBUM EDITOR ---
    const photoGridEl = document.getElementById('sdc-photo-grid');
    const photoHiddenEl = document.getElementById('sdc_photos');
    const photoEditor = (photoGridEl && photoHiddenEl && window.sdcAlbum)
        ? window.sdcAlbum.createEditor(photoGridEl, photoHiddenEl, markDirty)
        : null;
    if (photoEditor) photoEditor.set([]);

    // --- UPLOADERS ---
    var dailyUploader;
    const uploadBtn = document.getElementById('sdc-upload-btn');
    if(uploadBtn) {
        uploadBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (dailyUploader) { dailyUploader.open(); return; }
            dailyUploader = wp.media.frames.file_frame = wp.media({
                title: 'Add photos to this day',
                button: { text: 'Add to album' },
                library: { type: 'image' },
                multiple: 'add'
            });
            dailyUploader.on('select', function() {
                const chosen = dailyUploader.state().get('selection').toJSON();
                if (photoEditor) photoEditor.addAttachments(chosen);
                // Start with an empty selection next time.
                dailyUploader.state().get('selection').reset();
            });
            dailyUploader.open();
        });
    }
    var holidayUploader;
    const holidayUploadBtn = document.getElementById('sdc-holiday-upload-btn');
    if(holidayUploadBtn) {
        holidayUploadBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (holidayUploader) { holidayUploader.open(); return; }
            holidayUploader = wp.media.frames.file_frame = wp.media({ title: 'Select Holiday Image', button: { text: 'Use Image' }, multiple: false });
            holidayUploader.on('select', function() { document.getElementById('sdc_holiday_image').value = holidayUploader.state().get('selection').first().toJSON().url; });
            holidayUploader.open();
        });
    }

    // --- ROW HELPERS (Books, Films, Series) ---

    function ratingOptionsHtml(selected) {
        let html = '<option value="">-</option>';
        for (let i = 0; i <= 6; i++) {
            html += '<option value="' + i + '"' + (String(selected) === String(i) ? ' selected' : '') + '>' + i + '</option>';
        }
        return html;
    }

    function statusOptionsHtml(selected) {
        const opts = [
            { value: 'start', label: 'Start' },
            { value: 'in_progress', label: 'In Progress' },
            { value: 'completed', label: 'Completed' }
        ];
        let html = '';
        opts.forEach(o => {
            html += '<option value="' + o.value + '"' + (selected === o.value ? ' selected' : '') + '>' + o.label + '</option>';
        });
        return html;
    }

    function addBookRow(data) {
        const container = document.getElementById('sdc-books-rows');
        const row = document.createElement('div');
        row.className = 'sdc-item-row';
        row.dataset.id = (data && data.id) ? data.id : '';
        row.innerHTML =
            '<input type="text" class="sdc-item-title" placeholder="Book / audiobook title..." value="' + escHtml((data && data.title) || '') + '">' +
            '<select class="sdc-item-status">' + statusOptionsHtml((data && data.status) || 'start') + '</select>' +
            '<select class="sdc-item-rating">' + ratingOptionsHtml((data && data.rating !== null && data.rating !== undefined) ? data.rating : '') + '</select>' +
            '<button type="button" class="sdc-remove-row-btn" title="Remove">&times;</button>';

        row.querySelector('.sdc-remove-row-btn').addEventListener('click', function() {
            const id = row.dataset.id;
            if (id) deletedBookIds.push(id);
            row.remove();
        });

        container.appendChild(row);
    }

    function addFilmRow(data) {
        const container = document.getElementById('sdc-films-rows');
        const row = document.createElement('div');
        row.className = 'sdc-item-row';
        row.dataset.id = (data && data.id) ? data.id : '';
        row.innerHTML =
            '<input type="text" class="sdc-item-title" placeholder="Film title..." value="' + escHtml((data && data.title) || '') + '">' +
            '<select class="sdc-item-rating">' + ratingOptionsHtml((data && data.rating !== null && data.rating !== undefined) ? data.rating : '') + '</select>' +
            '<button type="button" class="sdc-remove-row-btn" title="Remove">&times;</button>';

        row.querySelector('.sdc-remove-row-btn').addEventListener('click', function() {
            row.remove();
        });

        container.appendChild(row);
    }

    function addSeriesRow(data) {
        const container = document.getElementById('sdc-series-rows');
        const row = document.createElement('div');
        row.className = 'sdc-item-row';
        row.dataset.id = (data && data.id) ? data.id : '';
        row.innerHTML =
            '<input type="text" class="sdc-item-title" placeholder="Series title..." value="' + escHtml((data && data.title) || '') + '">' +
            '<select class="sdc-item-status">' + statusOptionsHtml((data && data.status) || 'start') + '</select>' +
            '<select class="sdc-item-rating">' + ratingOptionsHtml((data && data.rating !== null && data.rating !== undefined) ? data.rating : '') + '</select>' +
            '<button type="button" class="sdc-remove-row-btn" title="Remove">&times;</button>';

        row.querySelector('.sdc-remove-row-btn').addEventListener('click', function() {
            const id = row.dataset.id;
            if (id) deletedSeriesIds.push(id);
            row.remove();
        });

        container.appendChild(row);
    }

    function escHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // --- VIEW MODE RENDERERS ---

    function renderBooksView(books, date) {
        const container = document.getElementById('view_books');
        if (!books || books.length === 0) {
            container.textContent = '-';
            return;
        }
        container.innerHTML = '';
        books.forEach(b => {
            const div = document.createElement('div');
            div.className = 'sdc-view-item';
            let statusText = 'In Progress';
            if (b.start_date === date && b.completion_date === date) {
                statusText = 'Completed';
            } else if (b.start_date === date) {
                statusText = 'Started';
            } else if (b.completion_date === date) {
                statusText = 'Completed';
            }

            let ratingText = '';
            if (statusText === 'Completed' && b.rating !== null && b.rating !== '' && b.rating !== undefined) {
                ratingText = ' (' + b.rating + '/6)';
            }

            div.innerHTML = '<span>• ' + escHtml(b.title) + '</span>' +
                '<span class="sdc-view-item-status"> — ' + statusText + '</span>' +
                (ratingText ? '<span class="sdc-view-item-rating">' + ratingText + '</span>' : '');
            container.appendChild(div);
        });
    }

    function renderFilmsView(films) {
        const container = document.getElementById('view_films');
        if (!films || films.length === 0) {
            container.textContent = '-';
            return;
        }
        container.innerHTML = '';
        films.forEach(f => {
            const div = document.createElement('div');
            div.className = 'sdc-view-item';
            let ratingText = '';
            if (f.rating !== null && f.rating !== '' && f.rating !== undefined) {
                ratingText = ' (' + f.rating + '/6)';
            }
            div.innerHTML = '<span>• ' + escHtml(f.title) + '</span>' +
                (ratingText ? '<span class="sdc-view-item-rating">' + ratingText + '</span>' : '');
            container.appendChild(div);
        });
    }

    function renderSeriesView(series, date) {
        const container = document.getElementById('view_series');
        if (!series || series.length === 0) {
            container.textContent = '-';
            return;
        }
        container.innerHTML = '';
        series.forEach(s => {
            const div = document.createElement('div');
            div.className = 'sdc-view-item';
            let statusText = 'In Progress';
            if (s.start_date === date && s.completion_date === date) {
                statusText = 'Completed';
            } else if (s.start_date === date) {
                statusText = 'Started';
            } else if (s.completion_date === date) {
                statusText = 'Completed';
            }

            let ratingText = '';
            if (statusText === 'Completed' && s.rating !== null && s.rating !== '' && s.rating !== undefined) {
                ratingText = ' (' + s.rating + '/6)';
            }

            div.innerHTML = '<span>• ' + escHtml(s.title) + '</span>' +
                '<span class="sdc-view-item-status"> — ' + statusText + '</span>' +
                (ratingText ? '<span class="sdc-view-item-rating">' + ratingText + '</span>' : '');
            container.appendChild(div);
        });
    }

    // --- PLUS BUTTON HANDLERS ---
    const addBookBtn = document.getElementById('sdc-add-book-btn');
    if (addBookBtn) addBookBtn.addEventListener('click', function() { addBookRow({}); });

    const addFilmBtn = document.getElementById('sdc-add-film-btn');
    if (addFilmBtn) addFilmBtn.addEventListener('click', function() { addFilmRow({}); });

    const addSeriesBtn = document.getElementById('sdc-add-series-btn');
    if (addSeriesBtn) addSeriesBtn.addEventListener('click', function() { addSeriesRow({}); });

    // --- MAIN LOGIC ---
    function openModalForDate(dateStr, mode = 'standard', draft = null) {
        modal.style.display = 'block';
        dirty = false;
        if (draftBanner) draftBanner.style.display = 'none';

        if ( ! sdcVars.can_edit ) {
            loading.style.display = 'none';
            titleEl.textContent = 'Date: ' + dateStr;
            const viewMode = document.getElementById('sdc-view-mode');
            viewMode.style.display = 'block';
            viewMode.innerHTML = '<div style="padding:40px 20px; text-align:center; color:#555; font-size:1.1em; line-height:1.5;">' +
                                 '<div style="font-size:3em; margin-bottom:15px;">🔒</div>' +
                                 '<strong>Details of this day are only visible to me.</strong><br>' +
                                 'Thanks for visiting!' +
                                 '</div>';
            const editMode = document.getElementById('sdc-edit-mode');
            if(editMode) editMode.style.display = 'none';
            const tabContainer = document.querySelector('.sdc-tabs');
            if(tabContainer) tabContainer.style.display = 'none';
            return;
        }

        loading.style.display = 'block';
        destroyEditors();

        if (mode === 'holiday_view_only') {
            titleEl.textContent = 'Holiday Details: ' + dateStr;
            if(editTopBtn) editTopBtn.style.display = 'none';
            if(contentTabBtn) contentTabBtn.style.display = 'none';
            if(holidaysTabBtn) holidaysTabBtn.click();
            if(addHolidayWrapper) addHolidayWrapper.style.display = 'none';
            if(activeHolidaysTitle) activeHolidaysTitle.style.display = 'none';
        } else if (mode === 'event_view_only') {
            titleEl.textContent = 'Event Details: ' + dateStr;
            if(editTopBtn) editTopBtn.style.display = 'none';
            if(contentTabBtn) contentTabBtn.style.display = 'none';
            if(eventsTabBtn) eventsTabBtn.click();
            if(addEventWrapper) addEventWrapper.style.display = 'none';
            if(activeEventsTitle) activeEventsTitle.style.display = 'none';
        } else {
            titleEl.textContent = 'Date: ' + dateStr;
            if(editTopBtn) editTopBtn.style.display = 'inline-block';
            if(contentTabBtn) contentTabBtn.style.display = 'block';
            if(tabs.length > 0) tabs[0].click();
            if(addHolidayWrapper) addHolidayWrapper.style.display = 'block';
            if(activeHolidaysTitle) activeHolidaysTitle.style.display = 'block';
            if(addEventWrapper) addEventWrapper.style.display = 'block';
            if(activeEventsTitle) activeEventsTitle.style.display = 'block';
        }

        document.getElementById('sdc_date_field').value = dateStr;
        document.getElementById('sdc_holiday_start').value = dateStr;
        document.getElementById('sdc_holiday_end').value = dateStr;
        document.getElementById('sdc_holiday_title').value = '';
        document.getElementById('sdc_holiday_image').value = '';
        document.getElementById('sdc_event_start').value = dateStr;
        document.getElementById('sdc_event_end').value = dateStr;
        document.getElementById('sdc_event_title').value = '';
        document.getElementById('sdc_event_image').value = '';

        // Reset deletion trackers
        deletedBookIds = [];
        deletedSeriesIds = [];
        savedWhileEditing = false;

        jQuery.ajax({
            url: sdcVars.ajax_url, type: 'POST',
            data: { action: 'sdc_get_content', security: sdcVars.nonce, date: dateStr },
            success: function(res) {
                loading.style.display = 'none';
                if (res.success) renderDay(res.data, dateStr, mode, draft, false);
            }
        });
    }

    // Fills the day window from sdc_get_content data.
    // keepEditing = true is the quiet refresh after SAVE: book/series rows get
    // their new ids and the read view is brought up to date, while the text
    // editors, habits, photos and the edit mode you are in stay exactly as they are.
    function renderDay(data, dateStr, mode, draft, keepEditing) {

        // A. Render Holidays
        holidayDisplay.innerHTML = '';
        if(data.holidays && data.holidays.length > 0) {
            data.holidays.forEach(h => {
                const card = document.createElement('div');
                card.className = 'sdc-holiday-card';
                let imgHtml = h.image_url ? '<img src=\"'+h.image_url+'\">' : '';
                card.innerHTML = imgHtml + '<h3>'+h.title+'</h3><div class=\"sdc-holiday-dates\">📅 '+h.start_date+' to '+h.end_date+'</div>';
                holidayDisplay.appendChild(card);
            });
        }

        // A2. Render Events
        if(eventDisplay) {
            eventDisplay.innerHTML = '';
            if(data.events && data.events.length > 0) {
                data.events.forEach(ev => {
                    const card = document.createElement('div');
                    card.className = 'sdc-event-card';
                    let imgHtml = ev.image_url ? '<img src=\"'+ev.image_url+'\">' : '';
                    card.innerHTML = imgHtml + '<h3>'+ev.title+'</h3><div class=\"sdc-event-dates\">📅 '+ev.start_date+' to '+ev.end_date+'</div>';
                    eventDisplay.appendChild(card);
                });
            }
        }

        // B. Populate books/films/series rows for edit mode
        const booksContainer = document.getElementById('sdc-books-rows');
        const filmsContainer = document.getElementById('sdc-films-rows');
        const seriesContainer = document.getElementById('sdc-series-rows');

        if (booksContainer) booksContainer.innerHTML = '';
        if (filmsContainer) filmsContainer.innerHTML = '';
        if (seriesContainer) seriesContainer.innerHTML = '';

        if (data.books_active && data.books_active.length > 0) {
            data.books_active.forEach(b => addBookRow(b));
        }
        if (data.films && data.films.length > 0) {
            data.films.forEach(f => addFilmRow(f));
        }
        if (data.series_active && data.series_active.length > 0) {
            data.series_active.forEach(s => addSeriesRow(s));
        }

        // C. Populate view mode for books/films/series
        renderBooksView(data.books_view, dateStr);
        renderFilmsView(data.films);
        renderSeriesView(data.series_view, dateStr);

        // D. Fill Daily Content
        if(data.has_content) {
            const d = data.data;
            const dayPhotos = d.photos_list || [];
            // (The collage needs the read view on screen to measure itself, so the
            // after-save refresh leaves it alone; leaving edit mode reloads the day.)
            if (keepEditing) {
                // nothing – see above
            } else if (dayPhotos.length && window.sdcAlbum) {
                viewImageWrapper.style.display = 'block';
                // Render once the view is visible, so the collage can measure its width.
                requestAnimationFrame(() => window.sdcAlbum.renderCollage(viewImageWrapper, dayPhotos, { date: dateStr, title: d.album_title || '' }));
            } else {
                viewImageWrapper.style.display = 'none';
                viewImageWrapper.innerHTML = '';
            }

            const setTxt = (id, val) => {
                const el = document.getElementById(id);
                if(val) { el.innerHTML = val; } else { el.textContent = '-'; }
            };

            setTxt('view_highlights', d.highlights);
            setTxt('view_daily_text', d.daily_text);
            setTxt('view_talking_head', d.talking_head);
            setTxt('view_podcasts', d.podcasts);
            setTxt('view_lessons', d.lessons);
            setTxt('view_posts_entries', d.posts_entries);

            // Legacy book/film content fallback (show old data if no new table data)
            if ((!data.books_view || data.books_view.length === 0) && d.books) {
                document.getElementById('view_books').innerHTML = d.books;
            }
            if ((!data.films || data.films.length === 0) && d.films) {
                let filmHtml = d.films;
                if (d.films_rating !== null && d.films_rating !== '') {
                    filmHtml += ' <span class="sdc-view-item-rating">(' + d.films_rating + '/6)</span>';
                }
                document.getElementById('view_films').innerHTML = filmHtml;
            }

            const weightEl = document.getElementById('view_weight_container');
            if(d.weight && d.weight > 0) {
                weightEl.textContent = '⚖️ ' + d.weight + ' kg';
            } else {
                weightEl.textContent = '';
            }

            let habitHtml = '';
            habitHtml += (d.ykw == 1 ? '✅ YKW ' : '⬜ YKW ');
            habitHtml += '<span style=\"margin-left:15px;\"></span>';
            habitHtml += (d.sport == 1 ? '✅ SPORT ' : '⬜ SPORT ');
            habitHtml += '<span style=\"margin-left:15px;\"></span>';
            habitHtml += (d.shower == 1 ? '✅ SHOWER' : '⬜ SHOWER');
            habitHtml += '<span style=\"margin-left:15px;\"></span>';
            habitHtml += (d.reading == 1 ? '✅ READING' : '⬜ READING');
            const viewStreak = readingStreakAt(dateStr, d.reading == 1);
            if (viewStreak >= 2) habitHtml += ' <span class=\"sdc-streak\">🔥 ' + viewStreak + ' days</span>';
            habitHtml += '<span style=\"margin-left:15px;\"></span>';
            habitHtml += (d.ministry == 1 ? '✅ MINISTRY' : '⬜ MINISTRY');
            const viewMinistry = ministryCountInMonth(dateStr, d.ministry == 1);
            if (viewMinistry > 0) habitHtml += ' <span class=\"sdc-streak\">👣 ' + ministryMonthLabel(dateStr, viewMinistry) + '</span>';
            document.getElementById('view_habits').innerHTML = habitHtml;

            if (!keepEditing) {
                if (photoEditor) photoEditor.set(d.photos_list || []);
                document.getElementById('sdc_album_title').value = d.album_title || '';

                document.getElementById('sdc_weight').value = d.weight || '';

                document.getElementById('sdc_ykw').checked = (d.ykw == 1);
                document.getElementById('sdc_sport').checked = (d.sport == 1);
                document.getElementById('sdc_shower').checked = (d.shower == 1);
                document.getElementById('sdc_reading').checked = (d.reading == 1);
                document.getElementById('sdc_ministry').checked = (d.ministry == 1);

                const textFields = ['highlights','daily_text','talking_head','podcasts','lessons','posts_entries'];
                textFields.forEach(f => {
                    document.getElementById('sdc_' + f).value = d[f] || '';
                });

                showView();
            }
        } else if (!keepEditing) {
            document.getElementById('sdc-content-form').reset();
            for (const id in editors) { if(editors[id]) editors[id].setData(''); }
            if (photoEditor) photoEditor.set([]);
            viewImageWrapper.style.display = 'none';
            viewImageWrapper.innerHTML = '';
            document.getElementById('sdc_date_field').value = dateStr;
            document.getElementById('view_weight_container').textContent = '';
            showEdit();
        }

        if (!keepEditing) { updateReadingStreak(); updateMinistryCount(); }

        // E. Manage Holidays List
        holidayList.innerHTML = '';
        if(data.holidays && data.holidays.length > 0) {
            data.holidays.forEach(h => {
                const li = document.createElement('li');
                let imgHtml = h.image_url ? '<img src=\"'+h.image_url+'\" style=\"max-height:100px; display:block; margin-bottom:5px;\">' : '';
                let deleteLink = (mode === 'standard') ? ' <a href=\"#\" class=\"sdc-del-holiday\" data-id=\"'+h.id+'\" style=\"color:red; float:right;\">[Delete]</a>' : '';
                li.innerHTML = '<div class=\"sdc-holiday-card\" style=\"margin-bottom:10px;\">' + imgHtml + '<strong>' + h.title + '</strong>' + deleteLink + '<br><span style=\"font-size:0.9em; color:#666;\">(' + h.start_date + ' to ' + h.end_date + ')</span></div>';
                holidayList.appendChild(li);
            });
            if(mode === 'standard') {
                document.querySelectorAll('.sdc-del-holiday').forEach(btn => {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        if(confirm('Delete holiday?')) {
                            jQuery.ajax({
                                url: sdcVars.ajax_url, type: 'POST',
                                data: { action: 'sdc_delete_holiday', security: sdcVars.nonce, id: this.dataset.id },
                                success: function() { openModalForDate(dateStr, 'standard'); setTimeout(() => location.reload(), 500); }
                            });
                        }
                    });
                });
            }
        } else {
            holidayList.innerHTML = '<li>No holidays set for this period.</li>';
        }

        // E2. Manage Events List
        if(eventList) {
            eventList.innerHTML = '';
            if(data.events && data.events.length > 0) {
                data.events.forEach(ev => {
                    const li = document.createElement('li');
                    let imgHtml = ev.image_url ? '<img src=\"'+ev.image_url+'\" style=\"max-height:100px; display:block; margin-bottom:5px;\">' : '';
                    let deleteLink = (mode === 'standard') ? ' <a href=\"#\" class=\"sdc-del-event\" data-id=\"'+ev.id+'\" style=\"color:red; float:right;\">[Delete]</a>' : '';
                    li.innerHTML = '<div class=\"sdc-event-card\" style=\"margin-bottom:10px;\">' + imgHtml + '<strong>' + ev.title + '</strong>' + deleteLink + '<br><span style=\"font-size:0.9em; color:#666;\">(' + ev.start_date + ' to ' + ev.end_date + ')</span></div>';
                    eventList.appendChild(li);
                });
                if(mode === 'standard') {
                    document.querySelectorAll('.sdc-del-event').forEach(btn => {
                        btn.addEventListener('click', function(e) {
                            e.preventDefault();
                            if(confirm('Delete event?')) {
                                jQuery.ajax({
                                    url: sdcVars.ajax_url, type: 'POST',
                                    data: { action: 'sdc_delete_event', security: sdcVars.nonce, id: this.dataset.id },
                                    success: function() { openModalForDate(dateStr, 'standard'); setTimeout(() => location.reload(), 500); }
                                });
                            }
                        });
                    });
                }
            } else {
                eventList.innerHTML = '<li>No events set for this period.</li>';
            }
        }

        // F. Loading the day is not "writing"; a restored draft is.
        if (keepEditing) return;
        dirty = false;
        if (draft) applyDraft(draft);
    }

    // Kalendarz locked while you were writing? Bring that text back.
    if (pendingDraft && pendingDraft.date) {
        openModalForDate(pendingDraft.date, 'standard', pendingDraft);
    }

    // --- SAVE HANDLERS ---
    const saveBtnEl = document.getElementById('sdc-save-btn');
    let saving = false;
    let saveFlashTimer = null;
    function setSaveState(state) {
        if (!saveBtnEl) return;
        clearTimeout(saveFlashTimer);
        saveBtnEl.disabled = (state === 'saving');
        saveBtnEl.classList.toggle('is-saved', state === 'saved');
        saveBtnEl.textContent = state === 'saving' ? 'SAVING…' : (state === 'saved' ? 'SAVED ✓' : 'SAVE');
        if (state === 'saved') saveFlashTimer = setTimeout(() => setSaveState(''), 1800);
    }

    const contentForm = document.getElementById('sdc-content-form');
    if(contentForm) {
        contentForm.addEventListener('submit', function(e) {
            e.preventDefault();

            updateEditorContent();

            // Collect books data
            const booksData = [];
            document.querySelectorAll('#sdc-books-rows .sdc-item-row').forEach(row => {
                const title = row.querySelector('.sdc-item-title').value.trim();
                if (!title) return;
                const status = row.querySelector('.sdc-item-status').value;
                const rating = row.querySelector('.sdc-item-rating').value;
                booksData.push({
                    id: row.dataset.id || '',
                    title: title,
                    status: status,
                    rating: rating
                });
            });

            // Validate: rating required on completed books
            for (const book of booksData) {
                if (book.status === 'completed' && (book.rating === '' || book.rating === null || book.rating === undefined)) {
                    alert('Please provide a rating for completed book: ' + book.title);
                    return;
                }
            }

            // Collect films data
            const filmsData = [];
            document.querySelectorAll('#sdc-films-rows .sdc-item-row').forEach(row => {
                const title = row.querySelector('.sdc-item-title').value.trim();
                if (!title) return;
                filmsData.push({
                    id: row.dataset.id || '',
                    title: title,
                    rating: row.querySelector('.sdc-item-rating').value
                });
            });

            // Collect series data
            const seriesData = [];
            document.querySelectorAll('#sdc-series-rows .sdc-item-row').forEach(row => {
                const title = row.querySelector('.sdc-item-title').value.trim();
                if (!title) return;
                const status = row.querySelector('.sdc-item-status').value;
                const rating = row.querySelector('.sdc-item-rating').value;
                seriesData.push({
                    id: row.dataset.id || '',
                    title: title,
                    status: status,
                    rating: rating
                });
            });

            // Validate: rating required on completed series
            for (const s of seriesData) {
                if (s.status === 'completed' && (s.rating === '' || s.rating === null || s.rating === undefined)) {
                    alert('Please provide a rating for completed series: ' + s.title);
                    return;
                }
            }

            if (saving) return; // one save at a time
            const sentBookDeletes = deletedBookIds.slice();
            const sentSeriesDeletes = deletedSeriesIds.slice();
            const versionAtSave = editVersion;
            const dateAtSave = document.getElementById('sdc_date_field').value;

            const fd = new FormData(contentForm);
            fd.append('action', 'sdc_save_content');
            fd.append('security', sdcVars.nonce);
            fd.append('books_data', JSON.stringify(booksData));
            fd.append('films_data', JSON.stringify(filmsData));
            fd.append('series_data', JSON.stringify(seriesData));
            fd.append('deleted_books', JSON.stringify(deletedBookIds));
            fd.append('deleted_series', JSON.stringify(deletedSeriesIds));

            saving = true;
            setSaveState('saving');
            jQuery.ajax({
                url: sdcVars.ajax_url, type: 'POST', data: fd, processData: false, contentType: false,
                success: function(res) {
                    saving = false;
                    if (!res || !res.success) {
                        setSaveState('');
                        alert(res && res.data ? res.data : 'Could not save.');
                        return;
                    }
                    // Saved. The window stays open, in edit mode, where you were.
                    setSaveState('saved');
                    savedWhileEditing = true;
                    if (editVersion === versionAtSave) dirty = false; // still dirty if you typed meanwhile
                    deletedBookIds = deletedBookIds.filter(id => !sentBookDeletes.includes(id));
                    deletedSeriesIds = deletedSeriesIds.filter(id => !sentSeriesDeletes.includes(id));
                    if (draftBanner) draftBanner.style.display = 'none';
                    // Month view: new icons / photo thumbnail for this day.
                    if (res.data && Array.isArray(res.data.events)) replaceCalendarEvents(res.data.events);
                    refreshDayAfterSave(dateAtSave);
                },
                error: function() {
                    saving = false;
                    setSaveState('');
                    alert('Could not save – check the connection and try again. Your text is still here.');
                }
            });
        });
    }

    // New books/series get their database ids only on the server, so fetch the
    // day again quietly; otherwise a second SAVE would add them twice.
    function refreshDayAfterSave(dateStr) {
        jQuery.ajax({
            url: sdcVars.ajax_url, type: 'POST',
            data: { action: 'sdc_get_content', security: sdcVars.nonce, date: dateStr },
            success: function(res) {
                const stillHere = modal.style.display !== 'none' && document.getElementById('sdc_date_field').value === dateStr;
                if (res && res.success && stillHere) renderDay(res.data, dateStr, 'standard', null, true);
            }
        });
    }

    const holidayForm = document.getElementById('sdc-holiday-form');
    if(holidayForm) {
        holidayForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const title = document.getElementById('sdc_holiday_title').value;
            const image = document.getElementById('sdc_holiday_image').value;
            const start = document.getElementById('sdc_holiday_start').value;
            const end = document.getElementById('sdc_holiday_end').value;

            jQuery.ajax({
                url: sdcVars.ajax_url, type: 'POST',
                data: { action: 'sdc_add_holiday', security: sdcVars.nonce, title: title, image: image, start: start, end: end },
                success: function(res) {
                    if(res.success) { location.reload(); }
                    else { alert(res.data); }
                }
            });
        });
    }

    const eventForm = document.getElementById('sdc-event-form');
    if(eventForm) {
        eventForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const title = document.getElementById('sdc_event_title').value;
            const image = document.getElementById('sdc_event_image').value;
            const start = document.getElementById('sdc_event_start').value;
            const end = document.getElementById('sdc_event_end').value;
            jQuery.ajax({
                url: sdcVars.ajax_url, type: 'POST',
                data: { action: 'sdc_add_event', security: sdcVars.nonce, title: title, image: image, start: start, end: end },
                success: function(res) {
                    if(res.success) { location.reload(); }
                    else { alert(res.data); }
                }
            });
        });
    }

    var eventUploader;
    const eventUploadBtn = document.getElementById('sdc-event-upload-btn');
    if(eventUploadBtn) {
        eventUploadBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (eventUploader) { eventUploader.open(); return; }
            eventUploader = wp.media.frames.file_frame = wp.media({ title: 'Select Event Image', button: { text: 'Use Image' }, multiple: false });
            eventUploader.on('select', function() { document.getElementById('sdc_event_image').value = eventUploader.state().get('selection').first().toJSON().url; });
            eventUploader.open();
        });
    }

}
