<?php
/**
 * config.php
 * Settings only: DB credentials, secrets and the site allowlist. The code
 * lives in lib.php. Copy config.example.php to config.php and fill it in.
 * Keep config.php OUTSIDE your public webroot if at all possible: put it
 * one level above the folder holding track.php / stats.php, and they'll
 * find it there automatically.
 */

// ---- Fill these in ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// Secret mixed into IP hashes (together with a random salt that rotates
// daily). Generate one with: php -r "echo bin2hex(random_bytes(32));"
// track.php refuses events while this is the placeholder.
define('IP_SALT', 'change-me-to-a-random-string');

// Password for stats.php (20+ characters; same command as above). The
// browser's login prompt asks for it; the username can be anything.
define('STATS_PASSWORD', 'change-me-too');

// Optional: max events per visitor per site per minute (default 60).
// define('RATE_LIMIT_PER_MINUTE', 60);

// Optional: delete events older than this many months (default 24).
// define('RETENTION_MONTHS', 24);

/**
 * Sites allowed to send events. Key = the site name used in data-site=""
 * and stored in the DB. Value = the exact origins (scheme + host, no
 * trailing slash) that are allowed to post for that site.
 *
 * Anything not listed here is rejected, so strangers can't stuff your
 * table with junk under made-up site names.
 */
const ALLOWED_SITES = [
    'personal' => ['https://example.com', 'https://www.example.com'],
    'workout'  => ['https://workout.example.com'],
    // 'another-site' => ['https://another.example.org'],
];
