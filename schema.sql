-- Minimal, extensible, multi-site analytics schema.
-- Every event (pageview, button click, whatever you think of
-- in six months) is a row here, tagged with the site it came
-- from. The `meta` JSON column means you never have to ALTER TABLE again
-- to track a new detail.

CREATE TABLE IF NOT EXISTS events (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site        VARCHAR(100) NOT NULL,        -- which of your sites: 'personal', 'workout', ...
    event_type  VARCHAR(50)  NOT NULL,        -- 'pageview', 'chat_started', 'click', ...
    path        VARCHAR(255) DEFAULT NULL,    -- e.g. '/', '/about'
    visit_id    VARCHAR(64)  DEFAULT NULL,    -- per-page-load random id (JS-generated, not a cookie)
    ip_hash     VARCHAR(64)  NOT NULL,        -- HMAC of site + IP, salt rotates daily; never the raw IP
    user_agent  VARCHAR(255) DEFAULT NULL,
    referrer    VARCHAR(255) DEFAULT NULL,    -- where the visitor came from (document.referrer)
    meta        JSON         DEFAULT NULL,    -- free-form extra data per event
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Each index serves specific stats.php / lib.php queries:
    INDEX idx_site_type_time (site, event_type, created_at), -- one site: pageviews, top pages, referrers
    INDEX idx_type_time (event_type, created_at),            -- all sites: pageviews, top pages, referrers
    INDEX idx_site_time (site, created_at),                  -- one site: events by type
    INDEX idx_time (created_at),                             -- all sites: per-site summary, events by type
    INDEX idx_ip_time (ip_hash, created_at)                  -- rate limit in lib.php
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One random salt per UTC day for hashing IPs. lib.php creates today's row
-- on first use and deletes rows older than yesterday, so past days' hashes
-- can never be linked back to an IP.
CREATE TABLE IF NOT EXISTS daily_salts (
    day   DATE       NOT NULL PRIMARY KEY,
    salt  BINARY(32) NOT NULL
) ENGINE=InnoDB;
