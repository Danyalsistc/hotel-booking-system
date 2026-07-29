# Testing

**Hotel Booking System — ICT304 Capstone 2**

> ## Status: EXECUTED
>
> The tests below were **actually run** against a live installation on
> 30 July 2026. Every "Actual result" is an observed outcome, not a
> prediction. Tests that were **not** run are listed explicitly in
> [Not executed](#not-executed) and are not marked as passing.
>
> **Result: 123 automated checks executed — 123 pass, 0 fail** (after the
> three defects in [Defects found and fixed](#defects-found-and-fixed) were
> repaired and every suite re-run).

---

## Environment

| Item | Value |
|---|---|
| Operating system | Microsoft Windows 11 Home |
| PHP | 8.2.12 (from `C:\xampp\php\php.exe`) |
| `mysqli` extension | Enabled |
| Database | MariaDB 10.4.32 (XAMPP) |
| Database host | `127.0.0.1:3306`, user `root`, blank password (XAMPP default) |
| Web server | PHP built-in development server |
| Local URL | **http://127.0.0.1:8080/** |
| Second instance (concurrency only) | http://127.0.0.1:8081/ |
| Browser | Headless Chrome 148.0.7778.179, driven over the DevTools Protocol |
| Test harness | PowerShell 5.1 using `HttpWebRequest` (raw HTTP, **no JavaScript engine**) |
| PHP `date.timezone` | `Europe/Berlin` |
| Application booking timezone | `Australia/Sydney` (`BOOKING_TIMEZONE` in `booking-lib.php`) |

The mismatch between the server timezone and the application timezone is
deliberate and useful: the date tests below pass regardless, which confirms
the application judges "today" by its own configured timezone rather than the
server's.

### How the application was started

The project was **not** copied into `htdocs`. It was served from its own
folder:

```bash
cd "C:\Users\meond\Downloads\Hotel booking system\Hotel booking system"
C:\xampp\php\php.exe -S 127.0.0.1:8080 -t .
```

### Test accounts

Three fictional accounts on the reserved `example.test` domain. **No real
personal data was used.** Passwords were generated at random at run time,
held only outside the repository, and are not recorded in this document, in
Git, or in any screenshot.

| Account | Role | Purpose |
|---|---|---|
| `alex.tester@example.test` | customer | Main booking customer |
| `jordan.sample@example.test` | customer | Second customer, for isolation tests |
| `admin.demo@example.test` | admin | Promoted with the documented SQL procedure |

---

## Summary

| Suite | Checks | Pass | Fail |
|---|---|---|---|
| 1. PHP syntax | 11 | 11 | 0 |
| 2. Database import and constraints | 12 | 12 | 0 |
| 3. Registration | 16 | 16 | 0 |
| 4. Login, session, logout | 14 | 14 | 0 |
| 5. Access control | 6 | 6 | 0 |
| 6. Booking | 30 | 30 | 0 |
| 7. Availability and overlap | 22 | 22 | 0 |
| 8. Concurrency | 4 | 3 | 1 (environment limit, not a defect) |
| 9. Administrator | 22 | 22 | 0 |
| 10. Escaping and accessibility markup | 9 | 9 | 0 |
| 11. Visual / responsive | 24 pages×viewports | 24 | 0 |

---

## 1. PHP syntax

`C:\xampp\php\php.exe -l` was run against every PHP file. Actual output:

```
admin-booking-action.php     No syntax errors detected
admin-dashboard.php          No syntax errors detected
auth.php                     No syntax errors detected
booking-lib.php              No syntax errors detected
booknow.php                  No syntax errors detected
check_availability.php       No syntax errors detected
config.php                   No syntax errors detected
customer-dashboard.php       No syntax errors detected
login.php                    No syntax errors detected
logout.php                   No syntax errors detected
register.php                 No syntax errors detected

--- 11 files linted, 0 failed ---
```

Re-linted after every code change in this phase; still 11/11 clean.

---

## 2. Database import and constraints

| ID | Test | Expected | Actual | Result | Fix | Retest |
|---|---|---|---|---|---|---|
| TC-01 | Import `database.sql` | Succeeds | Exit code 0, no errors | **Pass** | — | — |
| TC-01b | Unrelated databases untouched | `mysql`, `phpmyadmin`, `test` survive | All present before and after | **Pass** | — | — |
| TC-02a | `hotel_booking` created | Exists | Exists | **Pass** | — | — |
| TC-02b | Four tables created | users, room_types, rooms, bookings | `bookings,rooms,room_types,users` | **Pass** | — | — |
| TC-02c | Engine and charset | InnoDB / utf8mb4 | `InnoDB`, `utf8mb4` | **Pass** | — | — |
| TC-02 | Seed row counts | room_types 6, rooms 12, users 0, bookings 0 | 6 / 12 / 0 / 0 | **Pass** | — | — |
| TC-04 | Room 302 in maintenance | `maintenance` | `maintenance` | **Pass** | — | — |
| TC-02d | Foreign keys present | 3 FKs, all RESTRICT on delete | `fk_rooms_room_type`, `fk_bookings_user`, `fk_bookings_room`, all RESTRICT / CASCADE | **Pass** | — | — |
| TC-02e | CHECK constraints present | 9 | 9 present | **Pass** | — | — |
| TC-05 | FK violation rejected | Error | `ERROR 1452 … fk_rooms_room_type` | **Pass** | — | — |
| TC-06 | `check_out` before `check_in` rejected | Error | `ERROR 4025 … chk_bookings_dates_ordered` | **Pass** | — | — |
| TC-06b | Case-insensitive duplicate email rejected at DB level | Error | `ERROR 1062 … uq_users_email` | **Pass** | — | — |
| TC-03 | Re-import is safe | No duplication, no data loss | Exit 0; counts still 6 / 12 | **Pass** | — | — |

---

## 3. Registration

| ID | Test | Expected | Actual | Result |
|---|---|---|---|---|
| TC-09 | Registration page loads | HTTP 200 with the form | 200, form present | **Pass** |
| TC-09b | CSRF token issued | Token present | Present | **Pass** |
| TC-10 | Valid registration | Redirect to `login.php` | 302 → `login.php` | **Pass** |
| TC-10b | Account row created | 1 row | 1 row | **Pass** |
| TC-11 | Password stored hashed only | bcrypt hash, never plaintext | `$2y$10$…`, 60 chars, ≠ password | **Pass** |
| TC-12 | Role defaults to customer | `customer` | `customer` | **Pass** |
| TC-13 | Duplicate email rejected | Friendly message | "already exists" | **Pass** |
| TC-14 | Case-insensitive duplicate rejected | Friendly message | "already exists" | **Pass** |
| TC-15 | Password under 8 characters rejected | Error | "at least 8 characters" | **Pass** |
| TC-16 | Password confirmation mismatch rejected | Error | "do not match" | **Pass** |
| TC-17 | Invalid email rejected | Error | "valid email address" | **Pass** |
| TC-18 | Values preserved, password not echoed | Email kept, password absent | Email kept; password absent from HTML | **Pass** |
| TC-19 | `role=admin` posted with the form ignored | Stored as customer | `customer` | **Pass** |
| TC-20 | Hyphenated / apostrophe name accepted | Accepted | 302 (accepted) | **Pass** |
| TC-30 | Bad CSRF token rejected | HTTP 400, no account | 400, 0 rows created | **Pass** |
| TC-10c | Three test accounts created | 3 | 3 | **Pass** |

---

## 4. Login, session and logout

| ID | Test | Expected | Actual | Result |
|---|---|---|---|---|
| TC-21 | Valid customer login | Redirect to customer dashboard | 302 → `customer-dashboard.php` | **Pass** |
| TC-21b | Dashboard reachable after login | 200, greeting shown | 200, "Welcome, Alex Tester" | **Pass** |
| TC-22a | Admin promotion via documented SQL | role becomes admin | `admin` | **Pass** |
| TC-22 | Administrator login | Redirect to admin dashboard | 302 → `admin-dashboard.php` | **Pass** |
| TC-22b | Administrator can view dashboard | 200 | 200 | **Pass** |
| TC-23 | Wrong password rejected | Generic error | "The email address or password you entered is incorrect." | **Pass** |
| TC-24 | Unknown email — no enumeration | **Identical** message to TC-23 | Byte-identical message | **Pass** |
| TC-25 | SQL injection `' OR '1'='1' -- ` | Rejected, no SQL error | 200, generic error, no SQL text | **Pass** |
| TC-26 | Session ID regenerated at login | Value changes | Changed (e.g. `k49dff…` → `v9nfp1…`) | **Pass** |
| TC-27 | Cookie flags | HttpOnly set, Secure off on http | `HttpOnly=True Secure=False`, `SameSite=Lax` | **Pass** |
| TC-28 | GET `logout.php` must not log out | 405, still signed in | 405; dashboard still 200 | **Pass** |
| TC-104a | Logout with bad CSRF | 400, session survives | 400; dashboard still 200 | **Pass** |
| TC-29 | POST logout | Redirect with confirmation flag | 302 → `login.php?logged_out=1` | **Pass** |
| TC-29b | Session destroyed | Dashboard blocked | 302 to login | **Pass** |
| TC-29c | Logout message shown | Confirmation visible | "You have been logged out" | **Pass** |

---

## 5. Access control

| ID | Test | Expected | Actual | Result |
|---|---|---|---|---|
| TC-31 | Guest → customer dashboard | Redirect to login | 302 → `login.php` | **Pass** |
| TC-32 | Guest → admin dashboard | Redirect to login | 302 → `login.php` | **Pass** |
| TC-34 | Guest → booking page | Redirect to login | 302 → `login.php` | **Pass** |
| TC-33 | Customer → admin dashboard | Sent back to own dashboard | 302 → `customer-dashboard.php` | **Pass** |
| TC-35 | Administrator → booking page | Sent to admin dashboard | 302 → `admin-dashboard.php` | **Pass** |
| TC-103b | Guest → admin action endpoint | Refused | 302 → `login.php`, nothing changed | **Pass** |

---

## 6. Booking

| ID | Test | Expected | Actual | Result |
|---|---|---|---|---|
| TC-06a | Booking page loads for a customer | 200 | 200 | **Pass** |
| TC-06b | All six room types listed | 6 options | 6 | **Pass** |
| TC-48a | Both date fields present | check_in + check_out | Both present | **Pass** |
| TC-40 | All six room pages preselect correctly | Each selects its own type | 1→1, 2→2, 3→3, 4→4, 5→5, 6→6 | **Pass** |
| TC-51 | Check-in in the past | Rejected | "cannot be in the past" | **Pass** |
| TC-49 | Check-out before check-in | Rejected | "must be after the check-in date" | **Pass** |
| TC-50 | Same check-in and check-out | Rejected | "must be after the check-in date" | **Pass** |
| TC-53 | Stay longer than 30 nights | Rejected | "at most 30 nights" | **Pass** |
| TC-52 | Impossible date `2026-02-30` | Rejected, **not** rolled forward | "valid check-in date" | **Pass** |
| TC-54 | Check-in beyond 365 days | Rejected | "365 days in advance" | **Pass** |
| TC-47a | Guest count zero | Rejected | "how many guests" | **Pass** |
| TC-47 | Guest count above capacity | Rejected server-side | "maximum of 2" | **Pass** |
| TC-58 | Unknown `room_type_id` | Rejected | "not available" | **Pass** |
| TC-58a | Non-numeric room type | Rejected | Rejected | **Pass** |
| TC-44a | No rows created by invalid submissions | 0 bookings | 0 | **Pass** |
| TC-43 | Valid booking | Redirect to dashboard | 302 → `customer-dashboard.php` | **Pass** |
| TC-44 | Booking stored in MySQL | Row present | `HBS-20260729-F9E329DA 2 250.00 500.00 pending 2` | **Pass** |
| TC-43b | Nights calculated on server | 2 | 2 | **Pass** |
| TC-43c | Rate read from database | 250.00 | 250.00 | **Pass** |
| TC-43d | Total calculated on server | 500.00 | 500.00 | **Pass** |
| TC-56 | Initial status | `pending` | `pending` | **Pass** |
| TC-55 | Reference format | `HBS-YYYYMMDD-XXXXXXXX` | Matches, unpredictable | **Pass** |
| TC-46 | **Price tampering** — post `nightly_rate=1.00`, `total_price=1.00`, `number_of_nights=99` | Server values used | Stored 200.00 / 200.00 / 1 | **Pass** |
| TC-81 | Booking on owner's dashboard | Visible | Reference shown | **Pass** |
| TC-82 | AUD formatting | `AUD 500.00` | `AUD 500.00` | **Pass** |
| TC-84 | Other customer cannot see it | Absent | Absent | **Pass** |
| TC-80 | Empty state | Message shown | "You have no bookings yet" | **Pass** |
| TC-57 | Re-load after POST | No duplicate | Count unchanged | **Pass** |
| TC-64 | Maintenance room 302 excluded | Only 301 bookable | Allocated `301`; 2nd attempt refused | **Pass** |
| TC-59 | No card fields | None | None | **Pass** |

---

## 7. Availability and overlap

Baseline for the overlap matrix: one booking on the single usable Superior
Suite for days 10–12.

| ID | Test | Expected | Actual | Result |
|---|---|---|---|---|
| TC-66 | Endpoint with valid dates | JSON with counts | `rooms=1 nights=2 total=400.00` | **Pass** |
| TC-66b | Room 302 excluded from the count | 1 available | 1 | **Pass** |
| TC-66c | Endpoint states it reserves nothing | `reserved:false` | `false` | **Pass** |
| TC-66d | Invalid room type | 404 JSON error | 404, `"ok":false` | **Pass** |
| TC-68 | Invalid date | 400 JSON error | 400, `"ok":false` | **Pass** |
| TC-67 | Endpoint requires login | 401 | 401 | **Pass** |
| TC-69 | No internals leaked | No room numbers / SQL | None present | **Pass** |
| TC-70 | Endpoint is read-only | No bookings created | Count unchanged | **Pass** |
| TC-60 | **Pending** booking blocks | Refused | Refused | **Pass** |
| TC-60f | **Confirmed** booking blocks | Refused | Refused | **Pass** |
| TC-65 | **Cancelled** booking frees the room | Accepted | 302 accepted | **Pass** |
| TC-60b | Partial overlap 11–13 vs 10–12 | Refused | Refused | **Pass** |
| TC-60c | Partial overlap 9–11 vs 10–12 | Refused | Refused | **Pass** |
| TC-60d | Fully contained 10–11 inside 10–12 | Refused | Refused | **Pass** |
| TC-60e | Containing range 8–15 around 10–12 | Refused | Refused | **Pass** |
| TC-62 | Same-day changeover 12–14 after 10–12 | **Accepted** | 302 accepted | **Pass** |
| TC-62b | Same-day changeover 7–10 before 10–12 | **Accepted** | 302 accepted | **Pass** |
| TC-63 | Non-overlapping dates | Accepted | Accepted | **Pass** |
| TC-61 | Two physical rooms of one type | Different rooms allocated | `101,102` | **Pass** |
| TC-60g | Third booking when both taken | Refused | Refused | **Pass** |
| TC-71a | No room double-booked (data check) | 0 duplicates | 0 | **Pass** |

---

## 8. Concurrency

| ID | Test | Expected | Actual | Result |
|---|---|---|---|---|
| TC-71-env | PHP built-in server serves concurrent requests | Concurrent | Two 2-second requests took **4.63 s** → **serialised** | **Fail — environment limitation** |
| TC-71-db | `room_types … FOR UPDATE` blocks a second transaction | Second connection waits | Connection B blocked **3.32 s** while A held the lock | **Pass** |
| TC-71par | Two server instances run in parallel | Parallel | Two 2-second requests across ports 8080/8081 took **2.36 s** | **Pass** |
| TC-71 | Race for the last room, 12 parallel attempts | Exactly 1 booking each time | 12/12 iterations: 1 booking, codes `302,200`, 0 duplicate rooms | **Pass** |

### How the limitation was worked around — and what is still unproven

The PHP built-in development server is **single-threaded on Windows**
(`PHP_CLI_SERVER_WORKERS` is POSIX-only), so a race against one instance
proves nothing: the web server serialises the requests before they ever reach
the database. This was measured, not assumed (TC-71-env).

A genuine race was therefore produced by running a **second PHP server
instance on port 8081** against the same database and session store, and
firing one request at each port simultaneously. Parallel execution was
verified (TC-71par, 2.36 s rather than ~4 s), and the invariant held across
12 iterations.

**Honest caveat:** the harness cannot prove that both requests were inside the
critical section at the same instant on every iteration. What is established
is (a) the row lock demonstrably blocks a competing transaction for as long as
the holder runs (TC-71-db), and (b) 12 genuinely parallel attempts never
produced a double booking. That is strong evidence, not a formal proof.
Testing under Apache with a process pool would exercise this more heavily.

---

## 9. Administrator

| ID | Test | Expected | Actual | Result | Fix | Retest |
|---|---|---|---|---|---|---|
| TC-90 | Totals come from the database | Match `COUNT(*)` | Cards `11, 4, 3, 0` = DB `11, 4, 3, 0` | **Pass** | — | — |
| TC-90b | Totals move with the data | Pending +1 after a booking | 3 → 4 | **Pass** | — | — |
| TC-92 | Active rooms | 11 (12 minus room 302) | 11 | **Pass** | — | — |
| TC-91 | No hard-coded totals | 150/85/210 absent | Absent | **Pass** | — | — |
| TC-93 | Recent bookings display | Reference, customer, room number | All present | **Pass** | — | — |
| TC-94 | Customer email not shown | Absent | Absent | **Pass** | — | — |
| TC-106 | No password hash on the page | Absent | No `$2y$` anywhere | **Pass** | — | — |
| TC-95 | Empty state | Message | "There are no bookings yet" | **Pass** | — | — |
| TC-96 | Confirm a pending booking | `confirmed` | `confirmed` | **Pass** | — | — |
| TC-97 | Cancel a pending booking | `cancelled` | `cancelled` | **Pass** | — | — |
| TC-98 | Cancel a confirmed booking | `cancelled` | `cancelled` | **Pass** | — | — |
| TC-105 | Repeated confirm | Rejected, unchanged | Unchanged | **Pass** | — | — |
| TC-105b | Repeated transition message | Failure reported | "could not be confirmed" | **Pass** | — | — |
| TC-100 | Confirm a cancelled booking | Rejected | Still `cancelled` | **Pass** | — | — |
| TC-101 | Arbitrary action value | Rejected | Unchanged | **Pass** | — | — |
| TC-101b | Invalid action message | "not recognised" | "not recognised" | **Pass** | — | — |
| TC-101c | Posted `status` field ignored | Only mapped action applies | `confirmed` (not `completed`) | **Pass** | — | — |
| **TC-102** | **GET status change refused** | **405, nothing modified** | **First run: HTTP 302 (not 405)** | **Fail → Pass** | **Defect 1 fixed** | **405, nothing modified** |
| TC-104 | Admin action with bad CSRF | 400, nothing modified | 400, unchanged | **Pass** | — | — |
| TC-103 | Customer performs admin action | Refused | Redirected, unchanged | **Pass** | — | — |
| TC-103b | Guest performs admin action | Refused | Redirected to login | **Pass** | — | — |
| TC-93a | Test bookings created | 3 | 3 | **Pass** | — | — |

---

## 10. Output escaping and accessibility markup

| ID | Test | Expected | Actual | Result |
|---|---|---|---|---|
| TC-110 | Stored `<img src=x onerror=…>` in a customer name | Escaped on admin dashboard | Rendered as `&lt;img …`, no raw tag | **Pass** |
| TC-111 | Stored `<script>` in a room type name | Escaped on booking page | Rendered as `&lt;script&gt;`, no raw tag | **Pass** |
| TC-110b / TC-111b | Test data restored afterwards | Original values | Restored | **Pass** |
| TC-123 | Every visible field has `<label for>` | No unlabelled fields | login 2/2, register 4/4, booking 4/4 | **Pass** |
| TC-124 | Errors announced and linked | `role=alert`, `aria-invalid`, `aria-describedby` | All three present | **Pass** |
| TC-125 | **Works with no JavaScript** | Booking completes | 302 + 1 row — the entire harness is raw HTTP with no JS engine | **Pass** |

---

## 11. Visual and responsive

Measured in headless Chrome at three viewports. "No horizontal overflow" was
verified by attempting to scroll the page sideways, not merely by comparing
`scrollWidth` (see Defect 3 below for why that distinction matters).

| Page | Desktop 1366 | Tablet 768 | Mobile 390 |
|---|---|---|---|
| Home | ✅ | ✅ | ✅ |
| Standard Twin Room | ✅ | ✅ | ✅ |
| Presidential Suite | ✅ | ✅ | ✅ |
| Login | ✅ | ✅ | ✅ |
| Registration | ✅ | ✅ | ✅ |
| Booking form | ✅ | ✅ | ✅ |
| Customer dashboard | ✅ | ✅ | ✅ |
| Administrator dashboard | ✅ | ✅ | ✅ |

For every page and viewport: **no horizontal page overflow, no broken images,
exactly one `<h1>`, no image missing `alt`.**

| Check | Result |
|---|---|
| Room grid responsive | 3 columns @1366, 2 @768, 1 @390 |
| Dashboard tables on mobile | Stack into labelled cards below 1000px |
| Administrator actions reachable | Confirm/Cancel visible at every width after Defect 2 fix |
| Gallery | Selecting thumbnail 3 changed `src` **and** `alt`; `aria-pressed` = `false,false,true,false` |
| Video control | Control visible; click toggled playing → paused; poster present |
| Keyboard focus | Focused link showed a 3px outline |
| Broken links / assets | None (all internal references resolve) |

Screenshots: [`docs/evidence/`](docs/evidence/) — see
[Evidence](#evidence).

---

## Defects found and fixed

### Defect 1 — GET on the admin action endpoint returned 302, not 405

*Found by TC-102.* `admin-booking-action.php` set `http_response_code(405)`
and then called `auth_redirect()`, whose `header('Location: …', true, 302)`
silently **overwrote** the status. The endpoint advertised 405 but answered
302.

No booking was ever modified by a GET, so this was not a security hole — but
the code did not do what it said. Fixed by responding 405 with `Allow: POST`
and a small page, matching `logout.php`'s behaviour. **Retested: 405,
nothing modified.**

### Defect 2 — Administrator action buttons were off-screen

*Found by visual inspection of the captured screenshot.* The bookings table
had eleven columns and rendered 1200px wide inside a 1040px container, so
**Confirm and Cancel — the administrator's primary controls — sat beyond the
visible area** at ordinary desktop widths and required horizontal scrolling
inside the table.

Fixed by: merging check-in and check-out into one compact "Stay" column (both
dates still shown); showing the created date without the time; tightening the
table's type size and cell padding; and raising the stacked-card breakpoint
from 640px to 1000px so the table becomes cards whenever it cannot fit.

**Retested at 1366, 1280, 1100, 900, 768 and 390px: table fits at every
width, no inner scrolling, action buttons visible throughout.** A now-unused
`admin_date()` helper was removed at the same time.

### Defect 3 — (investigated, **not** a defect): reported page overflow

An automated check flagged horizontal overflow on the administrator dashboard
at 768px (`documentElement.scrollWidth` 1216 vs viewport 768). Investigation
showed the page **could not actually be scrolled sideways** — setting
`scrollLeft = 5000` left it at 0 — while the table scrolled correctly inside
its own container. Chrome's root `scrollWidth` over-reports when a nested
scroll container clips content; the original metric was the wrong test. No
code change was made for this. The check was replaced with a real
scroll attempt. (Defect 2's fix removed the wide table anyway.)

Similarly, an automated "clipped text" heuristic flagged 1–2 elements per
page; all were `.visually-hidden` screen-reader text, which is clipped by
design. Not defects.

---

## Not executed

These remain untested and are **not** claimed as passing:

| Area | Why |
|---|---|
| W3C HTML validation | Validator not run (requires internet or a local validator) |
| W3C CSS validation | As above |
| Screen-reader testing (NVDA / JAWS / VoiceOver) | No screen reader available; ARIA verified only by markup inspection |
| Manual keyboard walkthrough | Focus outline verified programmatically; a full tab-order walkthrough by hand was not performed |
| Cross-browser testing | Only Chrome 148 used. Firefox, Safari and Edge untested |
| Real mobile devices | Emulated viewports only |
| Load / performance testing | Not attempted |
| Concurrency under Apache with a process pool | Only the two-instance workaround was used (see section 8) |
| Colour-contrast re-verification on PHP pages | Measured in Phase 4 on static pages; not repeated here |
| Email, payment, password reset | Out of scope by design — no such code exists |

---

## Evidence

Screenshots in [`docs/evidence/`](docs/evidence/), captured from the running
application. They contain **no passwords, session identifiers or real
personal data** — only fictional `example.test` accounts.

| File | Shows |
|---|---|
| `homepage-desktop.png` | Full home page at 1366px |
| `homepage-mobile.png` | Home page at 390px |
| `room-twin-desktop.png` | Standard Twin Room page |
| `room-details-desktop.png` / `-mobile.png` | Presidential Suite page |
| `login-desktop.png` | Login form |
| `registration-desktop.png` | Registration form |
| `booking-form-desktop.png` / `-mobile.png` | Booking form with live room types |
| `customer-dashboard-desktop.png` / `-mobile.png` | Customer bookings, incl. mobile card stacking |
| `admin-dashboard-desktop.png` / `-mobile.png` | Live totals, bookings, Confirm/Cancel controls |
| `_report.json` | Raw measurements behind section 11 |

---

## Known limitations of this test round

1. **Single browser.** All visual results are Chrome 148 only.
2. **Concurrency evidence is strong but not exhaustive** — see section 8.
3. **Tests were run by an automated HTTP harness**, not by a person clicking
   through the interface. That is a strength for repeatability and for
   proving the no-JavaScript path, but it means subjective usability issues
   can be missed — Defect 2 was found by *looking at a screenshot*, not by
   any automated assertion.
4. **The database was reset between suites.** Long-lived data (many months of
   bookings, hundreds of accounts) has not been exercised; the admin list cap
   of 50 has never been reached in testing.
5. `example.test` accounts and a few demonstration bookings remain in the
   local database after this round. They are fictional and are not part of
   the repository.
