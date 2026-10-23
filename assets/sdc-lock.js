/**
 * Kalendarz PIN lock (v5.5)
 *
 * - Shows a PIN screen before anything from the calendar is loaded.
 * - The server only returns calendar data to an unlocked session (token).
 * - Locks automatically after `idle_seconds` (60) without mouse / keyboard / touch,
 *   or after `writing_idle_seconds` (300) while an entry is being written.
 * - If it locks with unsaved text, the text is sent to the server as a draft
 *   and offered back after the PIN is entered again.
 * - Survives the plugin's own page reloads (after Save etc.) for the same tab,
 *   as long as the idle limit has not passed.
 * - v6.2: while the page is awake it never locks without the countdown having
 *   been on screen for at least 10 s; the countdown sits above the phone
 *   keyboard; typing of every kind (incl. dictation / IME) counts as activity.
 */
(function ($) {
    'use strict';

    var V        = window.sdcVars || {};
    var IDLE_MS  = (parseInt(V.idle_seconds, 10) || 60) * 1000;
    var WRITE_MS = (parseInt(V.writing_idle_seconds, 10) || 300) * 1000; // while writing
    var WARN_MS  = 10 * 1000;   // countdown for the last 10 s (normal)
    var WARN_WRITE_MS = 30 * 1000; // countdown for the last 30 s (writing)
    var PING_MS  = 20 * 1000;   // heartbeat while the calendar is open
    var MIN_WARN_MS = 10 * 1000; // the countdown is always visible at least this long before locking
    var ASLEEP_MS = 5 * 1000;   // no timer tick for this long = page was asleep / frozen
    var KEY_TOK  = 'sdc_tok_' + (V.user_key || '');
    var KEY_ACT  = 'sdc_act_' + (V.user_key || '');

    var token = null;
    var unlocked = false;
    var locking = false;
    var started = false;
    var lastActivity = 0;
    var lastSaved = 0;
    var lastPing = 0;
    var lastTick = 0;       // when the 1-second timer last ran
    var warnSince = 0;      // when the countdown appeared (0 = not shown)
    var wasWriting = false;
    var ticker = null;
    var eventsJsonCache = null;
    var callbacks = [];
    var mode = 'unlock';
    var resetToken = null;
    var editorApi = null;   // registered by sdc-script.js: { isWriting(), collectDraft() }
    var chatApi = null;     // registered by sdc-sol.js:    { isWriting(), collectDraft() }

    function isWriting() {
        var w = false;
        try { w = !!(editorApi && editorApi.isWriting()); } catch (e) {}
        try { w = w || !!(chatApi && chatApi.isWriting()); } catch (e) {}
        return w;
    }
    function currentLimit() { return isWriting() ? WRITE_MS : IDLE_MS; }

    // ---------- storage (tab-only; cleared when the tab is closed) ----------
    function store(k, v) {
        try { if (v === null) sessionStorage.removeItem(k); else sessionStorage.setItem(k, v); } catch (e) {}
    }
    function load(k) {
        try { return sessionStorage.getItem(k); } catch (e) { return null; }
    }

    // ---------- attach the session token to every calendar AJAX call ----------
    $.ajaxPrefilter(function (opts) {
        if (!token || opts.url !== V.ajax_url) return;
        var d = opts.data;
        if (typeof FormData !== 'undefined' && d instanceof FormData) {
            var a = d.get('action');
            if (a && String(a).indexOf('sdc_') === 0 && !d.has('sdc_token')) d.append('sdc_token', token);
        } else if (typeof d === 'string' && /(^|&)action=sdc_/.test(d) && !/(^|&)sdc_token=/.test(d)) {
            opts.data = d + '&sdc_token=' + encodeURIComponent(token);
        }
    });

    // Any calendar request refused because the session expired → lock.
    $(document).ajaxError(function (e, xhr) {
        var r = xhr && xhr.responseJSON;
        if (unlocked && r && r.data && r.data.code === 'sdc_locked') lockNow();
    });

    // ---------- locking ----------
    function lockNow() {
        if (locking) return;
        locking = true;
        unlocked = false;
        if (ticker) clearInterval(ticker);

        // Grab any unsaved text BEFORE the page is wiped.
        var draft = null, chatDraft = null;
        try { draft = editorApi ? editorApi.collectDraft() : null; } catch (e) { draft = null; }
        try { chatDraft = chatApi ? chatApi.collectDraft() : null; } catch (e) { chatDraft = null; }

        // Remove everything immediately, before the reload. Removing (not just
        // hiding) also stops the browser from refilling form fields after reload.
        var app = document.getElementById('sdc-app');
        if (app && app.parentNode) app.parentNode.removeChild(app);
        hideWarning();
        $('.media-modal, .media-modal-backdrop').hide();

        var t = token;
        token = null;
        store(KEY_TOK, null);
        store(KEY_ACT, null);

        // A fresh page load is the only way to be sure nothing stays in memory/DOM.
        var reloaded = false;
        function reload() { if (!reloaded) { reloaded = true; window.location.reload(); } }

        var fd = new FormData();
        fd.append('action', 'sdc_lock');
        fd.append('security', V.nonce);
        if (t) fd.append('sdc_token', t);

        if (draft || chatDraft) {
            // Wait for the server to store the draft(s), then reload.
            if (draft) fd.append('draft', JSON.stringify(draft));
            if (chatDraft) fd.append('chat_draft', JSON.stringify(chatDraft));
            showMode('checking');
            $('#sdc-lock-title').text('Locking… saving what you were writing');
            $.ajax({ url: V.ajax_url, type: 'POST', data: fd, processData: false, contentType: false, timeout: 8000 })
                .always(reload);
            setTimeout(reload, 10000);
            return;
        }

        try {
            if (!(navigator.sendBeacon && navigator.sendBeacon(V.ajax_url, fd))) {
                $.ajax({ url: V.ajax_url, type: 'POST', data: fd, processData: false, contentType: false });
            }
        } catch (e) {}
        reload();
    }

    // ---------- activity tracking ----------
    function onActivity() {
        if (!unlocked) return;
        var now = Date.now();
        // If the 1-second timer has not run for a while, the page was asleep
        // (laptop lid closed, phone screen off). Let the timer decide first, so
        // coming back after the limit locks instead of extending. While the page
        // is awake, activity never locks – it only keeps it open.
        if (now - lastTick > ASLEEP_MS) {
            tick();
            if (!unlocked) return;
        }
        lastActivity = now;
        if (warnSince) { warnSince = 0; hideWarning(); }
        if (now - lastSaved > 1000) { store(KEY_ACT, String(now)); lastSaved = now; }
    }

    // Everything that means "someone is here". beforeinput / composition cover
    // phone keyboards, dictation and Polish characters typed via IME, which do
    // not always produce keydown/input events in the rich-text editor.
    ['mousemove', 'mousedown', 'pointerdown', 'keydown', 'keyup', 'wheel', 'touchstart', 'touchmove',
     'scroll', 'input', 'beforeinput', 'compositionstart', 'compositionupdate', 'compositionend', 'paste'
    ].forEach(function (ev) {
        window.addEventListener(ev, onActivity, { capture: true, passive: true });
    });

    function tick() {
        if (!unlocked) return;
        var now = Date.now();
        var asleep = lastTick && now - lastTick > ASLEEP_MS;
        lastTick = now;

        var writing = isWriting();
        // Writing just stopped (entry saved/closed, Sol answered…) while you were
        // within the writing limit: start a fresh normal minute instead of
        // locking on the spot because more than 60 s passed since the last key.
        if (wasWriting && !writing && !asleep && now - lastActivity < WRITE_MS) {
            lastActivity = now;
        }
        wasWriting = writing;

        var idle = now - lastActivity;
        var limit = writing ? WRITE_MS : IDLE_MS;
        var warn = writing ? WARN_WRITE_MS : WARN_MS;

        if (idle >= limit) {
            // Asleep/frozen page: lock at once. Awake page: only after the
            // countdown has really been on screen for MIN_WARN_MS.
            if (asleep || (warnSince && now - warnSince >= MIN_WARN_MS)) { lockNow(); return; }
        }

        if (idle >= limit - warn) {
            if (!warnSince) warnSince = now;
            var left = Math.max(limit - idle, warnSince + MIN_WARN_MS - now);
            showWarning(Math.max(1, Math.ceil(left / 1000)), writing);
        } else {
            warnSince = 0;
            hideWarning();
        }

        // Keep the server session alive while the page is open; the idle
        // limits above are what decide when it locks.
        if (now - lastPing >= PING_MS) {
            lastPing = now;
            $.post(V.ajax_url, { action: 'sdc_ping', security: V.nonce });
        }
    }

    // Timers are paused while a laptop sleeps or a tab is in the background,
    // so re-check the moment the page becomes visible again.
    document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
    window.addEventListener('focus', tick);
    window.addEventListener('pageshow', function (e) {
        // Page restored from the back/forward cache.
        if (e.persisted && unlocked && Date.now() - lastActivity >= currentLimit()) lockNow();
    });

    function showWarning(sec, writing) {
        var w = document.getElementById('sdc-idle-warning');
        var c = document.getElementById('sdc-idle-count');
        var n = document.getElementById('sdc-idle-note');
        if (c) c.textContent = sec;
        if (n) n.style.display = writing ? 'inline' : 'none';
        if (w) { w.style.display = 'block'; placeWarning(w); }
    }
    function hideWarning() {
        var w = document.getElementById('sdc-idle-warning');
        if (w && w.style.display !== 'none') w.style.display = 'none';
    }
    // Keep the countdown inside the part of the screen you can actually see:
    // on a phone the on-screen keyboard covers the bottom of the page, and a
    // plain "bottom: 24px" box ends up hidden behind it while you type.
    function placeWarning(w) {
        var vv = window.visualViewport;
        if (!w || !vv) return;
        var h = w.offsetHeight || 60;
        w.style.bottom = 'auto';
        w.style.top = Math.max(8, Math.round(vv.offsetTop + vv.height - h - 16)) + 'px';
        w.style.left = Math.round(vv.offsetLeft + vv.width / 2) + 'px';
    }
    if (window.visualViewport) {
        ['resize', 'scroll'].forEach(function (ev) {
            window.visualViewport.addEventListener(ev, function () {
                var w = document.getElementById('sdc-idle-warning');
                if (w && w.style.display !== 'none') placeWarning(w);
            });
        });
    }

    // ---------- unlocked ----------
    function enter(eventsJson, activityAt, draft, extra) {
        unlocked = true;
        locking = false;
        lastActivity = activityAt || Date.now();
        lastPing = Date.now();
        lastTick = Date.now();
        warnSince = 0;
        wasWriting = false;
        store(KEY_TOK, token);
        store(KEY_ACT, String(lastActivity));

        $('#sdc-lock-screen').hide();
        $('#sdc-app').show();

        if (ticker) clearInterval(ticker);
        ticker = setInterval(tick, 1000);

        eventsJsonCache = eventsJson;
        if (!started) {
            started = true;
            callbacks.forEach(function (cb) { cb(eventsJson, draft || null, extra || {}); });
            callbacks = [];
        }
    }

    // ---------- lock screen UI ----------
    var TEXT = {
        checking: { title: 'Opening…', sub: '', btn: '' },
        unlock:   { title: 'Kalendarz is locked', sub: 'Enter your PIN to open it.', btn: 'Unlock' },
        setup:    { title: 'Set a PIN for Kalendarz', sub: 'Choose 4–8 digits. You will need it every time you open the calendar.', btn: 'Save PIN' },
        reset:    { title: 'Choose a new PIN', sub: '4–8 digits. Your old PIN stops working.', btn: 'Save new PIN' },
        forgot:   { title: 'Forgot your PIN?', sub: 'I will e-mail a one-time reset link to the address on your WordPress account.', btn: 'Send reset link' }
    };

    function showMode(m, msg) {
        mode = m;
        var t = TEXT[m];
        $('#sdc-lock-title').text(t.title);
        $('#sdc-lock-sub').text(t.sub).toggle(!!t.sub);
        $('#sdc-lock-form').toggle(m !== 'checking');
        $('#sdc-pin').toggle(m === 'unlock' || m === 'setup' || m === 'reset').val('');
        $('#sdc-pin-confirm').toggle(m === 'setup' || m === 'reset').val('');
        $('#sdc-lock-submit').text(t.btn).prop('disabled', false);
        $('#sdc-forgot-pin')
            .toggle(m === 'unlock' || m === 'forgot')
            .text(m === 'forgot' ? 'Back to PIN' : 'Forgot PIN?');
        setMsg(msg || '', false);
        $('#sdc-lock-screen').show();
        if ($('#sdc-pin').is(':visible')) setTimeout(function () { $('#sdc-pin').trigger('focus'); }, 50);
    }

    function setMsg(text, isError) {
        $('#sdc-lock-msg').text(text).toggleClass('is-error', !!isError).toggleClass('is-ok', !!text && !isError);
        if (isError) {
            var card = $('.sdc-lock-card').removeClass('sdc-shake');
            void card[0].offsetWidth;
            card.addClass('sdc-shake');
        }
    }

    function errorText(xhr) {
        if (xhr && xhr.responseText === '-1') return 'Your session expired. Please reload the page.';
        var r = xhr && xhr.responseJSON;
        if (r && r.data) return (typeof r.data === 'string') ? r.data : (r.data.message || 'Something went wrong.');
        return 'Something went wrong. Check your connection and try again.';
    }

    function onSubmit(e) {
        e.preventDefault();
        var pin  = String($('#sdc-pin').val() || '').replace(/\D/g, '');
        var pin2 = String($('#sdc-pin-confirm').val() || '').replace(/\D/g, '');
        var btn  = $('#sdc-lock-submit');

        if (mode === 'unlock') {
            if (!pin) { setMsg('Enter your PIN.', true); return; }
            btn.prop('disabled', true);
            $.post(V.ajax_url, { action: 'sdc_unlock', security: V.nonce, pin: pin })
                .done(function (res) {
                    if (res && res.success) { token = res.data.token; enter(res.data.events_json, null, res.data.draft, { chat_draft: res.data.chat_draft }); }
                    else { btn.prop('disabled', false); setMsg('Something went wrong.', true); }
                })
                .fail(function (xhr) {
                    btn.prop('disabled', false);
                    $('#sdc-pin').val('').trigger('focus');
                    var r = xhr && xhr.responseJSON;
                    if (r && r.data && r.data.code === 'sdc_no_pin') { showMode('setup'); return; }
                    setMsg(errorText(xhr), true);
                });
            return;
        }

        if (mode === 'setup' || mode === 'reset') {
            if (!/^\d{4,8}$/.test(pin)) { setMsg('PIN must be 4–8 digits.', true); return; }
            if (pin !== pin2) { setMsg("The two PINs don't match.", true); $('#sdc-pin-confirm').val('').trigger('focus'); return; }
            btn.prop('disabled', true);
            var data = { action: 'sdc_set_pin', security: V.nonce, pin: pin, pin_confirm: pin2 };
            if (mode === 'reset' && resetToken) data.reset_token = resetToken;
            $.post(V.ajax_url, data)
                .done(function (res) {
                    if (res && res.success) { resetToken = null; V.has_pin = true; token = res.data.token; enter(res.data.events_json, null, res.data.draft, { chat_draft: res.data.chat_draft }); }
                    else { btn.prop('disabled', false); setMsg('Something went wrong.', true); }
                })
                .fail(function (xhr) {
                    btn.prop('disabled', false);
                    setMsg(errorText(xhr), true);
                });
            return;
        }

        if (mode === 'forgot') {
            btn.prop('disabled', true);
            var u = new URL(window.location.href);
            u.search = ''; u.hash = '';
            $.post(V.ajax_url, { action: 'sdc_request_pin_reset', security: V.nonce, return_url: u.toString() })
                .done(function (res) {
                    btn.prop('disabled', false);
                    if (res && res.success) showMode('unlock', res.data.message);
                    else setMsg('Something went wrong.', true);
                })
                .fail(function (xhr) {
                    btn.prop('disabled', false);
                    setMsg(errorText(xhr), true);
                });
        }
    }

    // ---------- public API for sdc-script.js ----------
    window.sdcLock = {
        onUnlock: function (cb) {
            if (started) cb(eventsJsonCache, null, {}); else callbacks.push(cb);
        },
        // sdc-script.js / sdc-sol.js tell the lock whether you are writing and how to grab unsaved text.
        registerEditor: function (api) { editorApi = api; },
        registerChat: function (api) { chatApi = api; },
        // Called by the editor when its text changes – a sure sign of typing,
        // whatever keyboard or dictation produced it.
        activity: function () { onActivity(); },
        lockNow: lockNow
    };

    // ---------- start ----------
    $(function () {
        if (!document.getElementById('sdc-lock-screen')) return;

        $('#sdc-lock-form').on('submit', onSubmit);
        $('#sdc-forgot-pin').on('click', function (e) {
            e.preventDefault();
            showMode(mode === 'forgot' ? 'unlock' : 'forgot');
        });
        $(document).on('click', '#sdc-lock-now, .sdc-lock-now-modal', function (e) { e.preventDefault(); lockNow(); });

        // Arrived from a "Forgot PIN" e-mail link?
        var url = new URL(window.location.href);
        var rt = url.searchParams.get('sdc_pin_reset');
        if (rt) {
            resetToken = rt;
            url.searchParams.delete('sdc_pin_reset');
            history.replaceState(null, '', url.toString());
            store(KEY_TOK, null);
            store(KEY_ACT, null);
            showMode('reset');
            return;
        }

        // Same tab, reloaded within the idle limit (e.g. after saving)? Resume.
        var t   = load(KEY_TOK);
        var act = parseInt(load(KEY_ACT) || '0', 10);
        if (t && act && Date.now() - act < IDLE_MS) {
            token = t;
            showMode('checking');
            $.post(V.ajax_url, { action: 'sdc_resume', security: V.nonce })
                .done(function (res) {
                    if (res && res.success && Date.now() - act < IDLE_MS) enter(res.data.events_json, act);
                    else { token = null; store(KEY_TOK, null); store(KEY_ACT, null); showMode(V.has_pin ? 'unlock' : 'setup'); }
                })
                .fail(function () {
                    token = null; store(KEY_TOK, null); store(KEY_ACT, null);
                    showMode(V.has_pin ? 'unlock' : 'setup');
                });
            return;
        }

        store(KEY_TOK, null);
        store(KEY_ACT, null);
        showMode(V.has_pin ? 'unlock' : 'setup');
    });

})(jQuery);
