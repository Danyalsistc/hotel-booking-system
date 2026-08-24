-- ===========================================================================
--  Hotel Booking System - Migration: customer cancellation requests
--  ICT304 Capstone 2
--  Applied: 2026-08-20
-- ---------------------------------------------------------------------------
--  Adds the controlled cancellation-request workflow to an EXISTING database.
--
--  This file is deliberately additive. It does NOT drop or recreate the
--  bookings table, does not touch row IDs, does not rewrite any existing
--  status, and does not alter any other constraint, index or foreign key.
--  Run it against a database that already contains live bookings.
--
--  For a brand-new installation you do not need this file at all -
--  database.sql already contains the final schema.
--
--  Usage:
--      mysql -u root hotel_booking < migrations/2026-08-20-add-cancellation-request.sql
--
--  Re-runnable: MODIFY is idempotent, and ADD COLUMN uses IF NOT EXISTS
--  (a MariaDB extension - this project runs on MariaDB 10.4).
-- ===========================================================================

-- ---------------------------------------------------------------------------
--  1. Add the fifth booking status.
--
--  'cancellation_requested' is APPENDED AT THE END of the existing list. That
--  matters: MySQL/MariaDB stores an ENUM as the ordinal position of its value,
--  so keeping the first four members in their original order means every
--  existing row keeps its original ordinal and therefore its original status.
--  Inserting the new member in the middle would shift 'cancelled' and
--  'completed' and force a full table rebuild.
--
--  Nothing in the application ORDERs BY status, so the declaration order has
--  no visible effect; display order is controlled in booking-status.php.
-- ---------------------------------------------------------------------------
ALTER TABLE `bookings`
    MODIFY `status`
        ENUM('pending','confirmed','cancelled','completed','cancellation_requested')
        NOT NULL DEFAULT 'pending';

-- ---------------------------------------------------------------------------
--  2. Remember what the booking was before the customer asked to cancel.
--
--  A single status column cannot answer "what should this booking go back to
--  if an administrator REJECTS the request?" - a request may have started from
--  'pending' or from 'confirmed', and sending every rejected request back to
--  'confirmed' would silently approve bookings that staff had never approved.
--
--  The column is deliberately typed ENUM('pending','confirmed') rather than
--  mirroring the full status list. The database itself then makes it
--  impossible for a rejection to resurrect a 'cancelled' or 'completed'
--  booking, even if the application code were wrong.
--
--  NULL means "no cancellation request is outstanding", which is true of every
--  existing row, so no backfill is needed.
-- ---------------------------------------------------------------------------
ALTER TABLE `bookings`
    ADD COLUMN IF NOT EXISTS `previous_status`
        ENUM('pending','confirmed') NULL DEFAULT NULL
        AFTER `status`;
