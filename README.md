# Hotel Booking System

**ICT304 Capstone 2 project**

> ⚠️ **UNDER DEVELOPMENT — NOT A WORKING SYSTEM YET.**
> This project is being rebuilt in phases. At present only the database
> foundation and the connection layer have been implemented. Registration,
> login, bookings, availability checking and the dashboards are **not yet
> functional**. Bookings made through the current front end are still stored
> in browser `localStorage` and do **not** reach the database. Do not treat
> this as a finished or secure application. See
> [Current status](#current-status) for detail.

---

## Project purpose

A web-based room reservation system for a **single demonstration hotel**. The
system is intended to let a guest browse room types, check availability for a
date range, register an account, make a booking, and view their own bookings,
while an administrator can review and manage incoming bookings.

Scope decisions for this project:

- **One hotel**, not a multi-hotel platform.
- All prices are in **Australian dollars (AUD)**.
- **Payment processing is out of scope.** No card details are collected,
  transmitted or stored anywhere in the system.
- **Email confirmation is out of scope.** No SMTP is configured.

---

## Technology stack

| Layer | Technology |
|---|---|
| Structure | HTML5 |
| Styling | CSS3 (hand-written, no framework) |
| Client-side behaviour | Vanilla JavaScript (no framework) |
| Server-side | PHP with the **mysqli** extension |
| Database | MySQL 8.0+ / MariaDB 10.4+ (InnoDB, utf8mb4) |
| Local server | Apache via XAMPP |
| Version control | Git |

There are **no Composer, npm or CDN dependencies**. The project runs from a
plain XAMPP installation with nothing else to install.

---

## XAMPP installation assumptions

This project assumes you are running it locally with **XAMPP**, which bundles
Apache, PHP and MariaDB (MySQL-compatible) together.

Assumptions:

- XAMPP is installed at `C:\xampp` on Windows (adjust paths if yours differs).
- PHP **8.0 or newer** — current XAMPP releases satisfy this.
- The `mysqli` PHP extension is enabled. It is enabled by default in XAMPP.
- MySQL/MariaDB listens on the default port **3306**.
- Apache listens on the default port **80**.
- The MySQL `root` account has a **blank password** — the XAMPP factory
  default. This is acceptable for local development only.

If you do not have XAMPP, download it from <https://www.apachefriends.org/>.

---

## Where to place the project

Copy the entire project folder into the XAMPP web root, `htdocs`:

```
C:\xampp\htdocs\hotel-booking-system\
```

The folder should end up looking like this:

```
C:\xampp\htdocs\hotel-booking-system\
├── index.html
├── booknow.html
├── login.html
├── register.html
├── admin-dashboard.html
├── DeluxeSuite.html   (and the other five room pages)
├── config.php
├── login.php
├── register.php
├── database.sql
├── README.md
├── docs\
│   └── DATABASE_DESIGN.md
├── images\
└── video\
```

The folder name you choose becomes part of the URL, so
`hotel-booking-system` gives you `http://localhost/hotel-booking-system/`.

---

## Starting Apache and MySQL

1. Launch the **XAMPP Control Panel**
   (`C:\xampp\xampp-control.exe`). On Windows, run it as Administrator if
   the services fail to start.
2. Click **Start** next to **Apache**. Wait for the module name to turn green.
3. Click **Start** next to **MySQL**. Wait for it to turn green as well.
4. Confirm both are running by opening <http://localhost/> in a browser.

**If a module refuses to start**, the port is usually already in use:

| Module | Common conflict | Fix |
|---|---|---|
| Apache (port 80) | Skype, IIS, or another web server | Change Apache to port 8080 via *Config → httpd.conf*, then use `http://localhost:8080/` |
| MySQL (port 3306) | An existing MySQL/MariaDB service | Stop the other service, or change the port and set `DB_PORT` (see below) |

---

## Importing `database.sql` with phpMyAdmin

The import script creates the `hotel_booking` database, all four tables, and
the development seed data. **You do not need to create the database first.**

1. With Apache and MySQL running, open <http://localhost/phpmyadmin/>.
2. Click the **Import** tab in the top menu bar.
   *(Import from the top-level view, not from inside an existing database —
   the script creates and selects its own database.)*
3. Under **File to import**, click **Choose File** and select
   `database.sql` from the project folder.
4. Leave the format as **SQL** and leave the other options at their defaults.
5. Scroll to the bottom and click **Go**.
6. On success you will see a green confirmation message, and `hotel_booking`
   will appear in the left-hand database list.

**Command-line alternative** (if you prefer, from the project folder):

```bash
C:\xampp\mysql\bin\mysql.exe -u root -p < database.sql
```

Press Enter at the password prompt if the `root` password is blank.

### What you should see after importing

Click `hotel_booking` in the left panel. You should find four tables:

| Table | Rows after import |
|---|---|
| `users` | 0 — expected; no accounts are seeded (see below) |
| `room_types` | 6 |
| `rooms` | 12 |
| `bookings` | 0 — expected; no bookings are seeded |

**No administrator account is created by the import.** Seeding an admin with
a known password would be a security defect. Once authentication is built in
a later phase, register normally through the site and then promote that
account in phpMyAdmin:

```sql
UPDATE users SET role = 'admin' WHERE email = 'your.email@example.com';
```

### Re-running the import

`database.sql` is written to be safely re-runnable. It contains no
`DROP TABLE` or `DROP DATABASE` statements and uses `CREATE ... IF NOT EXISTS`
and `INSERT IGNORE`, so importing it a second time will not destroy data you
have already entered.

---

## Database configuration options

`config.php` reads its settings from **environment variables when they are
set**, and otherwise falls back to XAMPP defaults. For a standard XAMPP
install **you do not need to change anything**.

| Variable | Default | Purpose |
|---|---|---|
| `DB_HOST` | `localhost` | Database server hostname |
| `DB_PORT` | `3306` | Database server port |
| `DB_NAME` | `hotel_booking` | Database name |
| `DB_USER` | `root` | Database username |
| `DB_PASS` | *(blank)* | Database password |

Notes:

- The connection is forced to **utf8mb4** to match the schema.
- Strict `mysqli` error reporting is enabled internally, so database faults
  cannot be silently ignored.
- Connection failures are written to the **PHP error log**
  (`C:\xampp\php\logs\php_error_log` or `C:\xampp\apache\logs\error.log`).
  Visitors only ever see a generic "service temporarily unavailable" page —
  no credentials, hostnames or MySQL messages are exposed.
- **Never commit real credentials.** `.env` and `config.local.php` are already
  listed in `.gitignore`.

If you changed the MySQL port, set `DB_PORT` in your environment rather than
editing `config.php`.

---

## Opening the site locally

With Apache and MySQL running and the project in `htdocs`:

| Page | URL |
|---|---|
| Home page | <http://localhost/hotel-booking-system/index.html> |
| Booking form | <http://localhost/hotel-booking-system/booknow.html> |
| **Login** | <http://localhost/hotel-booking-system/login.php> |
| **Register** | <http://localhost/hotel-booking-system/register.php> |
| Customer dashboard | `customer-dashboard.php` (requires login) |
| Administrator dashboard | `admin-dashboard.php` (requires an admin account) |

`login.php` and `register.php` are the **canonical** authentication pages.
The old `login.html` and `register.html` still exist so bookmarks do not
break, but they no longer contain forms — each is a small page that redirects
to its `.php` equivalent.

Adjust the folder name if you used a different one, and add `:8080` if you
moved Apache off port 80.

**Open the site through `http://localhost/`, not by double-clicking the HTML
files.** Opening a file directly gives a `file:///` address, and PHP will not
execute — you would see raw source code instead of a page.

---

## Accounts, roles and authentication

### Roles

Every account has a `role`, stored in `users.role`:

| Role | Lands on | Can access |
|---|---|---|
| `customer` | `customer-dashboard.php` | Their own dashboard only |
| `admin` | `admin-dashboard.php` | The administrator dashboard |

New registrations are **always** created as `customer`. The registration form
never sends a role, and `register.php` omits the column from its `INSERT` so
the database default applies — a crafted form cannot create an administrator.

A customer who tries to open `admin-dashboard.php` is sent back to their own
dashboard with an explanation. A signed-out visitor is sent to the login page.

### Registration flow

1. Visit `register.php`.
2. Enter full name, email address, password and password confirmation.
   Passwords must be at least 8 characters and the two must match.
3. The server validates every field, normalises the email to lower case, and
   checks for an existing account using a prepared statement. The `UNIQUE`
   key on `users.email` is the final guarantee, so two simultaneous
   registrations of the same address cannot both succeed.
4. On success the password is hashed with `password_hash()` and the account is
   created, then you are redirected to `login.php` with a confirmation message.

Registration does **not** log you in automatically — you log in explicitly.

### Login flow

1. Visit `login.php`.
2. Enter your email address and password.
3. The server looks the account up with a prepared statement and verifies the
   password with `password_verify()` against `users.password_hash`.
4. On success the session ID is regenerated, your user ID, name and role are
   stored in the session, and you are redirected to the dashboard for your
   role.

An unknown email address and an incorrect password produce the **same**
message, so the login page cannot be used to discover which addresses are
registered.

### Logging out

Logout is **POST only** and requires a CSRF token, so it is done with the
"Log out" button on a dashboard, not by following a link. Visiting
`logout.php` directly in the address bar does **not** log you out — it shows a
confirmation button instead. This prevents another site (or a browser
prefetching a link) from signing you out without your intent.

### Creating an administrator on your local machine

No administrator account ships with this project — seeding one with a known
password would be a security defect. To create one:

1. Register normally at `register.php` with the address you want to use.
2. Open <http://localhost/phpmyadmin/>, select `hotel_booking`, open the
   **SQL** tab and run:

   ```sql
   UPDATE users SET role = 'admin' WHERE email = 'your.email@example.com';
   ```

3. Log out and log back in. Your session picks up the new role at login, so
   you must sign in again for the change to take effect.
4. You will now land on `admin-dashboard.php`.

Never set a password directly in phpMyAdmin — the column stores a hash, not a
password, and typing a plaintext value there would make the account
unusable.

---

## Current status

This project is mid-rebuild. Honest status of each area:

| Area | Status |
|---|---|
| Database schema (`database.sql`) | ✅ Written — four tables, seeded room data |
| Database connection (`config.php`) | ✅ Hardened — env vars, utf8mb4, safe error handling |
| Version control + documentation | ✅ Established |
| Registration (`register.php`) | ✅ Rebuilt — prepared statements, duplicate-email check, hashed passwords, CSRF |
| Login (`login.php`) | ✅ Rebuilt — prepared statements, `password_verify`, generic errors, CSRF |
| Sessions and roles | ✅ Hardened — HttpOnly, SameSite=Lax, strict mode, ID regenerated at login |
| Logout (`logout.php`) | ✅ POST + CSRF only |
| Customer dashboard | ⚠️ Exists and is protected, but is a **shell** — no booking history yet |
| Admin dashboard | ⚠️ Exists and is protected by role, but is a **shell** — no booking management yet |
| Static room pages | ⚠️ Display correctly, but content is hard-coded, not read from the database |
| Home page layout | ❌ Known structural defect (unclosed element) |
| **Bookings** | ❌ **Still stored in browser `localStorage`, never reach MySQL; no check-out date** |
| Availability checking | ❌ Not implemented (placeholder alert only) |
| Testing | ❌ No tests written or executed yet |

### Booking is not finished

Authentication works, but **booking does not**. `booknow.html` and
`booknow.js` are unchanged: the form still writes to browser `localStorage`,
still has no check-out date, and nothing it produces reaches the `bookings`
table or either dashboard. Database-backed booking, the check-out date and
availability checking are all **Phase 3** work.

### Nothing here has been run

**No PHP or MySQL runtime testing has been performed on the machine where
this code was written** — neither PHP nor MySQL was available there. That
means the database import, the registration and login flows, the session and
CSRF behaviour and the role redirects have all been written and reviewed by
inspection, but **not executed**. Treat every flow described above as
untested until you have run it yourself in XAMPP.

---

## Assessment areas

This project is assessed against four areas.

### 1. Business rules

The rules the system must enforce — maximum occupancy per room type, a
booking requiring both a check-in and a later check-out date, a room not
being double-booked for overlapping dates, one account per email address, and
administrator-only access to administrative functions. Rules must be enforced
**on the server**, not only in JavaScript, since client-side checks can be
bypassed. Several of these rules are currently expressed as database
constraints in `database.sql`; the application-level enforcement is still to
be built.

### 2. Project management

Evidence of how the work was planned, tracked and delivered: a Git repository
with a meaningful commit history, setup and design documentation, a phased
implementation plan, and a documented test plan with recorded results. The
repository and documentation established in this phase are the foundation for
that evidence.

### 3. Visual design (front end)

The user-facing interface: layout, consistency, responsive behaviour across
desktop and mobile, accessibility, and the absence of placeholder or
incorrect content. The existing visual style is intentionally preserved for
now; defects such as the broken home-page layout and the missing responsive
breakpoints are scheduled for a later phase.

### 4. Database design

A normalised relational schema with appropriate primary keys, foreign keys,
indexes and constraints, supported by an entity-relationship diagram and
written justification. See [`docs/DATABASE_DESIGN.md`](docs/DATABASE_DESIGN.md).

---

## Repository layout

```
.
├── .gitignore              Excludes secrets, IDE, OS, temp and log files
├── README.md               This file
├── database.sql            Schema + development seed data
├── config.php              Database connection (mysqli)
├── auth.php                Session, role, CSRF, escaping and flash helpers
├── login.php               Login page (canonical)
├── register.php            Registration page (canonical)
├── logout.php              Logout (POST + CSRF only)
├── customer-dashboard.php  Protected customer dashboard (shell)
├── admin-dashboard.php     Protected administrator dashboard (shell)
├── login.html              Legacy redirect to login.php
├── register.html           Legacy redirect to register.php
├── admin-dashboard.html    Legacy notice — no administrator content
├── booknow.html            ⚠️ Still localStorage-based; Phase 3 rewrites this
├── *.html                  Room pages and home page
├── *.css                   Stylesheets (css.css, dashboard.css, …)
├── *.js                    Client-side scripts
├── docs/
│   └── DATABASE_DESIGN.md  Schema documentation and ER diagram
├── images/                 Room photography and site imagery
└── video/                  Home page banner video
```
