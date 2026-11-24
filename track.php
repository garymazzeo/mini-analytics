<?php
/**
 * track.php
 * Public endpoint, called cross-origin from every site's tracker.js.
 *
 * Expects a POST body (JSON; tracker.js sends it as text/plain via
 * sendBeacon to avoid a CORS preflight, which is fine because we parse
 * php://input ourselves). Answers 204 with no body on success:
 * {
 *   "site": "personal",         // must be a key in ALLOWED_SITES
 *   "event_type": "pageview",   // optional, defaults to "pageview"
 *   "path": "/",
 *   "visit_id": "a1b2c3...",    // per-page-load id from tracker.js
 *   "referrer": "https://...",  // document.referrer (optional)
 *   "meta": { ... }             // optional extra data
 * }
 */

require_once __DIR__ . '/lib.php';

// Largest request body we accept, and largest `meta` once re-encoded.
// A real tracker.js event is a few hundred bytes.
const MAX_BODY_BYTES = 4096;
const MAX_META_BYTES = 1024;

common_headers();
handle_cors();

header('Content-Type: application/json');

// Refuse to record anything while IP_SALT is still the placeholder: the
// hashes would be trivially reversible to raw IPs.
if (IP_SALT === 'change-me-to-a-random-string' || strlen(IP_SALT) < 20) {
    http_response_code(500);
    echo json_encode(['error' => 'server not configured']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

// Reject oversized bodies: check the declared length first, then read at
// most one byte past the limit in case the header was missing or wrong.
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_BODY_BYTES) {
    http_response_code(413);
    echo json_encode(['error' => 'payload too large']);
    exit;
}
$raw = file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);
if ($raw === false || strlen($raw) > MAX_BODY_BYTES) {
    http_response_code(413);
    echo json_encode(['error' => 'payload too large']);
    exit;
}

$data = json_decode($raw, true, 16);
if (!is_array($data)) {
    $data = [];
}

$site = valid_site(isset($data['site']) ? (string) $data['site'] : null);
if ($site === null) {
    http_response_code(400);
    echo json_encode(['error' => 'unknown site']);
    exit;
}

if (!origin_matches_site($site)) {
    http_response_code(403);
    echo json_encode(['error' => 'origin not allowed for this site']);
    exit;
}

if (rate_limited($site)) {
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['error' => 'too many events']);
    exit;
}

// String fields: anything that isn't a plain string/number is ignored.
// log_event() trims each to its column size and cleans the referrer.
$str = fn(string $k): ?string => isset($data[$k]) && is_scalar($data[$k]) && $data[$k] !== ''
    ? (string) $data[$k] : null;

$eventType = $str('event_type') ?? 'pageview';
$path      = $str('path');
$visitId   = $str('visit_id');
$referrer  = $str('referrer');
$meta      = isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : [];

if (strlen((string) json_encode($meta)) > MAX_META_BYTES) {
    http_response_code(413);
    echo json_encode(['error' => 'meta too large']);
    exit;
}

log_event($site, $eventType, $path, $visitId, $meta, $referrer);

// Success: nothing to say (tracker.js never reads the response).
http_response_code(204);
