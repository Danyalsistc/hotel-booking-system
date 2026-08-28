-- ===========================================================================
--  Migration: customer cancellation requests            Applied: 2026-08-20
-- ---------------------------------------------------------------------------
--  Additive only. Does not drop, recreate or rewrite the bookings table, and
--  no existing row is changed. Re-runnable: MODIFY is idempotent and ADD
--  COLUMN uses IF NOT EXISTS (a MariaDB extension - this project uses 10.4).
--
--  Not needed for a new installation; database.sql already has this schema.
--  Usage: mysql -u root hotel_booking < migrations/2026-08-20-...sql
-- ===========================================================================

-- 'cancellation_requested' is APPENDED at the END of the list. An ENUM is
-- stored as the ordinal position of its value, so keeping the first four
-- members in their original order means every existing row keeps its status.
-- Inserting the new value in the middle would shift 'cancelled' and
-- 'completed' and force a full table rebuild.
ALTER TABLE `bookings`
    MODIFY `status`
        ENUM('pending','confirmed','cancelled','completed','cancellation_requested')
        NOT NULL DEFAULT 'pending';

-- Remembers what the booking was before the customer asked to cancel, so a
-- REJECTED request can be put back exactly as it was. A request may begin from
-- 'pending' or 'confirmed', and sending every rejection back to 'confirmed'
-- would silently approve bookings staff never approved.
--
-- Typed ENUM('pending','confirmed') rather than mirroring the full status
-- list, so the database itself makes it impossible for a rejection to
-- resurrect a cancelled or completed booking. NULL means no request is
-- outstanding, which is true of every existing row - no backfill needed.
ALTER TABLE `bookings`
    ADD COLUMN IF NOT EXISTS `previous_status`
        ENUM('pending','confirmed') NULL DEFAULT NULL
        AFTER `status`;
