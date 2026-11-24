<?php
/**
 * lib.php
 * Shared code for track.php and stats.php: loads config.php, then provides
 * the DB connection, site allowlist checks, CORS handling, IP hashing,
 * rate limiting and log_event(). Settings live in config.php; this file
 * holds no secrets and is safe to commit.
 */

// Prefer a config.php one level up (outside the web root); fall back to
// the one next to this file.
require_once is_file(__DIR__ . '/../config.php') ? __DIR__ . '/../config.php' : __DIR__ . '/config.php';

// Optional settings with defaults (define them in config.php to override).
defined('RATE_LIMIT_PER_MINUTE') || define('RATE_LIMIT_PER_MINUTE', 60);
defined('RETENTION_MONTHS')      || define('RETENTION_MONTHS', 24);

// ---- Error handling ----
// Never show errors to visitors: they can reveal the DB host, user, file
// paths or SQL. Log the details server-side and answer with a generic 500.
ini_set('display_errors', '0');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
set_exception_handler(function (Throwable $e): void {
    error_log('mini-analytics: ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo 'Server error';
});

function db(): mysqli {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

/**
 * Valid UTF-8, at most $max characters (matches VARCHAR(n) semantics).
 * Invalid byte sequences (e.g. from a user-agent header) are replaced, so
 * MySQL never rejects the row and multi-byte characters are never split.
 */
function clip(?string $s, int $max): ?string {
    if ($s === null) return null;
    $s = json_decode(json_encode($s, JSON_INVALID_UTF8_SUBSTITUTE));
    preg_match('/^.{0,' . $max . '}/us', $s, $m);
    return $m[0];
}

/**
 * Today's random salt, shared by all requests on the same day. "Today" is
 * the database's CURDATE(), the same clock stats.php groups days by, so a
 * visitor's hash never changes partway through a dashboard day.
 *
 * Usually one SELECT. The first request of each day creates the salt and
 * runs the daily housekeeping: deleting old salts (after which that day's
 * hashes can't be linked back to an IP, even by someone holding the
 * database, the code and IP_SALT) and old events.
 */
function daily_salt(): string {
    static $salt = null;
    if ($salt !== null) return $salt;

    $conn = db();
    $row  = $conn->query('SELECT salt FROM daily_salts WHERE day = CURDATE()')->fetch_row();
    if ($row) return $salt = $row[0];

    // First request today. INSERT IGNORE: if two requests race, one wins
    // and both read back the same salt.
    $new  = random_bytes(32);
    $stmt = $conn->prepare('INSERT IGNORE INTO daily_salts (day, salt) VALUES (CURDATE(), ?)');
    $stmt->bind_param('s', $new);
    $stmt->execute();
    $created = $stmt->affected_rows === 1;
    $stmt->close();

    $salt = $conn->query('SELECT salt FROM daily_salts WHERE day = CURDATE()')->fetch_row()[0];

    if ($created) {
        // Keep yesterday's salt too, so requests straddling midnight don't fail.
        $conn->query('DELETE FROM daily_salts WHERE day < CURDATE() - INTERVAL 1 DAY');
        $conn->query('DELETE FROM events WHERE created_at < NOW() - INTERVAL ' . (int) RETENTION_MONTHS . ' MONTH');
    }
    return $salt;
}

/** Headers every response should carry. */
function common_headers(): void {
    header('X-Content-Type-Options: nosniff');
}

/**
 * One-way, per-site, per-day hash of the visitor's IP. Never store the raw
 * IP. The same visitor gets a different hash on each site and each day,
 * so sites can't be linked and old rows can't be reversed.
 */
function hashed_ip(string $site): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return hash_hmac('sha256', $site . '|' . $ip, IP_SALT . daily_salt());
}

/**
 * Reduce a referrer to scheme + host + path. Query strings and fragments
 * often carry search terms, tokens or session ids, so they're dropped.
 * Returns null for anything that isn't an http(s) URL.
 */
function clean_referrer(?string $url): ?string {
    if ($url === null || $url === '') return null;
    $p = parse_url($url);
    if ($p === false || !isset($p['scheme'], $p['host'])) return null;
    $scheme = strtolower($p['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') return null;
    $port = isset($p['port']) ? ':' . $p['port'] : '';
    return $scheme . '://' . strtolower($p['host']) . $port . ($p['path'] ?? '/');
}

/** Returns the site name if it's in the allowlist, otherwise null. */
function valid_site(?string $site): ?string {
    return ($site !== null && isset(ALLOWED_SITES[$site])) ? $site : null;
}

/** True if $origin is one of the allowed origins (for any site, or for $site if given). */
function origin_allowed(string $origin, ?string $site = null): bool {
    if ($origin === '') return false;
    if ($site !== null) {
        return in_array($origin, ALLOWED_SITES[$site] ?? [], true);
    }
    foreach (ALLOWED_SITES as $origins) {
        if (in_array($origin, $origins, true)) return true;
    }
    return false;
}

/**
 * Send CORS headers if the request's Origin is one of your sites, and
 * answer browser preflight (OPTIONS) requests. Call at the top of any
 * endpoint that other sites' JavaScript will call.
 *
 * Note: CORS only controls which *browsers* may read the response. The
 * real gatekeeping is the site/origin check in the endpoint itself.
 */
function handle_cors(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    // Always vary on Origin, so a cache never serves one site's response
    // (with or without CORS headers) to another.
    header('Vary: Origin');

    if (origin_allowed($origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/**
 * Confirm the request really comes from the origin that owns $site.
 * Browsers always send an Origin header on cross-origin POSTs, so a
 * missing/mismatched Origin is rejected. (A non-browser script can fake
 * the header, so this stops casual junk, not a determined attacker.)
 */
function origin_matches_site(string $site): bool {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    return origin_allowed($origin, $site);
}

/**
 * True if this visitor has already sent RATE_LIMIT_PER_MINUTE events for
 * $site in the last minute. Counts by ip_hash, so it uses idx_ip_time.
 * Behind a reverse proxy/CDN, REMOTE_ADDR is the proxy, and every visitor
 * would share one limit: see the README.
 */
function rate_limited(string $site): bool {
    $ipHash = hashed_ip($site);
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM events
         WHERE ip_hash = ? AND created_at >= NOW() - INTERVAL 1 MINUTE'
    );
    $stmt->bind_param('s', $ipHash);
    $stmt->execute();
    $count = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $count >= RATE_LIMIT_PER_MINUTE;
}

/**
 * Insert one event row. $meta is any associative array; it's stored as JSON.
 * Values are trimmed to their column sizes and the referrer is reduced to
 * scheme + host + path.
 */
function log_event(
    string $site,
    string $eventType,
    ?string $path = null,
    ?string $visitId = null,
    array $meta = [],
    ?string $referrer = null
): void {
    $stmt = db()->prepare(
        'INSERT INTO events (site, event_type, path, visit_id, ip_hash, user_agent, referrer, meta)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $site      = clip($site, 100);
    $eventType = clip($eventType, 50);
    $path      = clip($path, 255);
    $visitId   = clip($visitId, 64);
    $ipHash    = hashed_ip($site);
    $ua        = clip($_SERVER['HTTP_USER_AGENT'] ?? '', 255);
    $referrer  = clip(clean_referrer($referrer), 255);
    $metaJson  = empty($meta) ? null : json_encode($meta, JSON_INVALID_UTF8_SUBSTITUTE);

    $stmt->bind_param(
        'ssssssss',
        $site, $eventType, $path, $visitId, $ipHash, $ua, $referrer, $metaJson
    );
    $stmt->execute();
    $stmt->close();
}
