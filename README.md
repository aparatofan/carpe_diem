# Carpe Diem

Private calendar project (WordPress plugin, shortcode `[daily_calendar]`).

## Files
- simple-daily-calendar.php – main PHP file
- includes/sdc-sol.php – Sol (OpenAI chat): conversation loop, read-only data tools, chat storage
- assets/sdc-script.js – calendar JavaScript
- assets/sdc-album.js – photo albums: collage, full-screen viewer, photo manager (6.6)
- assets/sdc-lock.js – PIN lock screen + auto-lock
- assets/sdc-sol.js – Sol chat panel
- assets/sdc-style.css – CSS
- assets/vendor/quill/ – Quill 2.0.3 text editor, bundled locally (same as TBT Notes, 6.8)
- .github/workflows/deploy.yml – automatic upload to the website (not uploaded itself)

## Automatic deploy (since 6.1)
Same deploy as the TBT plugins (copied from drag-and-drop). A push or merge to `main` runs
`.github/workflows/deploy.yml`, which uploads changed files over FTPS to the folder in the
`FTP_SERVER_DIR` secret (the plugin folder's full path as the hosting file manager shows it;
if the FTP login starts lower down, the deploy drops leading folders until it finds the plugin). Markdown files and `.github/` are not uploaded, and a
documentation-only push skips the deploy.

Before uploading it checks that the four secrets exist (`FTP_SERVER`, `FTP_USERNAME`,
`FTP_PASSWORD`, `FTP_SERVER_DIR`), that that folder on the server already contains
`simple-daily-calendar.php`, and that every PHP file has valid syntax. If an upload is cut
off, it waits 60 s and retries once.

By hand: GitHub → **Actions** → **Deploy to WordPress** → **Run workflow** (*Preview only*
lists what would change; *protocol*, *port* and *log level* are there for troubleshooting).

The action uploads but never deletes, so two names are fixed:
- **`simple-daily-calendar.php`** must keep its name, or WordPress would show two plugins.
- **`FTP_SERVER_DIR`** must stay the installed plugin folder, for the same reason.

Database changes (like the new reading column) are applied automatically on the next page load.

## Habits
🤫 YKW · 🚴 Sport · 🚿 Shower · 👓 Reading (6.1) · 👣 Ministry (6.9). Reading shows a streak counter next to the
box (“Keep your 4-day streak going”) and in the day view; it's in the filter bar, the CSV
export and Sol's statistics (`reading_streak`).

Ministry (6.9) counts the days you went out in the ministry: next to the box and in the day view
you see how many times that month (“👣 3× in November”). It has its own 👣 filter, a CSV column and
is part of Sol's habit counts. New database column `ministry`, added automatically on the next page load.

Habit notes (6.10): every habit has a short note directly under its box, in the edit form and the day view:
YKW and Sport show days since the last tick (“⏱ 4 days since last”), Shower and Reading show the streak
(“🔥 3-day streak”), Ministry shows the month count (“1× in November”). On a phone the habits stack.

## Photo albums (since 6.6)
A day can hold many photos instead of one picture.

- **Adding:** in *Edit*, the 📷 Photos box. *+ Add photos* opens the media library with
  multi-select (Ctrl/Cmd-click or drag-select). Drag tiles to reorder; ★ makes a photo the
  cover (the first one); × removes it from the day (the file stays in the media library).
  Each photo has its own caption, and the album can have a title.
- **Day view:** a magazine-style collage – the cover large, the rest in a loose grid,
  the date in the top-right corner. Above it: the album title, the number of photos and
  the colour switch. The layout is fixed per day, so it looks the same every time.
  Black-and-white by default (colour on hover); the switch is remembered in that browser.
- **Viewer:** click any photo – full screen, arrows / ← → keys / swipe, captions,
  thumbnail strip, Esc or swipe down to close.
  Since 6.9.2 it covers the whole screen including the site menu, on solid black with a
  margin all round the photo (it sits on `<body>`; the PIN lock removes it with the app).
- **Month view:** a small round cover thumbnail with the number of photos; click it to
  open the album – the same collage as in the day view, in its own window, with *Open day*
  (6.9; before, it opened only the first photo in the viewer).
- **Storage:** JSON in the new `photos` column (plus `album_title`). `image_url` keeps the
  cover and `image_caption` all captions, so Sol reads and searches captions and album
  titles. Old single pictures show as one-photo albums – nothing to migrate.
- Photos are ordinary media-library files, i.e. reachable by their address.

## PIN lock (since 5.5)
- The first time you open the calendar page you are asked to choose a PIN (4–8 digits).
- After that, the page always opens on a lock screen. Calendar data is **not** in the page
  source; the server only sends it after the correct PIN.
- **No automatic idle lock (6.5).** The timer was removed: it kept locking mid-writing.
  Kalendarz now locks only when you press **🔒 Lock** (top bar, day window or Sol) or close
  the tab. Lock it yourself when you finish.
- Backstop: the server forgets an unlocked session after 12 hours without any request
  (the open page sends a heartbeat every minute, so this only happens after the computer
  slept or the tab was frozen that long).
- If it locks while you have unsaved text, the text is kept on the server as a draft and
  reopened after you enter the PIN again (yellow "Restored" banner). Save it or discard it.
- Reloads made by the calendar itself (e.g. after *Save*) don't ask for the PIN again.
  Closing the tab always forgets the session.
- 5 wrong attempts → 15-minute lockout.
- **Forgot PIN?** e-mails a one-time link (valid 30 min) to your WordPress account address.
  If e-mail doesn't work on the site, delete the user meta `_sdc_pin_hash` for your user
  (e.g. in phpMyAdmin, table `wp_usermeta`) and you'll be asked to set a new PIN.
- Timings are constants at the top of the `SimpleDailyCalendar` class
  (`SERVER_IDLE_SECONDS`, `MAX_PIN_ATTEMPTS`, `PIN_LOCKOUT_SECONDS`, …).

## Sol – coach / mentor chat (since 6.0)
- **💬 Sol** button next to 🔒 Lock. Works only while Kalendarz is unlocked; locking closes it.
- Sol is a coach/mentor character (editable under **⚙︎ Character**) that can read the calendar
  through **read-only** tools: overview, entries for a date range (max 92 days), text search,
  books/films/series, statistics (habits, weight), and earlier conversations.
- Conversations are stored in two tables (`wp_daily_calendar_chats`, `wp_daily_calendar_chat_messages`)
  and can be deleted from the list.
- Writing to Sol uses the 5-minute writing limit; an unsent message is rescued on lock.
  If the page locks while Sol is answering, the answer is still saved.

### Setup
Add to `wp-config.php` (above "That's all, stop editing!"):

```php
define( 'SDC_OPENAI_API_KEY', 'sk-...' );       // required
define( 'SDC_OPENAI_MODEL', 'gpt-6.1-sol' );     // optional (default gpt-6.1-sol)
define( 'SDC_OPENAI_REASONING', 'medium' );      // optional: low | medium | high
```

After uploading the files, open any wp-admin page once so the two chat tables are created
(they are also created automatically on first use).

### Privacy
- The API key stays on the server. The browser only talks to WordPress.
- Requests use `store: false`. Per OpenAI's API data policy, API data is not used for training by
  default; abuse-monitoring logs may be kept up to 30 days.
- Only what a question needs is sent: Sol requests specific dates/searches through its tools.

## Saving and the text editor (6.8)
- **SAVE** (was *Save Content*) saves and keeps the day window open, still in edit mode, so you can
  go straight to *Previous* / *Next*. The button shows *SAVING…*, then a green *SAVED ✓*. The month
  view updates its icons without reloading the page. *Cancel* after a save reloads the saved day.
- The text boxes use **Quill 2.0.3** instead of CKEditor – the same editor as TBT Notes, bundled in
  `assets/vendor/quill/` (no CDN). Toolbar: **bold**, *italic*, ~~strikethrough~~ and five
  highlighters (blue, red, yellow, pink, green – the Notes colours, in the Notes order).
- Shortcuts: Ctrl+B / Ctrl+I, **Alt+1…5** highlight colours, **Alt+0** removes a highlight. Select
  text first, or pick a colour with nothing selected to type in that colour.
- Highlights are stored as `<span class="sdc-hl-yellow">…</span>`. Old entries open unchanged:
  bold, italic, lists, links, headings and quotes are kept; pasted colours and fonts are dropped.
