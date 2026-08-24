# Hosting Notes

**Hotel Booking System — ICT304 Capstone 2**

Preparation notes for moving this project off `localhost`. Nothing here has been
deployed; this is documentation and configuration guidance only.

---

## 1. Requirements

| Component | Used here | Minimum |
|---|---|---|
| PHP | 8.2.12 | 7.3 (the session cookie code has a pre-7.3 fallback) |
| Database | MariaDB 10.4.32 | MariaDB 10.2 / MySQL 5.7 |
| Web server | Apache 2.4.58 | Apache 2.4 with `mod_headers`, `mod_rewrite`, and `AllowOverride All` |
| PHP extensions | `mysqli` only | — |

No Composer, no external packages, no CDN. The whole application is PHP, MySQL,
CSS and vanilla JavaScript.

> ⚠️ **`AllowOverride All` matters.** Every protection in `.htaccess` — the
> security headers, the `.git/` block, the `database.sql` block — silently
> stops working if the host ignores `.htaccess` files. On a host that does,
> move the contents into the virtual host configuration instead.

---

## 2. Configuration

`config.php` reads its settings from environment variables and falls back to
XAMPP defaults when they are absent. Variable names are listed in
[`.env.example`](.env.example).

**There is no dotenv loader.** Copying `.env.example` to `.env` on its own does
nothing — the values must reach PHP as real environment variables.

**Apache virtual host:**

```apache
SetEnv DB_HOST 127.0.0.1
SetEnv DB_PORT 3306
SetEnv DB_NAME hotel_booking
SetEnv DB_USER hotel_app
SetEnv DB_PASS "the-real-password"
```

**Managed hosts** (cPanel, Plesk, most PaaS) usually offer an environment
variable panel — use that rather than putting credentials in a file.

### Database account

Do not deploy with `root`. Create a restricted account:

```sql
CREATE USER 'hotel_app'@'localhost' IDENTIFIED BY 'a-strong-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON hotel_booking.* TO 'hotel_app'@'localhost';
FLUSH PRIVILEGES;
```

The application issues no DDL at runtime, so it needs no `CREATE`, `ALTER` or
`DROP`. Schema changes are applied by running the files in `migrations/`
manually as an administrator.

---

## 3. Deployment order

1. Create the schema: `mysql -u root hotel_booking < database.sql`
2. Apply any migrations newer than the dump, in filename order:
   - `migrations/2026-08-20-add-cancellation-request.sql`
   - `migrations/2026-08-21-add-login-attempts.sql`
3. Set the environment variables and restart Apache.
4. Confirm `.htaccess` is being honoured — see the checklist below.
5. Register the first account through `register.php`, then promote it:
   `UPDATE users SET role='admin' WHERE email='...';`
   No administrator is seeded, so there is no default password to change.

---

## 4. Enable HTTPS, then enable HSTS

HSTS is **deliberately not set** in `.htaccess`. On `http://localhost` it would
tell the browser to refuse plain HTTP for the whole host — breaking every other
XAMPP project on the machine, and persisting long after this site is gone.

Once a real certificate is serving the production domain over HTTPS, add this
to the **production virtual host** (not to the repository `.htaccess`):

```apache
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

Start with a short `max-age` (e.g. `300`) and raise it only once HTTPS is
confirmed working — the header is hard to undo once browsers have cached it.
Add `preload` only if you intend to submit the domain and never serve it over
plain HTTP again.

The application already adapts on its own: `auth_is_https()` in `auth.php`
detects HTTPS and turns on the `Secure` flag for the session cookie
automatically, so **no code change is needed** when the certificate is
installed. It deliberately ignores `X-Forwarded-Proto`, which a client can
forge — if the production setup terminates TLS at a load balancer, that
function is the one place to revisit, and it must then trust the proxy header
only when the request genuinely came from the proxy.

---

## 5. Pre-launch checklist

Confirm each of these against the live URL:

- [ ] `https://example.com/.git/config` → **403**
- [ ] `https://example.com/database.sql` → **403**
- [ ] `https://example.com/.env` → **403**
- [ ] `https://example.com/HOSTING.md` → **403**
- [ ] `https://example.com/docs/` → **403**
- [ ] `https://example.com/migrations/` → directory listing off, `.sql` denied
- [ ] Response headers include `Content-Security-Policy`, `X-Frame-Options`,
      `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`
- [ ] Browser console shows **no CSP violations** on the home page, a room
      page, the booking form and both dashboards
- [ ] `display_errors` is `Off` in the production `php.ini`
- [ ] The error log is writable, and is **not** inside the web root
- [ ] Database credentials are not `root` / blank
- [ ] Session cookie shows `Secure`, `HttpOnly` and `SameSite=Lax` in DevTools

---

## 6. Known gaps for a real deployment

These are acceptable in a university prototype but would need work before
handling real guests and real money:

- **No HTTPS locally.** The `Secure` cookie flag is therefore off during
  development; it turns itself on under HTTPS.
- **No email.** Password reset, booking confirmation emails and address
  verification do not exist, so a forgotten password can only be fixed by an
  administrator updating the hash directly.
- **No payment handling.** No card data is collected or stored anywhere, which
  is why PCI scope is currently nil — that changes entirely the moment a
  payment provider is added.
- **Login throttling is per IP and stored in one table.** Adequate for one
  server; behind a load balancer the IP must come from a trusted proxy header,
  and a distributed attacker spread across many addresses is not stopped by it.
- **No multi-factor authentication** for administrator accounts.
- **No automated backups.** `mysqldump` is currently run by hand.
- **Sessions are stored as files** in the PHP temp directory. On multiple
  application servers this needs a shared session store.
