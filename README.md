# Roadline Safety

A PHP 8.2+ and MySQL hazard-reporting workflow for citizens, field workers, admins, and super admins. Reports can link to existing Roadline hazard geometry or store a new map pin; approving a report creates a work order, and only the original reporter can close it after worker completion.

## Requirements

- XAMPP with Apache, MySQL, PHP 8.2+, and `pdo_mysql` / `mbstring` enabled.
- A MySQL database named `roadzyrf_roadline` and a database account with permission to create views and modify the four Roadline tables.

## Setup

1. Start Apache and MySQL in XAMPP.
2. For a new database, import [`database/schema.sql`](database/schema.sql). To keep your existing ERD tables and data, back up the database and run [`database/migrate_roadline_erd.sql`](database/migrate_roadline_erd.sql) once instead. For an existing installation, also back up the database and run [`database/migrate_report_images.sql`](database/migrate_report_images.sql) once to add progress-photo storage and [`database/migrate_report_conversations.sql`](database/migrate_report_conversations.sql) once to add persistent worker/reporter conversations. The migrations are additive: the app reads and writes `Users`, `Hazards`, `Work_Orders`, and `Updates` through updatable compatibility views, not separate copies of those records.
3. Copy `.env.example` to `.env` in the project root and adjust the settings for your installation. The defaults assume XAMPP's local MySQL (`127.0.0.1`, port `3306`, database `roadzyrf_roadline`, user `root`, blank password). `APP_TIMEZONE` controls both displayed dates and the MySQL session clock; it defaults to `Asia/Manila`. The app loads `.env` automatically; process-level environment variables take precedence. `.env` is ignored by Git, so keep real credentials there and never commit them.
4. Inspect the available road tables and columns:

   ```sql
   USE roadzyrf_roadline;
   SELECT TABLE_NAME, COLUMN_NAME
   FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = 'roadzyrf_roadline'
   ORDER BY TABLE_NAME, ORDINAL_POSITION;
   ```

5. The default location source is `Hazards.Hazard_ID` and `Hazards.Coordinates`. Existing geometry points appear as map choices; reporters can also pin a new point. The migration adds report details and workflow state to the existing tables and creates views named `roadline_app_users`, `roadline_reports`, `roadline_jobs`, and `roadline_notifications` as the app's database adapter.
6. Create the first Super Admin from a terminal. Enter the credentials into the process environment (or temporarily set the `SUPER_ADMIN_NAME`, `SUPER_ADMIN_EMAIL`, and `SUPER_ADMIN_PASSWORD` values in `.env`), then run:

   ```powershell
   $env:SUPER_ADMIN_NAME = 'System Owner'
   $env:SUPER_ADMIN_EMAIL = 'owner@example.org'
   $env:SUPER_ADMIN_PASSWORD = 'replace-with-a-unique-password-of-at-least-12-characters'
   php bin/create_super_admin.php
   Remove-Item Env:SUPER_ADMIN_NAME, Env:SUPER_ADMIN_EMAIL, Env:SUPER_ADMIN_PASSWORD
   ```

   If you set these values in `.env`, clear them after creating the account. Do not put real passwords in this README or other source-controlled files. If a real credential was added here, remove it from any shared copies and change that account's password.

7. Visit `http://localhost/hazardReportingApp/`. The public home page explains how Roadline's hazard-reporting workflow works; use **Report a hazard** or **Create an account** to register, or choose **Sign in** if you already have an account.

The first reporter account can be created from the public home page or sign-in screen. Staff accounts are provisioned by an Admin or Super Admin. The Super Admin can create Admins, Workers, and Reporters; Admins can create Workers and Reporters. The original ERD has no login credentials, so the migration adds `Email` and `Password_Hash` to `Users`. Existing user rows remain intact but need an email, a PHP password hash, and an app role before their owners can sign in. Roles used by the app are `super_admin`, `admin`, `worker`, and `user`.

## Main features

- **Public information and account access:** Visitors can learn how Roadline works, read its policy pages, and register as Reporters or sign in.
- **Hazard reporting with location and photo:** Reporters describe a road hazard, select an existing Roadline location or place a map pin, and attach a photo. The report remains visible to its Reporter while it is reviewed and worked on.
- **Report review and work assignment:** Admins review incoming reports, approve them into work orders or decline them, and assign approved work to an active Worker.
- **Field progress and conversations:** Workers can post progress notes and photos, mark work ready for verification, and exchange persistent text and photo messages with the Reporter while the job is active.
- **Reporter verification and follow-up:** The original Reporter confirms completed work to close the job. If the hazard is still present, the Reporter can reopen it for follow-up.
- **Dashboards, history, and notifications:** Role-specific dashboards summarize report and work status. Reports and jobs have ongoing and history views, while in-app notifications keep participants informed about workflow changes.
- **Account administration:** Authorized staff can create, edit, pause, restore, or remove managed accounts. Removal disables access without deleting historical report and job records.
- **Private image handling and form protection:** Uploaded photos are stored outside the public web directory and access is restricted to people involved in the report and operations staff. Application forms use session-bound CSRF tokens, and passwords are stored as hashes.

## Primary users

| User | Role in the platform |
| --- | --- |
| **Community members / Reporters** | Create an account, submit hazards with a location and photo, follow progress, message the assigned Worker, and confirm whether a reported repair resolved the hazard. In the application, this role is named `user`. |
| **Field Workers** | View work orders assigned to them, share progress notes and photos, communicate with the Reporter, and mark work complete for Reporter verification. |
| **Administrators** | Review and approve or decline reports, assign approved work to active Workers, monitor operations, and manage Worker and Reporter accounts. |
| **Super Administrators** | Perform administrator functions and manage Admin accounts, in addition to Worker and Reporter accounts. This role is also used to establish the system's initial administrator. |
| **Public visitors** | Read the public overview and policy pages and create a Reporter account; they cannot access operational reports or work orders until signed in. |

## Roadline mapping

Hazard submissions and status are stored in `Hazards`; assignments and work status in `Work_Orders`; activity and per-user notifications in `Updates`; accounts and credentials in `Users`. The compatibility views map these existing tables to the field names the PHP app expects.

## Workflow

- Reporters submit hazards tied to a Roadline location and track report/job status.
- Reports and work orders are separated into ongoing and resolved sections for staff; reporters keep submitted reports and their history under **My reports**, separate from the **Add report** page. Once a job is assigned, its reporter and assigned worker can exchange text and optional photo messages in a persistent progress conversation while the job is active; conversations can be shown or hidden and completed conversations remain available to read.
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
  migrate_report_conversations.sql
  migrate_report_images.sql
  migrate_roadline_erd.sql
  schema.sql
index.php
```

The root `index.php` is the front controller. POST workflows are handled in `app/controllers/actions.php`, page data and access rules are prepared in `app/controllers/page.php`, shared helpers live in `app/helpers.php`, and HTML is rendered from `app/views/index.php`. Configuration, database schema, command-line utilities, and browser assets remain in their existing locations.