/**
 * tracker.js
 * Add to any of your sites with ONE tag:
 *
 *   <script src="https://stats.yourdomain.com/tracker.js" data-site="personal" defer></script>
 *
 * - data-site must match a key in ALLOWED_SITES (config.php).
 * - The tracking endpoint is derived from where this script is hosted,
 *   so there's nothing else to configure.
 * - No cookies, no localStorage: visitId lives only in memory for this
 *   page load.
 *
 * It exposes window.siteAnalytics so your own page code can log custom
 * events and reuse the visitId:
 *
 *   siteAnalytics.track('chat_started');
 *   siteAnalytics.track('button_click', { id: 'signup' });
 *   siteAnalytics.visitId   // random id for this page load
 */
(function () {
    var script = document.currentScript;
    if (!script) return;

    var site = script.getAttribute('data-site');
    if (!site) {
        console.warn('tracker.js: missing data-site attribute');
        return;
    }

    var endpoint = new URL(script.src).origin + '/track.php';

    // crypto.randomUUID() only exists on HTTPS pages in recent browsers;
    // crypto.getRandomValues() works on plain HTTP and much older ones.
    function newVisitId() {
        try {
            if (crypto.randomUUID) return crypto.randomUUID();
            var bytes = crypto.getRandomValues(new Uint8Array(16));
            return Array.prototype.map.call(bytes, function (b) {
                return ('0' + b.toString(16)).slice(-2);
            }).join('');
        } catch (e) {
            return Date.now().toString(36) + Math.random().toString(36).slice(2);
        }
    }
    var visitId = newVisitId();

    function send(eventType, meta) {
        try {
            var body = JSON.stringify({
                site: site,
                event_type: eventType,
                path: location.pathname,
                visit_id: visitId,
                referrer: eventType === 'pageview' ? (document.referrer || null) : null,
                meta: meta || {}
            });
            // A string body goes out as text/plain, a "simple" request, so
            // there's no CORS preflight. track.php parses it as JSON anyway.
            // sendBeacon is built for analytics: it survives the page
            // closing and never delays navigation. Fall back to fetch if
            // it's missing or the browser refuses to queue the beacon.
            if (navigator.sendBeacon && navigator.sendBeacon(endpoint, body)) return;
            fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'text/plain' },
                body: body,
                keepalive: true
            }).catch(function () {});
        } catch (e) { /* fail silently, never break the host page */ }
    }

    window.siteAnalytics = {
        site: site,
        visitId: visitId,
        track: send
    };

    send('pageview');
})();
