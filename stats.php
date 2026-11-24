<?php
/**
 * stats.php
 * A bare-bones, password-protected dashboard for all your sites.
 *   https://stats.yourdomain.com/stats.php                -> every site combined
 *   https://stats.yourdomain.com/stats.php?site=personal  -> one site
 * The browser asks for a password (HTTP basic auth; any username).
 * Serve it over HTTPS only: basic auth sends the password with every request.
 */

require_once __DIR__ . '/lib.php';

// Refuse to serve the dashboard while the password is a placeholder or too
// short to resist guessing (there's no limit on login attempts).
if (STATS_PASSWORD === 'change-me-too' || strlen(STATS_PASSWORD) < 20) {
    http_response_code(500);
    exit('Set STATS_PASSWORD in config.php to a long random string (20+ characters).');
}

// Security headers (sent before any output, including the 401 below).
// - CSP: no scripts, no external resources; inline styles only (the
//   dashboard uses a <style> block). frame-ancestors stops anyone
//   embedding this page in a frame.
// - Referrer-Policy: don't tell other sites the dashboard's URL if you
//   ever click an outbound link.
// - Cache-Control: don't keep copies of your stats in caches.
// - X-Robots-Tag: keeps search engines from indexing the dashboard.
common_headers();
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

/**
 * Password from HTTP basic auth. PHP fills PHP_AUTH_PW under mod_php; under
 * PHP-FPM/CGI the raw Authorization header may arrive in HTTP_AUTHORIZATION
 * or REDIRECT_HTTP_AUTHORIZATION instead (Apache needs `CGIPassAuth On`).
 */
function basic_auth_password(): string {
    if (isset($_SERVER['PHP_AUTH_PW'])) return (string) $_SERVER['PHP_AUTH_PW'];
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($h, 'Basic ') !== 0) return '';
    $decoded = base64_decode(substr($h, 6), true);
    if ($decoded === false || !str_contains($decoded, ':')) return '';
    return explode(':', $decoded, 2)[1];
}

if (!hash_equals(STATS_PASSWORD, basic_auth_password())) {
    http_response_code(401);
    header('WWW-Authenticate: Basic realm="Stats", charset="UTF-8"');
    exit('Password required');
}

$conn = db();

// Selected site: must be in the allowlist, or 'all'.
$selected = $_GET['site'] ?? 'all';
if ($selected !== 'all' && valid_site($selected) === null) {
    $selected = 'all';
}

// Safe to interpolate: $selected is either 'all' or an allowlisted key,
// and we escape it anyway.
$siteSql = $selected === 'all'
    ? ''
    : " AND site = '" . $conn->real_escape_string($selected) . "'";

function fetch_all(mysqli $conn, string $sql): array {
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

// All dates below use the database's clock (NOW(), DATE()), the same one
// daily_salt() rotates on, so per-day unique counts line up with salts.

// Per-site summary, last 30 days (most useful on the "all" view).
// IP hashes rotate daily, so distinct hashes over 30 days count each
// visitor once per day they visited: "visitor days", not unique people.
$perSite = fetch_all($conn, "
    SELECT site,
           SUM(event_type = 'pageview') AS pageviews,
           COUNT(DISTINCT ip_hash) AS visitor_days,
           COUNT(*) AS total_events
    FROM events
    WHERE created_at >= (NOW() - INTERVAL 30 DAY)
    GROUP BY site ORDER BY pageviews DESC
");

$pageviewsByDay = fetch_all($conn, "
    SELECT DATE(created_at) AS day,
           COUNT(*) AS pageviews,
           COUNT(DISTINCT ip_hash) AS unique_visitors
    FROM events
    WHERE event_type = 'pageview' AND created_at >= (NOW() - INTERVAL 30 DAY) $siteSql
    GROUP BY day ORDER BY day DESC
");

$topPages = fetch_all($conn, "
    SELECT path, COUNT(*) AS count
    FROM events
    WHERE event_type = 'pageview' AND created_at >= (NOW() - INTERVAL 30 DAY) $siteSql
    GROUP BY path ORDER BY count DESC LIMIT 20
");

$eventCounts = fetch_all($conn, "
    SELECT event_type, COUNT(*) AS count
    FROM events
    WHERE created_at >= (NOW() - INTERVAL 30 DAY) $siteSql
    GROUP BY event_type ORDER BY count DESC
");

$topReferrers = fetch_all($conn, "
    SELECT referrer, COUNT(*) AS count
    FROM events
    WHERE event_type = 'pageview' AND referrer IS NOT NULL AND referrer != ''
      AND created_at >= (NOW() - INTERVAL 30 DAY) $siteSql
    GROUP BY referrer ORDER BY count DESC LIMIT 20
");

function table(string $title, array $rows, array $cols): void {
    echo '<h2>' . htmlspecialchars($title) . '</h2>';
    if (empty($rows)) {
        echo '<p><em>No data yet.</em></p>';
        return;
    }
    echo '<table><tr>';
    foreach ($cols as $c) echo '<th>' . htmlspecialchars(str_replace('_', ' ', $c)) . '</th>';
    echo '</tr>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($cols as $c) echo '<td>' . htmlspecialchars((string) ($row[$c] ?? '')) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stats</title>
<style>
    body { font-family: system-ui, sans-serif; max-width: 800px; margin: 2rem auto; padding: 0 1rem; }
    table { border-collapse: collapse; width: 100%; margin-bottom: 2rem; }
    th, td { text-align: left; padding: 0.4rem 0.8rem; border-bottom: 1px solid #ddd; word-break: break-all; }
    th { background: #f5f5f5; }
    h2 { margin-top: 2rem; }
    nav a { margin-right: 0.75rem; text-decoration: none; padding: 0.25rem 0.6rem; border-radius: 4px; border: 1px solid #ccc; }
    nav a.active { background: #222; color: #fff; border-color: #222; }
</style>
</head>
<body>
<h1>Site Stats</h1>

<nav>
    <a href="?site=all" class="<?= $selected === 'all' ? 'active' : '' ?>">All sites</a>
    <?php foreach (array_keys(ALLOWED_SITES) as $s): ?>
        <a href="?site=<?= urlencode($s) ?>" class="<?= $selected === $s ? 'active' : '' ?>"><?= htmlspecialchars($s) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($selected === 'all') table('Per site (30d)', $perSite, ['site', 'pageviews', 'visitor_days', 'total_events']); ?>
<?php table('Pageviews per day (30d)', $pageviewsByDay, ['day', 'pageviews', 'unique_visitors']); ?>
<?php table('Top pages (30d)', $topPages, ['path', 'count']); ?>
<?php table('Events by type (30d)', $eventCounts, ['event_type', 'count']); ?>
<?php table('Top referrers (30d)', $topReferrers, ['referrer', 'count']); ?>

</body>
</html>
