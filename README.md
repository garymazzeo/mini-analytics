# Minimal PHP/MySQL Analytics (multi-site)

A tiny, self-hosted, cookieless analytics tracker for several small sites.
One central install, two tables, a handful of PHP files, no dependencies
beyond `mysqli`. Tracks pageviews plus any custom events you want.

## How it fits together

```
site A  ──┐                                   ┌── events table (site column)
site B  ──┼─ tracker.js ─> track.php ────────>┤
site C  ──┘                                   └── stats.php (dashboard, per-site switcher)
```

Install it once on a host you control (e.g. `https://stats.yourdomain.com/`).
Every site just adds one `<script>` tag pointing at it.

## Files

| File | Purpose |
|---|---|
| `schema.sql` | The `events` and `daily_salts` tables |
| `config.example.php` | Template for `config.php`: DB credentials, secrets, **site allowlist** |
| `lib.php` | Shared code: DB, CORS, IP hashing, rate limit, `log_event` |
| `track.php` | Public endpoint that receives events from every site |
| `tracker.js` | The snippet each site includes |
| `stats.php` | Password-protected dashboard with a site switcher |

## Setup

### 1. Create the tables

```bash
mysql -u your_user -p your_db_name < schema.sql
```

### 2. Configure `config.php`

Copy the template first (the real `config.php` is gitignored so your
secrets never get committed):

```bash
cp config.example.php config.php
```

- Fill in the DB credentials.
- Set `IP_SALT` and `STATS_PASSWORD` to long random strings:
  `php -r "echo bin2hex(random_bytes(32));"`
- Edit `ALLOWED_SITES`: each key is the name you'll use in `data-site`,
  each value is the list of exact origins (scheme + host, no trailing
  slash) allowed to post for that site. Include the `www.` variant if you
  serve both.

```php
const ALLOWED_SITES = [
    'personal' => ['https://example.com', 'https://www.example.com'],
    'workout'  => ['https://workout.example.com'],
];
```

Anything not listed is rejected, so strangers can't fill your table with
junk under made-up site names.

### 3. Upload to your server

Put `track.php`, `stats.php`, `lib.php`, `tracker.js`, and `config.php` in the folder
served at your stats URL. If you can, put `config.php` one level *above*
that folder instead (outside the web root). `track.php` and `stats.php`
look there first and fall back to the copy beside them, so no code
changes are needed.

`stats.php` refuses to load while `STATS_PASSWORD` is the placeholder or
shorter than 20 characters, and `track.php` refuses events while
`IP_SALT` is. `track.php` also rejects request bodies over 4 KB and
`meta` over 1 KB (adjust `MAX_BODY_BYTES` / `MAX_META_BYTES` in
`track.php` if you need more), and answers `429` once a visitor sends
more than 60 events a minute to one site (set `RATE_LIMIT_PER_MINUTE` in
`config.php` to change it).

Events older than 24 months are deleted automatically, once a day, by the
first event of the day (set `RETENTION_MONTHS` in `config.php` to change
it). No cron job needed.

Errors are logged with PHP's `error_log` and never shown to visitors.

**Behind a reverse proxy or CDN** (Cloudflare etc.), `REMOTE_ADDR` is the
proxy's address, so every visitor would hash the same and share one rate
limit. Configure your web server to restore the real client IP (e.g.
nginx `real_ip`, Apache `mod_remoteip`) rather than trusting
`X-Forwarded-For` in PHP.

### 4. Add one tag to each site

```html
<script src="https://stats.yourdomain.com/tracker.js" data-site="personal" defer></script>
```

`data-site` must match a key in `ALLOWED_SITES`. The script works out the
endpoint from its own `src`, so there's nothing else to configure.

### 5. View your stats

```
https://stats.yourdomain.com/stats.php
```

Your browser asks for a username and password: enter `STATS_PASSWORD` as
the password (the username can be anything). Use the buttons at the top
to switch between "All sites" and each site.

Keeps asking for the password even though it's right? Your server isn't
passing the `Authorization` header to PHP. On Apache with PHP-FPM, add
`CGIPassAuth On` to an `.htaccess` file next to `stats.php`.

## Adding a new site later

1. Add it to `ALLOWED_SITES` in `config.php` (name + origin(s)).
2. Put the `<script … data-site="new-name">` tag on that site.

No database change needed.

## Custom events

After `tracker.js` loads, your page code can log anything:

```js
siteAnalytics.track('chat_started');
siteAnalytics.track('button_click', { id: 'signup' });
```

The second argument is stored in the JSON `meta` column (query it with
`JSON_EXTRACT(meta, '$.id')`). New event types show up in the dashboard's
"Events by type" table automatically — no schema change.

From PHP you can also call
`log_event($site, $eventType, $path, $visitId, $meta, $referrer)`.

## Privacy notes

- **Cookieless.** `visitId` is generated in JS on each page load and kept
  only in memory — no cookie, localStorage, or sessionStorage.
- **IPs are hashed, per site and per day.** `ip_hash` is an HMAC-SHA256
  of the site name and IP, keyed with `IP_SALT` plus a random salt that
  rotates every day at midnight on the database's clock. Raw IPs are never stored. Salts older than
  yesterday are deleted, so old hashes can't be reversed even by someone
  holding the database and `IP_SALT`, and the same visitor gets
  unrelated hashes on different sites and different days.
- **What the counts mean.** "Unique visitors" per day counts distinct
  hashes that day (shared IPs merge people, changing IPs split them).
  Because hashes rotate daily, the 30-day per-site summary shows
  **visitor days** (someone visiting on 3 days counts 3), not unique
  people. Days in the dashboard follow the database's time zone.
- **Referrers are trimmed** to scheme + host + path. Query strings and
  fragments (search terms, tokens, session ids) are dropped.
- **Kept for 24 months**, then deleted (see `RETENTION_MONTHS`).

## Security notes

- CORS and the origin check stop casual junk, but a non-browser script can
  fake the `Origin` header, so someone determined could still insert fake
  events, up to the per-IP rate limit. For personal-site stats that's an
  acceptable trade-off.
- `stats.php` sends a strict CSP, `Referrer-Policy: no-referrer`,
  `Cache-Control: no-store`, and `noindex`. If you later add scripts to
  the dashboard, loosen the CSP.
- `stats.php` uses HTTP basic auth, which sends the password with every
  request: **serve the stats host over HTTPS only.** There's no limit on
  login attempts, which is why the password must be 20+ random
  characters.
- If your sites send a Content-Security-Policy, allow the stats host:
  `script-src 'self' https://stats.yourdomain.com; connect-src 'self' https://stats.yourdomain.com;`
