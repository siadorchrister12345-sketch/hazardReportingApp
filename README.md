# Roadline Safety

A PHP 8.2+ and MySQL hazard-reporting workflow for citizens, field workers, admins, and super admins. Reports can link to existing Roadline hazard geometry or store a new map pin; approving a report creates a work order, and only the original reporter can close it after worker completion.

## Requirements

- XAMPP with Apache, MySQL, PHP 8.2+, and `pdo_mysql` / `mbstring` enabled.
- A MySQL database named `roadline` and a database account with permission to create views and modify the four Roadline tables.

## Setup

1. Start Apache and MySQL in XAMPP.
2. For a new database, import [`database/schema.sql`](database/schema.sql). To keep your existing ERD tables and data, back up the database and run [`database/migrate_roadline_erd.sql`](database/migrate_roadline_erd.sql) once instead. If the existing installation has already applied that migration, also back up the database and run [`database/migrate_report_images.sql`](database/migrate_report_images.sql) once to add progress-photo storage. The migrations are additive: the app reads and writes `Users`, `Hazards`, `Work_Orders`, and `Updates` through updatable compatibility views, not separate copies of those records.
3. Configure the PHP/Apache process environment. Defaults assume XAMPP's local MySQL (`127.0.0.1`, port `3306`, user `root`, blank password). Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` if your installation differs. Configure Apache environment variables in `apache/conf/extra/httpd-vhosts.conf` (inside the relevant `<VirtualHost>`) or `apache/conf/httpd.conf`, then restart Apache. Do not commit production credentials.
4. Inspect the available road tables and columns:

   ```sql
   USE roadline;
   SELECT TABLE_NAME, COLUMN_NAME
   FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = 'roadline'
   ORDER BY TABLE_NAME, ORDINAL_POSITION;
   ```

5. The default location source is `Hazards.Hazard_ID` and `Hazards.Coordinates`. Existing geometry points appear as map choices; reporters can also pin a new point. The migration adds report details and workflow state to the existing tables and creates views named `roadline_app_users`, `roadline_reports`, `roadline_jobs`, and `roadline_notifications` as the app's database adapter.
6. Create the first Super Admin from a terminal with credentials supplied only to the process environment:

   ```powershell
   $env:SUPER_ADMIN_NAME = 'System Owner'
   $env:SUPER_ADMIN_EMAIL = 'owner@example.org'
   $env:SUPER_ADMIN_PASSWORD = 'replace-with-a-unique-password-of-at-least-12-characters'
   php bin/create_super_admin.php
   Remove-Item Env:SUPER_ADMIN_NAME, Env:SUPER_ADMIN_EMAIL, Env:SUPER_ADMIN_PASSWORD
   ```

   Do not put real passwords in this README or other source-controlled files. If a real credential was added here, remove it from any shared copies and change that account's password.

7. Visit `http://localhost/hazardReportingApp/`.

The first reporter account can be created from the sign-in screen. Staff accounts are provisioned by an Admin or Super Admin. The Super Admin can create Admins, Workers, and Reporters; Admins can create Workers and Reporters. The original ERD has no login credentials, so the migration adds `Email` and `Password_Hash` to `Users`. Existing user rows remain intact but need an email, a PHP password hash, and an app role before their owners can sign in. Roles used by the app are `super_admin`, `admin`, `worker`, and `user`.

## Roadline mapping

Hazard submissions and status are stored in `Hazards`; assignments and work status in `Work_Orders`; activity and per-user notifications in `Updates`; accounts and credentials in `Users`. The compatibility views map these existing tables to the field names the PHP app expects.

## Workflow

- Reporters submit hazards tied to a Roadline location and track report/job status.
- Admins review incoming reports, approve them into jobs or decline them, and assign approved jobs to active Workers.
- Workers save progress and mark work complete. This changes the job to **Pending User Verification** and notifies the original Reporter.
- The Reporter confirms the fix to close the job, or reports that the hazard remains to reopen the work and notify Admins.
- Notifications are persisted in MySQL. Signed-in pages refresh periodically to surface new updates.
- A JPEG, PNG, or WebP photo (up to 5 MB) is required with each new report. Workers and reporters can optionally attach a progress photo to work updates and repair verification. Uploaded images are stored outside the public web directory and served only to the reporter, assigned worker, or operations admins for that report. Ensure the PHP/Apache account can write to the default storage directory, or set `ROADLINE_UPLOAD_DIR` to choose a different writable private storage directory.

Account removal is a soft delete: access is disabled and the account is hidden from management, while historical report/job references remain intact. Account passwords use PHP's `password_hash`; all application forms use session-bound CSRF tokens.

## Local development

The normal XAMPP URL is `http://localhost/hazardReportingApp/`. To use PHP's built-in server instead, from the project directory run `php -S 127.0.0.1:8080`; MySQL must still be running and configured as above.

## Project structure

```text
app/
  bootstrap.php
  controllers/
    actions.php
    page.php
  views/
    index.php
  helpers.php
assets/
  app.css
  app.js
bin/
  create_super_admin.php
config.php
database/
  migrate_roadline_erd.sql
  schema.sql
index.php
```

The root `index.php` is the front controller. POST workflows are handled in `app/controllers/actions.php`, page data and access rules are prepared in `app/controllers/page.php`, shared helpers live in `app/helpers.php`, and HTML is rendered from `app/views/index.php`. Configuration, database schema, command-line utilities, and browser assets remain in their existing locations.