# Carpe Diem

Private calendar project (WordPress plugin, shortcode `[daily_calendar]`).

## Files
- simple-daily-calendar.php – main PHP file
- assets/sdc-script.js – calendar JavaScript
- assets/sdc-lock.js – PIN lock screen + auto-lock
- assets/sdc-style.css – CSS

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
