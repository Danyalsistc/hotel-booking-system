# Testing

**Hotel Booking System — ICT304 Capstone 2**

> ## ⚠️ Status: NO TEST IN THIS DOCUMENT HAS BEEN EXECUTED
>
> Every case below is recorded as **Not yet executed**. Neither PHP nor MySQL
> was available in the environment where this code was written, so nothing has
> been run — not the database import, not a single page, not one query.
>
> The "Expected result" column states what *should* happen. It is a
> specification, **not** a record of observed behaviour. Do not report any of
> these as passing until you have run them yourself and filled in the
> "Actual result" column.

---

## How to run these tests

1. Install XAMPP and start **Apache** and **MySQL**.
2. Copy the project into `C:\xampp\htdocs\hotel-booking-system\`.
3. Import `database.sql` via phpMyAdmin (see README).
4. Work through the cases below in order and record what actually happens.

### Test accounts you will need

| Account | How to create | Used for |
|---|---|---|
| Customer A | Register at `register.php` | Most booking tests |
| Customer B | Register a second address | Cross-account isolation (TC-30) |
| Administrator | Register, then `UPDATE users SET role='admin' WHERE email='…'`, then log out and back in | Admin tests |

---

## 1. Environment and database

| ID | Test | Steps | Expected result | Actual | Status |
|---|---|---|---|---|---|
| TC-01 | Schema imports | Import `database.sql` in phpMyAdmin | Succeeds; `hotel_booking` created with 4 tables | | **Not yet executed** |
| TC-02 | Seed counts | Inspect tables | `room_types` = 6, `rooms` = 12, `users` = 0, `bookings` = 0 | | **Not yet executed** |
| TC-03 | Re-import is safe | Import `database.sql` a second time | No error; counts unchanged; no data lost | | **Not yet executed** |
| TC-04 | Maintenance room seeded | `SELECT * FROM rooms WHERE room_number='302'` | `status` = `maintenance` | | **Not yet executed** |
| TC-05 | Foreign key enforced | `INSERT INTO rooms (room_number, room_type_id, status) VALUES ('999', 9999, 'available');` | Rejected with a foreign-key error | | **Not yet executed** |
| TC-06 | Date CHECK enforced | Insert a booking with `check_out` before `check_in` | Rejected (check constraint or FK error first) | | **Not yet executed** |
| TC-07 | PHP syntax | `C:\xampp\php\php.exe -l` on every `.php` file | "No syntax errors detected" for all | | **Not yet executed** |
| TC-08 | DB down behaviour | Stop MySQL, open `login.php` | Generic "Service temporarily unavailable" page; **no** credentials, hostname or SQL text shown | | **Not yet executed** |

---

## 2. Registration

| ID | Test | Steps | Expected result | Actual | Status |
|---|---|---|---|---|---|
| TC-10 | Valid registration | Register with a new address | Account created; redirected to `login.php` with a success message; **not** logged in automatically | | **Not yet executed** |
| TC-11 | Password stored hashed | Inspect `users.password_hash` | A `$2y$…` hash, never the plaintext password | | **Not yet executed** |
| TC-12 | Role defaults to customer | Inspect `users.role` | `customer` | | **Not yet executed** |
| TC-13 | Duplicate email blocked | Register the same address again | Rejected with "An account with this email address already exists." | | **Not yet executed** |
| TC-14 | Case-insensitive duplicate | Register `USER@Example.com` after `user@example.com` | Rejected as a duplicate | | **Not yet executed** |
| TC-15 | Short password | Enter a 5-character password | Rejected: at least 8 characters | | **Not yet executed** |
| TC-16 | Mismatched confirmation | Enter two different passwords | Rejected: "The two passwords do not match." | | **Not yet executed** |
| TC-17 | Invalid email | Enter `notanemail` | Rejected | | **Not yet executed** |
| TC-18 | Values preserved on error | Trigger any error | Name and email are re-filled; **both password fields are empty** | | **Not yet executed** |
| TC-19 | Role cannot be injected | Post `role=admin` with the form (e.g. via browser dev tools) | Account is still created as `customer` | | **Not yet executed** |
| TC-20 | Accented / hyphenated name | Register as `Anne-Marie O'Brien` | Accepted | | **Not yet executed** |

---

## 3. Login, sessions and logout

| ID | Test | Steps | Expected result | Actual | Status |
|---|---|---|---|---|---|
| TC-21 | Valid customer login | Log in as Customer A | Redirected to `customer-dashboard.php` | | **Not yet executed** |
| TC-22 | Valid admin login | Log in as the administrator | Redirected to `admin-dashboard.php` | | **Not yet executed** |
| TC-23 | Wrong password | Enter a bad password | "The email address or password you entered is incorrect." | | **Not yet executed** |
| TC-24 | Unknown email | Enter an unregistered address | **The identical message as TC-23** — no user enumeration | | **Not yet executed** |
| TC-25 | SQL injection in login | Email `' OR '1'='1' -- ` | Rejected as a normal failed login; no bypass, no SQL error | | **Not yet executed** |
| TC-26 | Session ID rotates | Note `PHPSESSID` before and after login | The value changes after a successful login | | **Not yet executed** |
| TC-27 | Cookie flags | Inspect the session cookie | `HttpOnly` set, `SameSite=Lax`; `Secure` absent on plain http localhost | | **Not yet executed** |
| TC-28 | Logout by GET refused | Open `logout.php` in the address bar | Confirmation page, HTTP 405; **still logged in** | | **Not yet executed** |
| TC-29 | Logout by POST | Press "Log out" | Session ends; redirected to `login.php` with "You have been logged out." | | **Not yet executed** |
| TC-30 | Bad CSRF token | Alter the hidden `csrf_token` value and submit | HTTP 400 "Request could not be verified"; nothing changes | | **Not yet executed** |

---

## 4. Access control

| ID | Test | Steps | Expected result | Actual | Status |
|---|---|---|---|---|---|
| TC-31 | Guest → customer dashboard | Log out, open `customer-dashboard.php` | Redirected to `login.php` | | **Not yet executed** |
| TC-32 | Guest → admin dashboard | Log out, open `admin-dashboard.php` | Redirected to `login.php` | | **Not yet executed** |
| TC-33 | Customer → admin dashboard | As Customer A, open `admin-dashboard.php` | Redirected to own dashboard: "You do not have permission to view that page." | | **Not yet executed** |
| TC-34 | Guest → booking page | Log out, open `booknow.php` | Redirected to `login.php` | | **Not yet executed** |
| TC-35 | Admin → booking page | As administrator, open `booknow.php` | Redirected to `admin-dashboard.php` | | **Not yet executed** |
| TC-36 | No public admin link | View the home page source | No link to any administrator dashboard | | **Not yet executed** |
| TC-37 | Legacy admin page | Open `admin-dashboard.html` | Notice page only — no totals, no bookings, no admin data | | **Not yet executed** |
| TC-38 | Customer isolation | As Customer B, view the dashboard after Customer A has booked | Customer A's bookings are **not** visible | | **Not yet executed** |

---

## 5. Booking

| ID | Test | Steps | Expected result | Actual | Status |
|---|---|---|---|---|---|
| TC-40 | Room preselection | Open a room page, press "Book Now" | `booknow.php` opens with that exact room type selected | | **Not yet executed** |
| TC-41 | All six pages preselect | Repeat TC-40 on all six room pages | Each selects its own type, matching the page title | | **Not yet executed** |
| TC-42 | Check Availability button | Press it on a room page | Goes to `booknow.php` with the type selected — **no alert box** | | **Not yet executed** |
| TC-43 | Valid booking | Book 2 nights in a Deluxe Suite for 2 guests | Booking created; redirected to the dashboard with a reference | | **Not yet executed** |
| TC-44 | Stored in MySQL | `SELECT * FROM bookings` | The booking row exists with correct dates, nights, rate and total | | **Not yet executed** |
| TC-45 | Nothing in localStorage | Check DevTools → Application → Local Storage | **Empty** — no booking or personal data | | **Not yet executed** |
| TC-46 | Server-side price | Edit the option's `data-price` in DevTools, then book | Stored `nightly_rate`/`total_price` match the **database** price, not the edited one | | **Not yet executed** |
| TC-47 | Server-side capacity | Remove the `max` attribute and submit 9 guests for a 2-guest room | Rejected: maximum guests message | | **Not yet executed** |
| TC-48 | Check-out required | Submit with check-out empty | Rejected: valid check-out date required | | **Not yet executed** |
| TC-49 | Check-out before check-in | Check-in 12th, check-out 10th | Rejected: "must be after the check-in date" | | **Not yet executed** |
| TC-50 | Same-day check-out | Check-in and check-out on the same date | Rejected (zero nights) | | **Not yet executed** |
| TC-51 | Past check-in | Choose yesterday | Rejected: "cannot be in the past" | | **Not yet executed** |
| TC-52 | Impossible date | Post `check_in=2026-02-30` | Rejected as an invalid date — **not** rolled forward to 1 March | | **Not yet executed** |
| TC-53 | Over-long stay | Book 31 nights | Rejected: maximum 30 nights | | **Not yet executed** |
| TC-54 | Too far ahead | Check-in more than 365 days away | Rejected | | **Not yet executed** |
| TC-55 | Reference format | Inspect `booking_reference` | `HBS-YYYYMMDD-XXXXXXXX`, unpredictable, unique | | **Not yet executed** |
| TC-56 | Initial status | Inspect a new booking | `pending` | | **Not yet executed** |
| TC-57 | Refresh after booking | Press F5 on the dashboard after booking | **No duplicate booking** is created (redirect-after-POST) | | **Not yet executed** |
| TC-58 | Invalid room type | Post `room_type_id=9999` | Rejected: "That room type is not available." | | **Not yet executed** |
| TC-59 | No card fields | Inspect the booking form and the `bookings` table | No card number, expiry or CVV anywhere | | **Not yet executed** |

---

## 6. Availability

| ID | Test | Steps | Expected result | Actual | Status |
|---|---|---|---|---|---|
| TC-60 | Overlap blocks | Book both Deluxe rooms (401, 402) for 10–12 Aug, then try a third | Refused: no Deluxe Suite available for those dates | | **Not yet executed** |
| TC-61 | Second room allocated | Book two guests into the same type and dates | Two **different** `room_id` values are used | | **Not yet executed** |
| TC-62 | Same-day changeover | Existing booking 10–12 Aug; book 12–14 Aug | **Accepted** — the 12th is not double-counted | | **Not yet executed** |
| TC-63 | Non-overlapping dates | Existing 10–12 Aug; book 20–22 Aug | Accepted | | **Not yet executed** |
| TC-64 | Maintenance excluded | Book both Superior Suites (301, 302 — 302 is in maintenance) | Only **one** Superior Suite can ever be booked | | **Not yet executed** |
| TC-65 | Cancelled frees the room | Fully book a type, cancel one booking as admin, rebook | The rebooking succeeds | | **Not yet executed** |
| TC-66 | JSON endpoint | `check_availability.php?room_type_id=4&check_in=…&check_out=…` while logged in | JSON with `ok`, `available`, `rooms_available`; `Content-Type: application/json` | | **Not yet executed** |
| TC-67 | Endpoint needs login | Call it logged out | HTTP 401 JSON error | | **Not yet executed** |
| TC-68 | Endpoint validates input | Pass `check_in=rubbish` | HTTP 400 JSON error | | **Not yet executed** |
| TC-69 | Endpoint leaks nothing | Inspect any response | No room numbers, no row IDs, no SQL or database error text | | **Not yet executed** |
| TC-70 | Endpoint is read-only | Call it repeatedly, then check `bookings` | Row count unchanged | | **Not yet executed** |
| TC-71 | **Concurrent booking** | Last room of a type free. Submit two bookings for the same dates at the same instant (two browsers, or two `curl` requests fired together) | Exactly **one** succeeds; the other is refused. **No double booking.** *This is the most important test in this document.* | | **Not yet executed** |

---

## 7. Customer dashboard

| ID | Test | Steps | Expected result | Actual | Status |
|---|---|---|---|---|---|
| TC-80 | Empty state | New account with no bookings | "You have no bookings yet" plus a link to book | | **Not yet executed** |
| TC-81 | Booking listed | After booking | Reference, room type, dates, nights, guests, rate, total, status and booking date all shown | | **Not yet executed** |
| TC-82 | AUD formatting | Inspect the amounts | Shown as `AUD 250.00` with two decimals | | **Not yet executed** |
| TC-83 | Total is correct | 2 nights at 250 | Total shows `AUD 500.00` | | **Not yet executed** |
| TC-84 | Only own bookings | Compare Customer A and Customer B | Each sees only their own | | **Not yet executed** |
| TC-85 | Mobile layout | Narrow the window below 600px | Table stacks into readable cards; page does not scroll sideways | | **Not yet executed** |

---

## 8. Administrator dashboard

| ID | Test | Steps | Expected result | Actual | Status |
|---|---|---|---|---|---|
| TC-90 | Totals are live | Note the totals, add a booking, reload | Pending count increases by 1 | | **Not yet executed** |
| TC-91 | No hard-coded totals | Compare against the database | Figures match `COUNT(*)`; the old 12/150/85/210 never appear | | **Not yet executed** |
| TC-92 | Active rooms | With the seed data | Shows **11** (12 rooms minus room 302 in maintenance) | | **Not yet executed** |
| TC-93 | Bookings listed | After customers book | Reference, customer name, room type, room number, dates, guests, total, status, created date | | **Not yet executed** |
| TC-94 | No email shown | Inspect the table | Customer email addresses are not displayed | | **Not yet executed** |
| TC-95 | Empty state | Before any booking exists | "There are no bookings yet" | | **Not yet executed** |
| TC-96 | Confirm | Press Confirm on a pending booking | Status becomes `confirmed`; success message | | **Not yet executed** |
| TC-97 | Cancel pending | Press Cancel on a pending booking | Status becomes `cancelled` | | **Not yet executed** |
| TC-98 | Cancel confirmed | Press Cancel on a confirmed booking | Status becomes `cancelled` | | **Not yet executed** |
| TC-99 | No action when final | View a cancelled booking | "No actions" — no buttons offered | | **Not yet executed** |
| TC-100 | Invalid transition | Post `action=confirm` for a cancelled booking | Rejected; status unchanged | | **Not yet executed** |
| TC-101 | Arbitrary status refused | Post `action=completed` or `status=completed` | Rejected: "That action is not recognised."; status unchanged | | **Not yet executed** |
| TC-102 | Action needs POST | Open `admin-booking-action.php` in the address bar | HTTP 405; redirected; nothing changed | | **Not yet executed** |
| TC-103 | Action needs admin | As Customer A, post a confirm action | Refused by `require_admin()` | | **Not yet executed** |
| TC-104 | Action needs CSRF | Post with a bad token | HTTP 400; nothing changed | | **Not yet executed** |
| TC-105 | Repeat submission | Confirm the same booking twice | Second attempt reports it could not be confirmed; no corruption | | **Not yet executed** |

---

## 9. Output escaping

| ID | Test | Steps | Expected result | Actual | Status |
|---|---|---|---|---|---|
| TC-110 | XSS via name | Register as `<script>alert(1)</script>` (if the name rule allows) then view both dashboards | Rendered as literal text; **no alert box** | | **Not yet executed** |
| TC-111 | XSS via room type | Set a room type name to `<b>x</b>` in phpMyAdmin, open the booking page | Rendered as literal text, not bold | | **Not yet executed** |

---

## 10. Accessibility and validation (manual)

| ID | Test | Steps | Expected result | Actual | Status |
|---|---|---|---|---|---|
| TC-120 | HTML validation | Run each page through <https://validator.w3.org/> | No errors on the new PHP pages | | **Not yet executed** |
| TC-121 | CSS validation | Run the stylesheets through the W3C CSS validator | No errors | | **Not yet executed** |
| TC-122 | Keyboard only | Tab through login, register and the booking form | Every control reachable, focus always visible | | **Not yet executed** |
| TC-123 | Labels | Inspect the forms | Every input has a visible `<label>` | | **Not yet executed** |
| TC-124 | Errors announced | Submit an invalid form | Errors carry `role="alert"` and are linked by `aria-describedby` | | **Not yet executed** |
| TC-125 | No JavaScript | Disable JS and book a room | Booking still works — the form posts and the server validates | | **Not yet executed** |

---

## Known gaps in this test plan

- No automated tests exist; every case above is manual.
- No load or performance testing.
- TC-71 (concurrency) needs two genuinely simultaneous requests; a browser
  refresh is not a valid substitute.
- Browser compatibility has not been enumerated beyond a single browser.
- Phases 4 and later (visual defects, grammar, the home-page layout bug) are
  not covered here.
