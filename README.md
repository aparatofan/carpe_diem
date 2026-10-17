# Carpe Diem

Private calendar project (WordPress plugin, shortcode `[daily_calendar]`).

## Files
- simple-daily-calendar.php – main PHP file
- includes/sdc-sol.php – Sol (OpenAI chat): conversation loop, read-only data tools, chat storage
- assets/sdc-script.js – calendar JavaScript
- assets/sdc-lock.js – PIN lock screen + auto-lock
- assets/sdc-sol.js – Sol chat panel
- assets/sdc-style.css – CSS
- .github/workflows/deploy.yml – automatic upload to the website (not uploaded itself)

## Automatic deploy (since 6.1)
Same deploy as the TBT plugins (copied from drag-and-drop). A push or merge to `main` runs
`.github/workflows/deploy.yml`, which uploads changed files over FTPS to
`/simple-daily-calendar/`. Markdown files and `.github/` are not uploaded, and a
documentation-only push skips the deploy.

Before uploading it checks that the three secrets exist (`FTP_SERVER`, `FTP_USERNAME`,
`FTP_PASSWORD`), that `/simple-daily-calendar/` on the server already contains
`simple-daily-calendar.php`, and that every PHP file has valid syntax. If an upload is cut
off, it waits 60 s and retries once.

By hand: GitHub → **Actions** → **Deploy to WordPress** → **Run workflow** (*Preview only*
lists what would change; *protocol*, *port* and *log level* are there for troubleshooting).

The action uploads but never deletes, so two names are fixed:
- **`simple-daily-calendar.php`** must keep its name, or WordPress would show two plugins.
- **`/simple-daily-calendar/`** must stay the server folder, for the same reason.

Database changes (like the new reading column) are applied automatically on the next page load.

## Habits
🤫 YKW · 🚴 Sport · 🚿 Shower · 👓 Reading (6.1). Reading shows a streak counter next to the
box (“Keep your 4-day streak going”) and in the day view; it's in the filter bar, the CSV
export and Sol's statistics (`reading_streak`).

## PIN lock (since 5.5)
- The first time you open the calendar page you are asked to choose a PIN (4–8 digits).
- After that, the page always opens on a lock screen. Calendar data is **not** in the page
  source; the server only sends it after the correct PIN.
- Auto-lock after 60 s with no mouse, keyboard, scroll or touch activity
  (a countdown appears for the last 10 s). The **🔒 Lock** button locks immediately.
- **Writing mode (5.6):** while you are writing an entry (you have typed something not yet
  saved, or the cursor is in a text field) the limit is 5 minutes instead, with a 30 s countdown.
- If it locks while you have unsaved text, the text is kept on the server as a draft and
  reopened after you enter the PIN again (yellow "Restored" banner). Save it or discard it.
- Reloads made by the calendar itself (e.g. after *Save*) don't ask for the PIN again
  as long as the minute hasn't passed. Closing the tab always forgets the session.
- 5 wrong attempts → 15-minute lockout.
- **Forgot PIN?** e-mails a one-time link (valid 30 min) to your WordPress account address.
  If e-mail doesn't work on the site, delete the user meta `_sdc_pin_hash` for your user
  (e.g. in phpMyAdmin, table `wp_usermeta`) and you'll be asked to set a new PIN.
- Timings are constants at the top of the `SimpleDailyCalendar` class
  (`IDLE_SECONDS`, `WRITING_IDLE_SECONDS`, `MAX_PIN_ATTEMPTS`, `PIN_LOCKOUT_SECONDS`, …).

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
