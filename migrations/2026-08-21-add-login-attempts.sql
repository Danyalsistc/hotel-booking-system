-- ===========================================================================
--  Migration: login attempt throttling                  Applied: 2026-08-21
-- ---------------------------------------------------------------------------
--  Additive only - one new table. No existing table is touched, so every user,
--  booking, room and room type is left exactly as it was. Re-runnable via
--  CREATE TABLE IF NOT EXISTS.
--
--  Not needed for a new installation; database.sql already has this schema.
--  Usage: mysql -u root hotel_booking < migrations/2026-08-21-...sql
-- ===========================================================================

-- Failed login attempts, recorded per client IP.
--
-- Per IP and not per email: counting failures against an email address would
-- let anybody lock a real customer out of their own account simply by guessing
-- wrong at it, turning the throttle into a denial-of-service tool aimed at the
-- victim. Counting per IP throttles the guesser instead. The check also runs
-- BEFORE the email is looked up, so being throttled reveals nothing about
-- whether an address is registered.
--
-- Deliberately NOT stored: no email, username, password, hash, or any
-- indication of which account was targeted. A row records only that some
-- failed attempt came from an address at a time.
--
-- Retention: the application deletes rows older than the throttle window every
-- time the limiter runs, so IP addresses are not kept indefinitely.
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- 45 characters is the longest textual IP address (IPv4-mapped IPv6).
    `ip_address`   VARCHAR(45) NOT NULL,

    `attempted_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- Serves the only query this table has: "how many failures from this IP
    -- since <time>?", plus the matching delete.
    KEY `idx_login_attempts_ip_time` (`ip_address`, `attempted_at`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
