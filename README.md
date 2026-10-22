# Property CRM v2 — Enterprise Real Estate CRM

A production-ready Real Estate CRM built with **PHP (vanilla, PDO)**, **MySQL**, **Bootstrap 5**, and **Chart.js**, covering full RBAC across 7 roles, Buy/Sale/Rental lead management with a deal-closure revenue/profit/commission engine, project inventory tracking, meeting scheduling, audit logs, and a Chart.js-powered analytics dashboard — all mobile-responsive.

Every leads and follow-ups table includes **one-tap Call and WhatsApp buttons** for every client contact.

---

## 1. Requirements

- PHP 8.0+ (uses `match()`, typed returns)
- MySQL 5.7+ / MariaDB 10.3+
- Apache with `mod_rewrite` and `mod_headers` (or Nginx equivalent — see note below)
- A hosting environment with `pdo_mysql` enabled

---

## 2. Folder Structure

```
property-crm/
├── admin/                  Admin-only pages (dashboard, employees, reports, audit log)
├── employee/                Employee-facing pages (own dashboard, leads, properties)
├── api/                     JSON REST endpoints (leads, properties, employees, meetings)
├── auth/                    login.php, logout.php
├── config/                  database.php, config.php  (edit DB credentials here)
├── includes/                Shared PHP: auth guard, functions, header/sidebar/footer, templates
├── assets/css, assets/js    Bootstrap-based styling + vanilla JS (no build step needed)
├── database/                schema.sql — import this first
├── setup/                   One-time admin password setup script (delete after use)
├── uploads/                 Property image uploads land here
├── manifest.json, sw.js     PWA app-like experience
└── index.php                Entry point — redirects to login or dashboard
```

---

## 3. Setup Instructions

### Step 1 — Upload files
Upload the entire `property-crm/` folder to your web server (e.g. `public_html/property-crm/`).

### Step 2 — Create the database
1. Create a MySQL database (or let the script do it — `schema.sql` includes `CREATE DATABASE`).
2. **Fresh install:**
   ```bash
   mysql -u your_db_user -p < database/schema.sql
   ```
3. **Upgrading an existing v1 install instead** (keeps your existing data):
   ```bash
   mysql -u your_db_user -p property_crm < database/migration_v2.sql
   ```
   Back up your database first. The migration adds the new roles, permissions, projects table, and revenue/deal fields to leads without touching existing rows (v1 `converted` status is mapped forward to `deal_closed`).

4. **Already on v2 and upgrading further:** run these in order (each is safe to run once, back up first):
   ```bash
   mysql -u your_db_user -p property_crm < database/migration_v3_lease.sql
   mysql -u your_db_user -p property_crm < database/migration_v4_customer_type.sql
   mysql -u your_db_user -p property_crm < database/migration_v5_website_booking.sql
   mysql -u your_db_user -p property_crm < database/migration_v7_flat_selling.sql
   ```
   If "Add Lead" or "Add Buyer/Seller/Tenant" starts failing silently after an app update, it almost always means one of these hasn't been run yet against the live database — the app code expects the new columns/enum values.

### Step 3 — Configure database credentials
Edit `config/database.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'property_crm');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
```

### Step 4 — Set the base URL
Edit `config/config.php`:
```php
define('BASE_URL', '/property-crm'); // change to your actual deployment path, e.g. '' if at domain root
```

### Step 5 — Set a real admin password
The schema ships with a placeholder password hash. Visit:
```
https://yourdomain.com/property-crm/setup/setup_admin.php
```
Enter username `admin` and your chosen password. **Delete `setup/setup_admin.php` immediately after use.**

### Step 6 — Log in
```
https://yourdomain.com/property-crm/auth/login.php
Username: admin
Password: (the one you just set)
```

### Step 7 (Nginx only)
If you're on Nginx instead of Apache, the `.htaccess` files won't apply. Add to your server block:
```nginx
location ~ ^/property-crm/(config|includes|database)/ { deny all; }
location ~ \.(sql|log|env)$ { deny all; }
```

---

## 4. Roles & Permissions (RBAC)

Seven roles ship out of the box: **Super Admin, Admin, Manager, sell Executive, Telecaller, Employee, Accountant**. Each has a default permission set (`database/schema.sql` seeds `role_permissions`), and **Employees → Edit → Permission Overrides** lets a Super Admin/Admin/Manager grant or revoke individual permissions per person beyond their role default (e.g. give one Telecaller visibility into revenue without changing everyone's role).

Key rules enforced server-side (not just hidden in the UI):
- Only a **Super Admin** can create or edit **Super Admin/Admin** accounts.
- Financial fields (commission, revenue, profit) are stripped from API responses entirely for anyone without the `revenue.view` permission — not just hidden client-side.
- `leads.view_all` controls whether someone sees every lead or only their own assigned leads; `deals.close` / `deals.cancel` gate who can close a deal or mark one cancelled/lost.

See `includes/permissions.php` for the full permission catalogue and `userCan()` / `requirePermission()` helpers.

## 5. Deal Closure & Revenue Engine

Setting a lead's status to **Deal Closed** reveals a Revenue section capturing Property Value (or Monthly Rent for rentals) and Commission Rate. The server — never the browser — computes:

```
commission_amount = base × commission_rate / 100      (base = monthly_rent for rentals, property_value otherwise)
expected_revenue   = commission_amount
actual_revenue     = commission_amount (finalized once status = deal_closed)
profit             = revenue − employee_incentive
```

Cancelling or marking a lead **Lost** requires selecting a reason (Customer Rejected, Budget Issue, Competitor Won, etc.) — enforced server-side, not just a required HTML attribute.

## 6. Adding Team Members

Log in as admin → **Employees** → **Add Employee**. Set a username and temporary password; share it with the employee securely. Employees log in at the same `auth/login.php` URL and are automatically routed to their own dashboard, filtered to only their assigned leads and properties.

---

## 7. Call & WhatsApp Buttons

Every leads/follow-ups table row renders two round action buttons next to the client's contact info:
- 📞 **Call** → opens `tel:` link (dials directly on mobile)
- 💬 **WhatsApp** → opens `https://wa.me/<number>` with a pre-filled greeting message referencing the client's requirement

These are generated by `includes/quick_actions.php` (server-rendered) and mirrored in `assets/js/leads.js` / `assets/js/properties.js` (client-rendered rows). Mobile numbers are normalized to include the `91` country code automatically for 10-digit Indian numbers.

---

## 8. Security Notes

- Passwords hashed with bcrypt (`password_hash`/`password_verify`)
- All queries use PDO prepared statements (SQL injection protected)
- All output escaped via `e()` helper (XSS protected)
- CSRF tokens required on every state-changing API call
- Session fixation protection (`session_regenerate_id` on login)
- Idle session timeout (4 hours, configurable in `config.php`)
- Full audit trail in `activity_log` table (who changed what, when, old→new values)
- `.htaccess` blocks direct access to `/config`, `/includes`, `/database`, and sensitive file extensions

**Before going to production:**
1. Delete `/setup/setup_admin.php`
2. Set `APP_DEBUG` to `false` in `config/config.php` (already default)
3. Enable HTTPS and uncomment the `session.cookie_secure` and HTTPS-redirect lines in `config/config.php` / `.htaccess`
4. Change the default `DB_USER`/`DB_PASS` and restrict DB user privileges to only this database

---

## 9. What's Built vs. Future Enhancements

**Built now (v2):**
- Full RBAC across 7 roles (Super Admin, Admin, Manager, sell Executive, Telecaller, Employee, Accountant) with per-user permission overrides
- Extended lead capture: WhatsApp number, address/city/state/country, customer type, lead source, project linkage
- Deal Closure workflow with server-authoritative revenue/commission/profit calculation (Buy/Sale/Rental all supported, rentals use monthly rent as the commission base)
- Cancelled/Lost deal tracking with mandatory reason capture and loss analytics
- 11-stage lead status workflow (New → ... → Deal Closed / Cancelled / Lost) with full audit trail
- Projects module (builder, location, unit inventory, sold/available/rental unit tracking)
- Chart.js dashboard: revenue & profit trend, sell vs buy vs rental split, lead conversion funnel, closed vs cancelled vs lost, profit vs loss, lead source analysis, employee performance — all permission-gated so financial charts never reach roles without revenue access
- Reports v2: employee performance, property, project performance, lead source, revenue/profit/loss summary — CSV export + print-to-PDF
- Everything from v1: Buy/Sale/Rental property modules, Call/WhatsApp one-tap buttons throughout, meeting/follow-up scheduling, mobile-responsive card-based tables, audit logs

**Explicitly deferred (flag if you want these next):**
- Real-time notifications (new lead, meeting reminders, deal closed alerts)
- Dark mode / light mode toggle
- JWT authentication / Multi-Factor Authentication (current auth is session-based with CSRF protection, bcrypt passwords, and idle timeout — solid for a server-rendered app, but JWT would matter if you add a separate API-consuming frontend)
- Offline cache / PWA background sync
- Document upload (property_images table exists but has no upload UI yet)
- Lead merge/transfer UI (reassignment exists; deduplication merge does not)
- Saved filter presets
- Native mobile app (Android/iOS)
- WhatsApp Business API automation (current implementation uses `wa.me` click-to-chat, which requires no API/approval)
- SMS/Email notification triggers
- Google Maps address picker
- Automated DB backup/restore tooling
- Server-side Excel (.xlsx)/PDF generation library (current export is CSV + browser print-to-PDF)

---

## 10. Default Login (after Step 5)

```
URL:      /property-crm/auth/login.php
Username: admin
Password: (set by you via setup_admin.php)
```
