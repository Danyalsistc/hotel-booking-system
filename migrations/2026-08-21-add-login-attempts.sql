-- ===========================================================================
--  Hotel Booking System - Migration: login attempt throttling
--  ICT304 Capstone 2
--  Applied: 2026-08-21
-- ---------------------------------------------------------------------------
--  Adds the table behind login rate limiting to an EXISTING database.
--
--  Purely ADDITIVE: one new table. Nothing existing is dropped, altered,
--  renamed or rewritten - no other table is touched at all, so every existing
--  user, booking, room and room type is left exactly as it was.
--
--  For a brand-new installation this file is not needed; database.sql already
--  contains the final schema.
--
--  Usage:
--      mysql -u root hotel_booking < migrations/2026-08-21-add-login-attempts.sql
--
--  Re-runnable: CREATE TABLE IF NOT EXISTS is standard SQL and idempotent on
--  both MySQL and MariaDB.
-- ===========================================================================

-- ---------------------------------------------------------------------------
--  Failed login attempts, recorded per client IP address.
--
--  WHY PER IP AND NOT PER EMAIL
--  Counting failures against an email address would let anybody lock a known
--  customer out of their own account simply by guessing wrong at it - the
--  throttle would become a denial-of-service tool aimed at real users.
--  Counting per IP throttles the guesser instead of the victim.
--
--  It also keeps the login response honest: the check runs BEFORE the email is
--  looked up, so being throttled says nothing about whether an address is
--  registered.
--
--  WHAT IS DELIBERATELY NOT STORED
--  No email address, no username, no password, no password hash, and no
--  indication of which account was being targeted. A row records only that
--  *some* failed attempt came from an address at a time. There is therefore
--  nothing here worth stealing, and nothing that can be replayed.
--
--  RETENTION
--  Rows older than the throttle window are deleted by the application every
--  time the limiter runs, so this table stays small and IP addresses are not
--  retained indefinitely.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- 45 characters is the longest possible textual IP address: an
    -- IPv4-mapped IPv6 form such as
    -- 'ffff:ffff:ffff:ffff:ffff:ffff:255.255.255.255'.
    `ip_address`   VARCHAR(45) NOT NULL,

    `attempted_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- The only query this table serves is
    -- "how many failures from this IP since <time>?", plus the matching
    -- delete. One composite index covers both.
    KEY `idx_login_attempts_ip_time` (`ip_address`, `attempted_at`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
