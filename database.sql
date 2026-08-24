-- ===========================================================================
--  Hotel Booking System - Database Schema
--  ICT304 Capstone 2
-- ---------------------------------------------------------------------------
--  Target      : MySQL 8.0+ / MariaDB 10.4+ (XAMPP local development)
--  Engine      : InnoDB (required for foreign keys and transactions)
--  Charset     : utf8mb4 / utf8mb4_unicode_ci
--
--  HOW TO IMPORT
--    phpMyAdmin : Import tab -> choose this file -> Go
--    Command line: mysql -u root -p < database.sql
--
--  RERUNNABILITY
--    This script is written to be safely re-runnable. It uses
--    CREATE ... IF NOT EXISTS and INSERT IGNORE throughout, so running it a
--    second time will NOT drop tables and will NOT overwrite existing rows.
--    There are deliberately NO "DROP TABLE" or "DROP DATABASE" statements.
--
--  SECURITY NOTES
--    * No payment card details are stored anywhere in this schema.
--      Payment processing is explicitly out of scope for this project.
--    * Only password HASHES are stored (see users.password_hash). Plaintext
--      passwords are never persisted.
--    * NO administrator account is seeded by this script. Creating an admin
--      with a known or predictable password would be a security defect.
--      See the "CREATING AN ADMINISTRATOR" note at the bottom of this file.
-- ===========================================================================


-- ---------------------------------------------------------------------------
--  Database
-- ---------------------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS `hotel_booking`
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE `hotel_booking`;


-- ===========================================================================
--  TABLE: users
--  Registered accounts. Holds both customers and administrators, separated
--  by the `role` column, which is what later phases will use to protect the
--  administrator dashboard.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `fullname`      VARCHAR(120)    NOT NULL,

    -- Email is the login identifier.
    -- utf8mb4_unicode_ci is a CASE-INSENSITIVE collation, so the UNIQUE key
    -- below treats 'User@Example.com' and 'user@example.com' as the SAME
    -- address. This is what prevents duplicate-email registration.
    `email`         VARCHAR(190)    NOT NULL COLLATE utf8mb4_unicode_ci,

    -- Output of PHP password_hash(). 255 chars leaves room for future
    -- algorithms (bcrypt is 60, argon2id is ~96).
    `password_hash` VARCHAR(255)    NOT NULL,

    `role`          ENUM('customer','admin') NOT NULL DEFAULT 'customer',

    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- Enforces "one account per email address" at the database level.
    UNIQUE KEY `uq_users_email` (`email`),

    -- Supports admin listings filtered by role.
    KEY `idx_users_role` (`role`),

    -- Basic sanity checks (enforced on MySQL 8.0.16+ and MariaDB 10.2+;
    -- silently ignored on older servers, which is acceptable here).
    CONSTRAINT `chk_users_fullname_not_blank`
        CHECK (CHAR_LENGTH(TRIM(`fullname`)) > 0),
    CONSTRAINT `chk_users_email_shape`
        CHECK (`email` LIKE '%_@_%._%')
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Registered customers and administrators';


-- ===========================================================================
--  TABLE: room_types
--  The six bookable room categories. Price and capacity live here (once),
--  instead of being duplicated as hard-coded text across six HTML pages.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS `room_types` (
    `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `name`            VARCHAR(80)   NOT NULL,
    `description`     TEXT          NULL,

    -- Maximum number of guests permitted in this room type.
    -- This is the authoritative source for the occupancy business rule.
    `capacity`        TINYINT UNSIGNED NOT NULL,

    -- DECIMAL (never FLOAT) so money arithmetic is exact.
    -- Currency is AUD for this project.
    `price_per_night` DECIMAL(10,2) NOT NULL,

    -- Relative path to the representative image, e.g. images/DeluxeSuite/5.webp
    `image_path`      VARCHAR(255)  NULL,

    -- Soft-disable a room type without deleting it (protects booking history).
    `active`          TINYINT(1)    NOT NULL DEFAULT 1,

    `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_room_types_name` (`name`),
    KEY `idx_room_types_active` (`active`),

    -- Capacity and price must be positive (never zero or negative).
    CONSTRAINT `chk_room_types_capacity_positive`
        CHECK (`capacity` >= 1),
    CONSTRAINT `chk_room_types_price_positive`
        CHECK (`price_per_night` > 0)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Bookable room categories, prices in AUD';


-- ===========================================================================
--  TABLE: rooms
--  Physical, individually bookable rooms. Availability is calculated against
--  THIS table, not room_types - two guests can hold the same room TYPE on the
--  same night as long as they occupy different physical rooms.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS `rooms` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Human-facing door number, e.g. '101'. VARCHAR so numbers like '10A'
    -- remain possible.
    `room_number`  VARCHAR(10)  NOT NULL,

    `room_type_id` INT UNSIGNED NOT NULL,

    -- 'available'   - in service and bookable
    -- 'maintenance' - temporarily out of service
    -- 'inactive'    - retired from service (history preserved)
    `status`       ENUM('available','maintenance','inactive')
                   NOT NULL DEFAULT 'available',

    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rooms_room_number` (`room_number`),

    -- Composite index: availability searches filter on type AND status.
    KEY `idx_rooms_type_status` (`room_type_id`, `status`),

    -- RESTRICT: a room type that has physical rooms cannot be deleted.
    -- This protects the chain room_types -> rooms -> bookings.
    CONSTRAINT `fk_rooms_room_type`
        FOREIGN KEY (`room_type_id`) REFERENCES `room_types` (`id`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Individual physical rooms';


-- ===========================================================================
--  TABLE: bookings
--  A reservation of ONE physical room by ONE user for a date range.
--
--  DATE CONVENTION: the stay occupies [check_in, check_out) - the guest
--  leaves on check_out morning, so a new booking MAY start on the previous
--  booking's check_out date. Overlap test used by availability queries:
--      existing.check_in < requested.check_out
--      AND existing.check_out > requested.check_in
-- ===========================================================================
CREATE TABLE IF NOT EXISTS `bookings` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Customer-facing reference, e.g. 'HBS-20260729-A1B2C3'.
    -- Generated SERVER-SIDE in a later phase (never by the browser).
    `booking_reference` VARCHAR(32)  NOT NULL,

    `user_id`           INT UNSIGNED NOT NULL,
    `room_id`           INT UNSIGNED NOT NULL,

    `check_in`          DATE         NOT NULL,
    `check_out`         DATE         NOT NULL,

    `guest_count`       TINYINT UNSIGNED NOT NULL,

    -- Rate is COPIED from room_types.price_per_night at time of booking, so
    -- a later price change does not silently rewrite historical bookings.
    `nightly_rate`      DECIMAL(10,2) NOT NULL,
    `number_of_nights`  SMALLINT UNSIGNED NOT NULL,
    `total_price`       DECIMAL(10,2) NOT NULL,

    -- 'cancellation_requested' is APPENDED at the end of the list on purpose.
    -- An ENUM is stored as the ordinal position of its value, so keeping the
    -- original four members in their original order lets an existing database
    -- adopt this schema without rewriting a single row
    -- (see migrations/2026-08-20-add-cancellation-request.sql).
    `status`            ENUM('pending','confirmed','cancelled','completed',
                             'cancellation_requested')
                        NOT NULL DEFAULT 'pending',

    -- What the booking was immediately before the customer asked to cancel,
    -- so an administrator REJECTING the request can put it back exactly as it
    -- was. A request may begin from 'pending' or from 'confirmed', and sending
    -- every rejection back to 'confirmed' would silently approve bookings that
    -- staff never approved.
    --
    -- Typed ENUM('pending','confirmed') rather than mirroring the full status
    -- list: the database itself then makes it impossible for a rejection to
    -- resurrect a cancelled or completed booking. NULL means "no cancellation
    -- request is outstanding".
    `previous_status`   ENUM('pending','confirmed') NULL DEFAULT NULL,

    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- Unique AND indexed: guarantees no duplicate reference, and makes
    -- "look up my booking by reference" a single-row index lookup.
    UNIQUE KEY `uq_bookings_reference` (`booking_reference`),

    -- Primary availability index: "is room R free between D1 and D2?"
    KEY `idx_bookings_room_dates` (`room_id`, `check_in`, `check_out`),

    -- "Show me my bookings, newest first" (customer dashboard).
    KEY `idx_bookings_user` (`user_id`, `created_at`),

    -- "Show all pending bookings" (admin dashboard).
    KEY `idx_bookings_status` (`status`),

    -- Date-range scans for admin reporting.
    KEY `idx_bookings_check_in` (`check_in`),

    -- RESTRICT on both FKs: deleting a user or a room that has bookings is
    -- blocked, so financial/booking history can never be silently destroyed.
    CONSTRAINT `fk_bookings_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT `fk_bookings_room`
        FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    -- Business rules enforced at the database level.
    CONSTRAINT `chk_bookings_dates_ordered`
        CHECK (`check_out` > `check_in`),
    CONSTRAINT `chk_bookings_guest_count_positive`
        CHECK (`guest_count` >= 1),
    CONSTRAINT `chk_bookings_nights_positive`
        CHECK (`number_of_nights` >= 1),
    CONSTRAINT `chk_bookings_nightly_rate_positive`
        CHECK (`nightly_rate` > 0),
    CONSTRAINT `chk_bookings_total_price_positive`
        CHECK (`total_price` > 0)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Room reservations. No payment card data is stored.';


-- ===========================================================================
--  TABLE: login_attempts
--  Failed login attempts, recorded per client IP address, used to throttle
--  password guessing (see migrations/2026-08-21-add-login-attempts.sql).
--
--  WHY PER IP AND NOT PER EMAIL: counting failures against an email address
--  would let anybody lock a known customer out of their own account just by
--  guessing wrong at it, turning the throttle into a denial-of-service tool
--  aimed at real users. Counting per IP throttles the guesser instead.
--
--  It also keeps the login response honest: the check runs BEFORE the email
--  is looked up, so being throttled reveals nothing about whether an address
--  is registered.
--
--  DELIBERATELY NOT STORED: no email, no username, no password, no hash, and
--  no indication of which account was targeted. A row says only that some
--  failed attempt came from an address at a time - nothing worth stealing and
--  nothing that can be replayed.
--
--  RETENTION: the application deletes rows older than the throttle window
--  every time the limiter runs, so IP addresses are not kept indefinitely.
-- ===========================================================================
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- 45 characters is the longest possible textual IP address: an
    -- IPv4-mapped IPv6 form such as
    -- 'ffff:ffff:ffff:ffff:ffff:ffff:255.255.255.255'.
    `ip_address`   VARCHAR(45) NOT NULL,

    `attempted_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- The only query this table serves is "how many failures from this IP
    -- since <time>?", plus the matching delete. One composite index covers
    -- both. There is no foreign key: the row is deliberately not tied to any
    -- account, because we do not record which account was being tried.
    KEY `idx_login_attempts_ip_time` (`ip_address`, `attempted_at`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Failed login attempts per IP. No emails or passwords are stored.';


-- ===========================================================================
--  ###################  DEVELOPMENT-ONLY SEED DATA  ######################
--
--  Everything BELOW this line is demonstration data for local development
--  and assessment only. It is NOT production data.
--
--  INSERT IGNORE is used so this script stays re-runnable: on a second run
--  the duplicate-key rows are skipped and any edits you made are preserved.
--
--  NOTE: no user accounts are seeded (see the admin note at the bottom).
-- ===========================================================================


-- ---------------------------------------------------------------------------
--  SEED (dev only): room_types
--  Capacities and prices match the values currently shown on the room pages
--  and the occupancy rules currently coded in booknow.js.
--  Prices are AUD per night.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `room_types`
    (`name`, `description`, `capacity`, `price_per_night`, `image_path`, `active`)
VALUES
    ('Standard Twin Room',
     'Two single beds, 25 sqm. Air conditioning, free Wi-Fi, TV, mini fridge, coffee maker and bathtub.',
     2, 100.00, 'images/StandardTwinRoom/2.jpg', 1),

    ('Executive Twin Room',
     'Two single beds, 30 sqm. Air conditioning, free Wi-Fi, TV, mini fridge, coffee maker and bathtub.',
     2, 150.00, 'images/ExecutiveTwinRoom/6.jpg', 1),

    ('Superior Suite',
     'King bed, 40 sqm. Air conditioning, free Wi-Fi, TV, mini fridge, coffee maker and bathtub.',
     3, 200.00, 'images/SuperiorSuite/8.webp', 1),

    ('Deluxe Suite',
     'King bed, 50 sqm. Air conditioning, free Wi-Fi, TV, mini fridge, coffee maker and bathtub.',
     3, 250.00, 'images/DeluxeSuite/5.webp', 1),

    ('Executive Suite',
     'King bed, 60 sqm. Air conditioning, free Wi-Fi, TV, mini fridge, coffee maker, bathtub and a separate lounge area.',
     3, 300.00, 'images/ExecutiveSuite/5.webp', 1),

    ('Presidential Suite',
     'King bed, 80 sqm. Air conditioning, free Wi-Fi, TV, mini fridge, coffee maker, bathtub, private pool and jacuzzi.',
     5, 500.00, 'images/PresidentialSuiteRoom/5.webp', 1);


-- ---------------------------------------------------------------------------
--  SEED (dev only): rooms - 2 physical rooms per type = 12 rooms.
--
--  Demonstration numbering: the first digit is the floor, and each floor
--  holds one room type.
--      Floor 1 = Standard Twin    Floor 4 = Deluxe Suite
--      Floor 2 = Executive Twin   Floor 5 = Executive Suite
--      Floor 3 = Superior Suite   Floor 6 = Presidential Suite
--
--  Room 302 is seeded as 'maintenance' on purpose, so availability logic in
--  a later phase can be demonstrated excluding an out-of-service room.
--
--  room_type_id is resolved by NAME rather than hard-coded, so the seed does
--  not depend on auto-increment values.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `rooms` (`room_number`, `room_type_id`, `status`)
SELECT `seed`.`room_number`, `rt`.`id`, `seed`.`status`
FROM (
              SELECT '101' AS `room_number`, 'Standard Twin Room'  AS `type_name`, 'available'   AS `status`
    UNION ALL SELECT '102',                  'Standard Twin Room',                 'available'
    UNION ALL SELECT '201',                  'Executive Twin Room',                'available'
    UNION ALL SELECT '202',                  'Executive Twin Room',                'available'
    UNION ALL SELECT '301',                  'Superior Suite',                     'available'
    UNION ALL SELECT '302',                  'Superior Suite',                     'maintenance'
    UNION ALL SELECT '401',                  'Deluxe Suite',                       'available'
    UNION ALL SELECT '402',                  'Deluxe Suite',                       'available'
    UNION ALL SELECT '501',                  'Executive Suite',                    'available'
    UNION ALL SELECT '502',                  'Executive Suite',                    'available'
    UNION ALL SELECT '601',                  'Presidential Suite',                 'available'
    UNION ALL SELECT '602',                  'Presidential Suite',                 'available'
) AS `seed`
JOIN `room_types` AS `rt`
  ON `rt`.`name` = `seed`.`type_name`;


-- ===========================================================================
--  CREATING AN ADMINISTRATOR
-- ---------------------------------------------------------------------------
--  This script deliberately seeds NO user accounts, because shipping a known
--  or predictable admin password would be a security defect.
--
--  To create an administrator on your local machine, register normally
--  through the website once authentication is implemented, then promote that
--  account manually in phpMyAdmin:
--
--      UPDATE users SET role = 'admin' WHERE email = 'your.email@example.com';
--
--  Until authentication is implemented (a later phase), the users table will
--  simply remain empty. That is expected at this stage.
-- ===========================================================================


-- ===========================================================================
--  END OF SCHEMA
-- ===========================================================================
