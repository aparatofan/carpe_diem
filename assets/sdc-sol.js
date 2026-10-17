/**
 * Sol – chat panel inside Kalendarz (v6.0)
 * Talks only to the plugin's own AJAX endpoints; the OpenAI key never reaches the browser.
 * The panel lives inside #sdc-app, so locking removes it together with the calendar.
 */
(function ($) {
    'use strict';

    var V = window.sdcVars || {};
    var started = false;
    var state = { chatId: 0, chats: [], waiting: false, open: false, settingsOpen: false };
    var el = {};

    // ---------- helpers ----------
    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /** Small, safe Markdown → HTML (everything is escaped first). */
    function md(src) {
        var lines = esc(src).replace(/\r\n?/g, '\n').split('\n');
        var html = [], para = [], list = null;

        function inline(t) {
            return t
                .replace(/`([^`]+)`/g, '<code>$1</code>')
                .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
                .replace(/(^|[^*])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>')
                .replace(/(^|\W)_([^_\s][^_]*)_(?=\W|$)/g, '$1<em>$2</em>');
        }
        function flushPara() { if (para.length) { html.push('<p>' + para.map(inline).join('<br>') + '</p>'); para = []; } }
        function flushList() { if (list) { html.push('<' + list.tag + '>' + list.items.map(function (i) { return '<li>' + inline(i) + '</li>'; }).join('') + '</' + list.tag + '>'); list = null; } }

        lines.forEach(function (line) {
            var m;
            if (/^\s*$/.test(line)) { flushPara(); flushList(); return; }
            if ((m = line.match(/^\s{0,3}#{1,4}\s+(.*)$/))) { flushPara(); flushList(); html.push('<h4>' + inline(m[1]) + '</h4>'); return; }
            if ((m = line.match(/^\s*[-*•]\s+(.*)$/))) { flushPara(); if (!list || list.tag !== 'ul') { flushList(); list = { tag: 'ul', items: [] }; } list.items.push(m[1]); return; }
            if ((m = line.match(/^\s*\d+[.)]\s+(.*)$/))) { flushPara(); if (!list || list.tag !== 'ol') { flushList(); list = { tag: 'ol', items: [] }; } list.items.push(m[1]); return; }
            if ((m = line.match(/^\s*&gt;\s?(.*)$/))) { flushPara(); flushList(); html.push('<blockquote>' + inline(m[1]) + '</blockquote>'); return; }
            flushList();
            para.push(line);
        });
        flushPara(); flushList();
        return html.join('');
    }

    function when(mysql) {
        if (!mysql) return '';
        var d = new Date(String(mysql).replace(' ', 'T'));
        if (isNaN(d)) return '';
        return d.toLocaleDateString([], { day: 'numeric', month: 'short' }) + ', ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function post(data, opts) {
        data.security = V.nonce;
        return $.ajax($.extend({ url: V.ajax_url, type: 'POST', data: data, dataType: 'json' }, opts || {}));
    }

    function errText(xhr, fallback) {
        var r = xhr && xhr.responseJSON;
        if (r && r.data) return typeof r.data === 'string' ? r.data : (r.data.message || fallback);
        if (xhr && xhr.statusText === 'timeout') return 'Sol took too long to answer. Please try again (or ask something narrower).';
        return fallback;
    }

    // ---------- build the panel ----------
    function build() {
        var app = document.getElementById('sdc-app');
        if (!app) return false;
        var wrap = document.createElement('div');
        wrap.id = 'sdc-sol';
        wrap.className = 'sdc-sol';
        wrap.style.display = 'none';
        wrap.innerHTML =
            '<div class="sdc-sol-box" role="dialog" aria-label="Sol">' +
              '<aside class="sdc-sol-side">' +
                '<div class="sdc-sol-side-head"><strong>Conversations</strong>' +
                  '<button type="button" class="sdc-sol-btn sdc-sol-new">+ New</button></div>' +
                '<ul class="sdc-sol-list"></ul>' +
              '</aside>' +
              '<section class="sdc-sol-main">' +
                '<header class="sdc-sol-head">' +
                  '<button type="button" class="sdc-sol-btn sdc-sol-toggle" title="Conversations">☰</button>' +
                  '<div class="sdc-sol-title"><span class="sdc-sol-name">☀️ Sol</span><span class="sdc-sol-sub"></span></div>' +
                  '<div class="sdc-sol-actions">' +
                    '<button type="button" class="sdc-sol-btn sdc-sol-settings-btn" title="Sol\'s character">⚙︎ Character</button>' +
                    '<button type="button" class="sdc-sol-btn sdc-lock-now-modal" title="Lock now">🔒 Lock</button>' +
                    '<button type="button" class="sdc-sol-btn sdc-sol-close" title="Back to the calendar">Close</button>' +
                  '</div>' +
                '</header>' +
                '<div class="sdc-sol-notice" style="display:none;"></div>' +
                '<div class="sdc-sol-msgs" aria-live="polite"></div>' +
                '<div class="sdc-sol-settings" style="display:none;">' +
                  '<h4>Sol\'s character</h4>' +
                  '<p class="sdc-sol-hint">This is what Sol is told about who it is and how to talk to you. Change anything you like – it applies from your next message.</p>' +
                  '<textarea class="sdc-sol-persona" rows="18"></textarea>' +
                  '<div class="sdc-sol-settings-actions">' +
                    '<button type="button" class="sdc-sol-btn sdc-sol-primary sdc-sol-save-persona">Save</button>' +
                    '<button type="button" class="sdc-sol-btn sdc-sol-reset-persona">Restore default</button>' +
                    '<button type="button" class="sdc-sol-btn sdc-sol-settings-close">Back to chat</button>' +
                    '<span class="sdc-sol-settings-msg"></span>' +
                  '</div>' +
                  '<p class="sdc-sol-hint sdc-sol-model"></p>' +
                '</div>' +
                '<form class="sdc-sol-form" autocomplete="off">' +
                  '<textarea class="sdc-sol-input" rows="3" placeholder="Write to Sol…  (Ctrl+Enter to send)"></textarea>' +
                  '<button type="submit" class="sdc-sol-btn sdc-sol-primary sdc-sol-send">Send</button>' +
                '</form>' +
              '</section>' +
            '</div>';
        app.appendChild(wrap);

        el.wrap = wrap;
        el.side = wrap.querySelector('.sdc-sol-side');
        el.list = wrap.querySelector('.sdc-sol-list');
        el.msgs = wrap.querySelector('.sdc-sol-msgs');
        el.sub = wrap.querySelector('.sdc-sol-sub');
        el.notice = wrap.querySelector('.sdc-sol-notice');
        el.form = wrap.querySelector('.sdc-sol-form');
        el.input = wrap.querySelector('.sdc-sol-input');
        el.send = wrap.querySelector('.sdc-sol-send');
        el.settings = wrap.querySelector('.sdc-sol-settings');
        el.persona = wrap.querySelector('.sdc-sol-persona');
        el.settingsMsg = wrap.querySelector('.sdc-sol-settings-msg');
        el.model = wrap.querySelector('.sdc-sol-model');

        $(wrap).on('click', '.sdc-sol-close', close);
        $(wrap).on('click', '.sdc-sol-new', function () { newChat(); if (isNarrow()) el.side.classList.remove('is-open'); });
        $(wrap).on('click', '.sdc-sol-toggle', function () { el.side.classList.toggle('is-open'); });
        $(wrap).on('click', '.sdc-sol-settings-btn', openSettings);
        $(wrap).on('click', '.sdc-sol-settings-close', closeSettings);
        $(wrap).on('click', '.sdc-sol-save-persona', savePersona);
        $(wrap).on('click', '.sdc-sol-reset-persona', function () {
            if (state.defaultPersona && confirm("Replace the text with Sol's default character?")) el.persona.value = state.defaultPersona;
        });
        $(wrap).on('click', '.sdc-sol-chat', function (e) {
            if ($(e.target).closest('.sdc-sol-del').length) return;
            loadChat(parseInt(this.dataset.id, 10));
            if (isNarrow()) el.side.classList.remove('is-open');
        });
        $(wrap).on('click', '.sdc-sol-del', function (e) {
            e.stopPropagation();
            var id = parseInt($(this).closest('.sdc-sol-chat').data('id'), 10);
            if (!confirm('Delete this conversation for good? Sol will not remember it any more.')) return;
            post({ action: 'sdc_chat_delete', chat_id: id }).done(function () {
                if (id === state.chatId) newChat();
                loadList();
            });
        });
        el.form.addEventListener('submit', function (e) { e.preventDefault(); send(); });
        el.input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); send(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && state.open && !state.waiting && !el.input.value.trim()) close();
        });
        return true;
    }

    function isNarrow() { return window.matchMedia && window.matchMedia('(max-width: 760px)').matches; }

    // ---------- open / close ----------
    function open() {
        if (!el.wrap && !build()) return;
        el.wrap.style.display = 'flex';
        state.open = true;
        document.body.classList.add('sdc-sol-active');
        if (!V.sol_configured) {
            notice('Sol isn\'t connected yet. Add your OpenAI API key to <code>wp-config.php</code>:<br><code>define( \'SDC_OPENAI_API_KEY\', \'sk-…\' );</code>');
        }
        loadList();
        if (applyPendingDraft()) return;
        if (!state.chatId && !el.msgs.children.length) welcome();
        setTimeout(function () { el.input.focus(); }, 50);
    }

    function close() {
        if (!el.wrap) return;
        el.wrap.style.display = 'none';
        state.open = false;
        document.body.classList.remove('sdc-sol-active');
    }

    function notice(html) {
        el.notice.innerHTML = html;
        el.notice.style.display = html ? 'block' : 'none';
    }

    function welcome() {
        el.msgs.innerHTML =
            '<div class="sdc-sol-welcome">' +
              '<div class="sdc-sol-welcome-sun">☀️</div>' +
              '<p><strong>Hi, I\'m Sol.</strong> I can read your Kalendarz – diary entries, habits, books, films – and our earlier conversations.</p>' +
              '<p>Tell me what\'s on your mind, or ask about a period of your life. I\'ll be honest with you.</p>' +
            '</div>';
        el.sub.textContent = 'New conversation';
    }

    // ---------- conversations ----------
    function loadList() {
        return post({ action: 'sdc_chat_list' }).done(function (res) {
            if (!res || !res.success) return;
            state.chats = res.data.chats || [];
            renderList();
        });
    }

    function renderList() {
        if (!state.chats.length) {
            el.list.innerHTML = '<li class="sdc-sol-empty">No conversations yet.</li>';
            return;
        }
        el.list.innerHTML = state.chats.map(function (c) {
            return '<li class="sdc-sol-chat' + (parseInt(c.id, 10) === state.chatId ? ' is-active' : '') + '" data-id="' + esc(c.id) + '">' +
                '<span class="sdc-sol-chat-title">' + esc(c.title || 'Conversation') + '</span>' +
                '<span class="sdc-sol-chat-date">' + esc(when(c.updated_at)) + '</span>' +
                '<button type="button" class="sdc-sol-del" title="Delete">🗑</button>' +
            '</li>';
        }).join('');
    }

    function newChat() {
        state.chatId = 0;
        welcome();
        renderList();
        el.input.focus();
    }

    function loadChat(id, then) {
        closeSettings();
        el.msgs.innerHTML = '<div class="sdc-sol-loading">Loading…</div>';
        return post({ action: 'sdc_chat_get', chat_id: id }).done(function (res) {
            if (!res || !res.success) { newChat(); return; }
            state.chatId = parseInt(res.data.chat.id, 10);
            el.sub.textContent = res.data.chat.title || '';
            el.msgs.innerHTML = '';
            (res.data.messages || []).forEach(function (m) { addMsg(m.role, m.content, m.created_at); });
            renderList();
            scrollDown();
            if (then) then();
        }).fail(function () { newChat(); });
    }

    function addMsg(role, text, at, looked) {
        var div = document.createElement('div');
        div.className = 'sdc-sol-msg ' + (role === 'assistant' ? 'is-sol' : 'is-me');
        div.innerHTML =
            '<div class="sdc-sol-bubble">' + (role === 'assistant' ? md(text) : '<p>' + esc(text).replace(/\n/g, '<br>') + '</p>') + '</div>' +
            '<div class="sdc-sol-meta">' + (role === 'assistant' ? 'Sol' : 'You') + (at ? ' · ' + esc(when(at)) : '') +
            (looked && looked.length ? ' · <span class="sdc-sol-looked" title="What Sol looked at">looked at: ' + esc(looked.join(', ')) + '</span>' : '') +
            '</div>';
        el.msgs.appendChild(div);
        return div;
    }

    function scrollDown() { el.msgs.scrollTop = el.msgs.scrollHeight; }

    // ---------- sending ----------
    function send() {
        if (state.waiting) return;
        var text = el.input.value.trim();
        if (!text) return;
        if (!V.sol_configured) { notice('Sol isn\'t connected yet – add <code>SDC_OPENAI_API_KEY</code> to <code>wp-config.php</code> first.'); return; }

        var welcomeEl = el.msgs.querySelector('.sdc-sol-welcome');
        if (welcomeEl) welcomeEl.remove();
        notice('');

        var mine = addMsg('user', text, null);
        var thinking = document.createElement('div');
        thinking.className = 'sdc-sol-msg is-sol is-thinking';
        thinking.innerHTML = '<div class="sdc-sol-bubble"><span class="sdc-sol-dots"><i></i><i></i><i></i></span> Sol is thinking<span class="sdc-sol-secs"></span></div>';
        el.msgs.appendChild(thinking);
        scrollDown();

        el.input.value = '';
        setWaiting(true);
        var t0 = Date.now();
        var secs = thinking.querySelector('.sdc-sol-secs');
        var timer = setInterval(function () {
            var s = Math.round((Date.now() - t0) / 1000);
            if (s >= 5) secs.textContent = ' · ' + s + ' s';
        }, 1000);

        post({ action: 'sdc_chat_send', chat_id: state.chatId, message: text }, { timeout: 300000 })
            .done(function (res) {
                if (!res || !res.success) { fail(res && res.data && res.data.message ? res.data.message : 'Something went wrong.'); return; }
                thinking.remove();
                state.chatId = parseInt(res.data.chat.id, 10);
                el.sub.textContent = res.data.chat.title || '';
                addMsg('assistant', res.data.reply, res.data.chat.updated_at, res.data.looked);
                scrollDown();
                loadList();
            })
            .fail(function (xhr) {
                var r = xhr && xhr.responseJSON;
                if (r && r.data && r.data.code === 'sdc_locked') return; // the lock takes over
                fail(errText(xhr, 'Could not reach Sol. Check your connection and try again.'));
            })
            .always(function () { clearInterval(timer); setWaiting(false); });

        function fail(msg) {
            thinking.remove();
            mine.remove();
            if (!el.input.value.trim()) el.input.value = text;   // give the text back
            notice('⚠️ ' + esc(msg));
            if (!el.msgs.children.length && !state.chatId) welcome();
        }
    }

    function setWaiting(on) {
        state.waiting = on;
        el.send.disabled = on;
        el.send.textContent = on ? '…' : 'Send';
    }

    // ---------- settings ----------
    function openSettings() {
        el.settings.style.display = 'block';
        el.msgs.style.display = 'none';
        el.form.style.display = 'none';
        state.settingsOpen = true;
        el.settingsMsg.textContent = 'Loading…';
        post({ action: 'sdc_sol_settings' }).done(function (res) {
            if (!res || !res.success) return;
            el.persona.value = res.data.persona;
            state.defaultPersona = res.data.default_persona;
            el.settingsMsg.textContent = res.data.is_default ? 'Using the default character.' : 'Using your own version.';
            el.model.textContent = 'Model: ' + res.data.model + (res.data.configured ? '' : ' · API key not set');
        });
    }

    function closeSettings() {
        if (!el.settings) return;
        el.settings.style.display = 'none';
        el.msgs.style.display = '';
        el.form.style.display = '';
        state.settingsOpen = false;
    }

    function savePersona() {
        el.settingsMsg.textContent = 'Saving…';
        post({ action: 'sdc_sol_save_settings', persona: el.persona.value })
            .done(function (res) { el.settingsMsg.textContent = res && res.success ? 'Saved ✓' : 'Could not save.'; })
            .fail(function (xhr) { el.settingsMsg.textContent = errText(xhr, 'Could not save.'); });
    }

    // ---------- lock integration ----------
    function focusInSol() {
        var a = document.activeElement;
        return !!(a && el.wrap && el.wrap.contains(a) && (a.tagName === 'TEXTAREA' || a.tagName === 'INPUT'));
    }

    window.sdcLock && window.sdcLock.registerChat({
        // Writing to Sol (or waiting for an answer) uses the longer "writing" limit.
        isWriting: function () {
            return state.open && (state.waiting || focusInSol() || !!(el.input && el.input.value.trim()) || state.settingsOpen);
        },
        // Unsent text is handed to the server when the calendar locks.
        collectDraft: function () {
            if (!state.open) return null;
            var text = el.input ? el.input.value : '';
            if (!text.trim() && !state.waiting && !state.chatId) return null;
            return { chat_id: state.chatId, text: text };
        }
    });

    // ---------- start after PIN ----------
    $(function () {
        if (!window.sdcLock) return;
        window.sdcLock.onUnlock(function (eventsJson, draft, extra) {
            if (started) return;
            started = true;
            $(document).on('click', '#sdc-sol-open', open);

            var cd = extra && extra.chat_draft;
            if (cd) {
                state.pendingDraft = cd;
                // A restored diary entry opens first; the Sol message waits until Sol is opened.
                if (!draft) open();
            }
        });
    });

    function applyPendingDraft() {
        var cd = state.pendingDraft;
        if (!cd) return false;
        state.pendingDraft = null;
        var restore = function () {
            if (cd.text) {
                el.input.value = cd.text;
                notice('↩️ Restored the message you were writing when Kalendarz locked.');
            }
            el.input.focus();
        };
        if (cd.chat_id) loadChat(cd.chat_id, restore); else restore();
        return true;
    }

})(jQuery);
