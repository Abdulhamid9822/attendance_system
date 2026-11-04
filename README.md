# Attendance System

## Poster Marketplace deployment

1. Deploy over HTTPS on a permanent domain and point the web root at this application.
2. Create a private `.env` from `.env.example`. Set the production `APP_URL`, database credentials, Poster application ID/secret, and IVMS settings. Never commit `.env`; rotate any credentials that were previously stored in source files.
3. Configure the Poster application redirect URL to the deployed `/index.php` callback and verify that the `context` query parameter is preserved by Poster OAuth.
4. Set `SESSION_SAVE_PATH` to a writable directory outside the web root when the host does not provide suitable private temporary storage. The application uses strict, HttpOnly session cookies; route context values are selectors, not session credentials.
5. If the Hikvision agent API is used, set a randomly generated `AGENT_REGISTRATION_SECRET` with at least 32 characters and send it in the `X-Agent-Registration-Secret` header for `get_api_key` requests.
6. Confirm the Apache `.htaccess` protections are active, then test OAuth installation, account separation, branch permissions, synchronization, logout, and data deletion with separate Poster test accounts before submitting the Marketplace listing.

The old account-SID API, diagnostic endpoints, and legacy token-writing callback are disabled. The `ajax/settings_ajax.php` endpoint now uses the authenticated session's Poster account rather than resolving an account from a URL-derived SID.

## Work schedule changes

In the employee list, use **Schedule** to open the month calendar. Select a day to load its current check-in, check-out, and lunch times. Save changed times to start a new schedule from that date; it remains effective until another schedule change. Use **Shifts** in the main navigation to create, edit, or remove shared shift templates. In the employee calendar, choose a saved template and apply it to one date or select multiple dates to apply it to all at once. Each selected date becomes a schedule start date, and that schedule continues until the next saved schedule date. The schedule history is independent of salary settings and is used by attendance calculations.

Employee salary settings support a per-shift amount. It is paid once for a completed attended shift (both check-in and check-out); absent, half-day, and incomplete records do not receive the shift amount.
