# LavaLust Framework

> A lightweight, fast PHP framework built for developers who want clean MVC architecture without unnecessary complexity or performance overhead.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-8892BF)](https://www.php.net/)
[![GitHub Stars](https://img.shields.io/github/stars/ronmarasigan/lavalust?style=flat)](https://github.com/ronmarasigan/lavalust/stargazers)

---

## Overview

**LavaLust** is an open-source PHP framework that follows the **MVC (Model–View–Controller)** architectural pattern. It is designed for developers who need a structured, maintainable, and scalable foundation — without the bloat of heavier modern frameworks.

Whether you are building a simple web application, a REST API, or a teaching project, LavaLust provides the right tools with minimal friction.

---

## Features

| Feature | Description |
|---|---|
| **MVC Architecture** | Clean separation of Models, Views, and Controllers for organized, maintainable code |
| **Built-in Routing** | Flexible URL routing that maps requests to controllers with minimal configuration |
| **Libraries & Helpers** | Reusable components for sessions, forms, validation, and database access |
| **Modular Design** | Scalable structure that supports clean organization as your application grows |
| **REST API Support** | First-class support for building RESTful APIs using LavaLust conventions |
| **ORM-like Models** | Simplified, readable database interaction without a heavy abstraction layer |

---

## Requirements

- PHP 7.4 or higher
- A web server with URL rewriting support (Apache `.htaccess` or Nginx config)
- Composer (optional, for dependency management)

---

## Installation

**Clone the repository:**

```bash
git clone https://github.com/ronmarasigan/lavalust.git
cd lavalust
```

**Or download a release directly:**

```bash
wget https://github.com/ronmarasigan/lavalust/archive/refs/heads/main.zip
unzip main.zip
```

Configure your web server to point to the project root and ensure `mod_rewrite` (Apache) or equivalent is enabled.

---

## Quick Start

### 1. Define a Route

**File:** `app/config/routes.php`

```php
$router->get('/', 'Welcome::index');
$router->get('/about', 'Welcome::about');
$router->post('/users/store', 'Users::store');
```

### 2. Create a Controller

**File:** `app/controllers/Welcome.php`

```php
<?php

class Welcome extends Controller
{
    public function index()
    {
        $data['title'] = 'Home';
        $this->call->view('welcome', $data);
    }

    public function about()
    {
        $this->call->view('about');
    }
}
```

### 3. Create a View

**File:** `app/views/welcome.php`

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $title ?></title>
</head>
<body>
    <h1>Welcome to LavaLust Framework</h1>
    <p>Lightweight. Fast. MVC.</p>
</body>
</html>
```

### 4. Create a Model

**File:** `app/models/User_model.php`

```php
<?php

class User_model extends Model
{
    protected $table = 'users';

    public function getAll()
    {
        return $this->db->table($this->table)->get()->getResult();
    }

    public function findById(int $id)
    {
        return $this->db->table($this->table)
                        ->where('id', $id)
                        ->get()
    }
}
```

---

## Project Structure

```
lavalust/
├── app/
│   ├── config/          # Application configuration (database, routes, etc.)
│   ├── controllers/     # Controller classes
│   ├── models/          # Model classes
│   ├── views/           # View templates
│   └── libraries/       # Custom libraries and helpers
├── scheme/              # Core framework files (do not modify)
├── public/              # Publicly accessible entry point
│   └── index.php
└── runtime/            # Cache, logs, and uploads (must be writable)
```

---

## Configuration

### PDF Reports

Admin PDF reports use the standalone TCPDF 6.10.1 library bundled under
`app/libraries/tcpdf`; Composer is not required. The LavaLust wrapper and report
branding asset are `app/libraries/Pdf.php` and `app/libraries/assets/`. The PDF uses its opaque
JPEG asset to remain compatible with PHP installations without GD or Imagick;
the browser UI uses the transparent PNG version.
Hazard reports list incidents for a date range. Barangay activity reports show
active resident totals, incident status summaries, and report history grouped by
the submitting resident's registered barangay. Keep the bundled TCPDF
`LICENSE.TXT` when redistributing the application.

### Database

**File:** `app/config/database.php`

```php
$database['main'] = array(
    'driver'	=> getenv('DB_DRIVER') ?: '',
    'hostname'	=> getenv('DB_HOST') ?: '',
    'port'		=> getenv('DB_PORT') ?: '',
    'username'	=> getenv('DB_USER') ?: '',
    'password'	=> getenv('DB_PASSWORD') ?: '',
    'database'	=> getenv('DB_NAME') ?: '',
    'charset'	=> getenv('DB_CHARSET') ?: '',
    'dbprefix'	=> getenv('DB_PREFIX') ?: '',
    // Optional for SQLite
    'path'      => ''
);
```

The database username can be provided as `DB_USER` (the `.env.example` name) or
`DB_USERNAME`; if both are set, `DB_USERNAME` takes precedence.

For managed MySQL providers such as Aiven, set `DB_HOST`, `DB_PORT`,
`DB_USERNAME` (or `DB_USER`), `DB_PASSWORD`, and `DB_NAME` to the values from
the provider console. Aiven connections negotiate TLS even when no CA file is
configured. For server identity verification (recommended), download the
provider CA certificate and add it to Render as a Secret File named
`aiven-ca.pem`. The backend automatically uses `/etc/secrets/aiven-ca.pem`; if
you use a different file name or path, set `DB_SSL_CA` to its mounted path.
Without a CA, the connection is encrypted but the server certificate is not
verified. Keep the certificate on the backend only; do not commit it or put
database credentials in frontend environment variables.

### Calapan weather forecast

The resident and admin dashboards read current conditions and a three-day
forecast from WeatherAPI.com through `GET /api/weather/calapan`. The backend
fixes the requested coordinates to Calapan City and keeps the WeatherAPI key server-side.
Create `BayanAlert-Backend/.env` from `.env.example` and set `WEATHERAPI_KEY`
to a key from your WeatherAPI.com account. The frontend does not need or expose
the provider key. The resident dashboard can show one-time weather-change
reminders; the admin dashboard displays conditions without resident reminders.

### External hazard feed

The backend also exposes a lightweight external hazard feed at `GET /api/external/resident-feed`
for USGS earthquakes, GDACS multi-hazard and tropical cyclone alerts, and HDX overlay metadata. The admin APIs
at `GET /api/admin/external-apis`, `POST /api/admin/external-apis/{key}/sync`, and
`PATCH /api/admin/external-apis/{key}/config` allow health checks, manual sync triggers,
and configuration updates without exposing the provider keys to the browser. The related
migration `app/migrations/008_create_external_api_tables.php` creates the supporting tables.
Apply migration `004` first if the `notifications` table is missing, then migration `008`, and
then `app/migrations/009_create_system_api_registry.php` before using the current API Management
tab or persisting notification deep links. Migration `009` adds
notification report/advisory targets, stores rate-limit reset timestamps, and creates the
`system_apis` registry. The registry routes are under `/api/admin/system-apis`; they support
listing, create/update, connection testing, cache/rate-limit reset, and deletion of custom entries.
For a manual phpMyAdmin/MySQL alternative, select the BayanAlert database and run
[`manual_migrations_external_api_004_008_009_010.sql`](./manual_migrations_external_api_004_008_009_010.sql)
instead of applying migrations `004`, `008`, `009`, and `010` separately. It is safe to re-run
and expands `external_hazard_events.hazard_type` for normalized hazard types. It also removes
retired NASA EONET, ReliefWeb, and Open-Meteo integration rows and cached events.
After importing the BayanAlert schema, run
[`manual_migration_011_barangays_moderation_resolved.sql`](./manual_migration_011_barangays_moderation_resolved.sql)
to seed the 62 Calapan barangays and approximate map centers, add report and barangay coordinates,
enable three-strike account moderation, and add resolved-report timestamps. The script is
safe to re-run and is required before using the location suggestions and moderation APIs.
Set a stable `APP_KEY` of at least 32 characters before saving API credentials: credentials in
the registry are encrypted with AES-256-GCM and cannot be recovered if this key is changed.
Edits to built-in endpoint, credential, authentication, and parameter settings are used by the
backend's live weather, regional-hazard, and transactional-email requests, not only by the
connection-test button. Brevo connection tests check account authentication without sending an email;
email delivery uses the configured SMTP endpoint.
Connection tests are run on demand. WeatherAPI and Brevo use credentials stored in API Management
or the `WEATHERAPI_KEY` and `BREVO_API_KEY` backend environment variables; their keys remain
server-side. USGS and GDACS are public feeds and do not require API keys. The Leaflet map uses the
clipped, WGS84 GeoJSON at
`BayanAlert-Frontend/public/data/calapan_barangays.geojson`, sourced from UN OCHA HDX's
`cod-ab-phl` admin4 GeoJSON entry (`phl_admin4.geojson`, resource
`0120c30e-ba8b-487d-83f5-a664eddd3a8e`). The full national archive is nearly 1 GB; only the
Calapan City features are bundled and served by the frontend, so neither the browser nor Render
downloads the national archive. Preserve UN OCHA HDX attribution when updating this subset.
The map also serves `BayanAlert-Frontend/public/data/calapan_emergency_places.geojson`, a static
OpenStreetMap snapshot filtered to points inside those Calapan barangay polygons. It is rendered
alongside the HDX barangay boundaries by default, with independently toggleable layers; the
source is © OpenStreetMap contributors under the ODbL. The map also queries Overpass for fire stations tagged
with either `amenity=fire_station` or `emergency=fire_station` inside Calapan City's administrative
area; if Overpass is unavailable, the bundled snapshot remains the fallback. Update the bundled
snapshot deliberately and retain its source/snapshot metadata when refreshing.
Hospitals, clinics, and police locations are mapped listings, not a guarantee of current operations.
Schools and community facilities are shown only as **unverified possible evacuation sites** and
must be confirmed with Calapan City DRRMO before anyone relies on them. The current snapshot has
no OSM-tagged Calapan fire station or designated evacuation center; this does not establish that
none exist in reality. A separate BFP pin and its hotline, plus city-wide Tamaraw Fire Volunteer
Brigade and Calapan City DRRMD/Public Safety Rescue & Fire Unit contacts, were supplied by the
requester and are not independently verified. Only the BFP pin has a supplied map coordinate;
the other contacts are shown as city-wide contacts rather than invented map locations.
For production, set
`APP_ENV=production` and set a random `APP_KEY` in the backend host's environment;
the key is required for CSRF protection and must not be committed. Generate one
with `php -r "echo bin2hex(random_bytes(32));"`. When the frontend is hosted
separately, set its build-time `VITE_API_URL` to this backend's public `/api`
base URL and redeploy the frontend. Set the backend's `FRONTEND_URL` to the
frontend origin so API responses and login preflight requests receive the correct
CORS headers before database startup.

### Persistent evidence uploads

Render's web-service filesystem is temporary unless a persistent disk is mounted.
Create a disk on the backend service mounted at `/var/data` and set
`BAYANALERT_EVIDENCE_DIR=/var/data/evidence`. New uploads are written there and
served through `GET /api/media/evidence/{filename}`. For local development, the
default remains `public/uploads/evidence`. Existing evidence URLs that return
404 are missing from the deployed filesystem and must be restored from a backup
or re-uploaded; the database stores their paths, not the image contents.

## BayanAlert admin category management

The application includes an admin-only BayanAlert management panel at `/admin` and an admin sign-in page at `/auth/login`. Import the BayanAlert MySQL schema before using the application. Admin accounts must have a matching row in `roles` with `role_name = 'admin'`, and their `users.account_status` must be `active`.

The panel provides searchable, paginated registers and CRUD forms for reports, users, roles, barangays, hazard categories, stations, assignments, evidence, resolutions, verifications, duplicate checks, and notifications. OTP verification records and report status history are view-only so administrators cannot expose verification hashes or rewrite the audit trail. Password and OTP hashes are never displayed; user passwords are hashed by the application when set. Database foreign-key rules still apply, and deleting a parent record may cascade to related records.

The hazard category CRUD is available in the panel under **Directory → Hazard categories**. Existing `/category` routes remain available for compatibility.

New resident registrations are assigned to the configured `resident` role, or `user` role when that is the resident role name in the database, and saved with `account_status = 'pending'`. An administrator must activate the account before the resident can sign in. The Users register displays role and barangay names while retaining their IDs in the edit form.

The frontend keeps one authenticated account type per browser at a time. Sign out of an admin account before signing in as a resident, or sign out of a resident account before signing in as an admin. This session guard is synchronized across tabs in the same browser.

The authenticated resident dashboard endpoint `GET /api/reports/community` returns current hazard reports, status/category totals, reporter-barangay totals, and only the signed-in resident's notifications. Community reports include confirm/dispute totals and the signed-in resident's vote; `POST /api/reports/{id}/vote` accepts `{"vote":"confirm"}`, `{"vote":"dispute"}`, or `{"vote":null}` to remove a vote. Each resident has one changeable vote per report and cannot vote on their own report. Report responses omit reporter identity and exact address details.

The admin portal's **Notifications** page shows new-report notifications and lets admins publish announcements. `POST /api/admin/announcements` sends a notification to every active account with the `resident` or `user` role. Residents receive notifications when an admin changes the status of one of their reports and can mark notifications as read. The notification APIs are `GET /api/notifications` and `POST /api/notifications/{id}/read`; notification records are scoped to their recipient.

After migration `011`, `GET /api/barangays` includes barangay center coordinates and
`GET /api/location-suggestions?q=...` returns matching barangays and previously reported
locations. Admins can issue warning strikes with `POST /api/admin/users/{id}/warning-strike`,
directly suspend or reactivate with `POST /api/admin/users/{id}/suspension`, and resolve active
reports with `POST /api/admin/reports/{id}/resolve`. Three strikes automatically suspend a
resident; suspended residents cannot sign in or submit/edit reports. Resolved incidents stay
in the admin and PDF history but are excluded from live hazard-map markers.

For a fresh local database, import `bayanalert_schema.sql` from a MySQL/MariaDB client or phpMyAdmin. It creates all application tables, including `notifications` and report coordinates, and seeds the required `admin`, `resident`, and compatibility `user` roles. It does not erase existing tables. The importing database user needs permission to create the `bayanalert_db` database, or you can select a database and remove the `CREATE DATABASE` and `USE` statements first.

For an existing database, retain its data and apply the needed migrations instead of importing the fresh schema. Migrations are disabled by default in `app/config/migration.php`. To enable report locations on an existing schema, apply `app/migrations/003_add_hazard_report_coordinates.php` or run:

```sql
ALTER TABLE hazard_reports
    ADD COLUMN latitude DECIMAL(10,7) NULL,
    ADD COLUMN longitude DECIMAL(10,7) NULL;
```

Apply `app/migrations/004_create_notifications_table.php` if the `notifications` table is missing; it creates that table and converts an existing enum `notification_type` column to a string column so all notification types are accepted.

Apply `app/migrations/005_create_community_report_votes.php` to enable resident
confirm/dispute votes in the community incident feed. Each resident can have
one vote per report, change it between confirm and dispute, or remove it by
selecting the active vote again. Report owners cannot vote on their own reports.
The migration creates the `report_community_votes` table and should be applied
to existing databases before deploying the updated community dashboard. If
applying the migration manually, run:

```sql
CREATE TABLE report_community_votes (
    vote_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    vote VARCHAR(10) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (vote_id),
    UNIQUE KEY report_community_vote_unique (report_id, user_id),
    KEY report_community_votes_user_id_idx (user_id),
    CONSTRAINT report_community_votes_report_fk
        FOREIGN KEY (report_id) REFERENCES hazard_reports (report_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT report_community_votes_user_fk
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Brevo transactional email

The registration form's **Verify** action sends a six-digit code that expires
after 10 minutes; codes are hashed in `otp_verifications`, and resend requests
are limited to one per minute, with a five-attempt limit per code. After
account creation, unverified residents can also receive the existing 24-hour
verification link. Residents cannot sign in until verified; administrator
activation is separate. Approved hazard reports are also emailed to their
configured emergency department.

Brevo is called over its HTTPS REST API (not SMTP). Configure the API key in
Admin API Management or with `BREVO_API_KEY` on the backend, and configure
`BREVO_SENDER_EMAIL` and `BREVO_SENDER_NAME` either in the Brevo API's custom
parameters (`sender_email`, `sender_name`) or as backend environment variables.
Database settings take precedence when present and enabled; environment values
are fallbacks. Keep secrets out of the frontend and source control. The sender
email must be verified with Brevo. `FRONTEND_URL` is used for post-registration
verification links; `APP_BASE_URL` is used for evidence links. Brevo's successful
response confirms a message was queued, not that it reached the inbox.

Apply `app/migrations/006_create_email_verification_tokens.php` to an existing
database, or run this SQL once in the selected database:

```sql
CREATE TABLE email_verification_tokens (
    verification_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (verification_id),
    UNIQUE KEY email_verification_user_unique (user_id),
    UNIQUE KEY email_verification_token_unique (token_hash),
    CONSTRAINT email_verification_user_fk
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

The verification endpoints create the required link-token tables on first use.
The registration form's OTP flow requires the OTP and pre-registration
verification tables to be provisioned before deployment. For an existing
database, run
[`manual_migration_013_otp_verifications.sql`](./manual_migration_013_otp_verifications.sql).
The migration adds the email, expiration-use, and registration-code fields while
retaining existing OTP records, and creates the pre-registration verification
state table if it is missing. Migration
`app/migrations/007_create_registration_email_verifications.php` is also
available for environments that manage this table separately. Its manual SQL is:

```sql
CREATE TABLE registration_email_verifications (
    verification_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(150) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (verification_id),
    UNIQUE KEY registration_email_verification_email_unique (email),
    UNIQUE KEY registration_email_verification_token_unique (token_hash),
    KEY registration_email_verification_expiry_idx (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

For an existing database, preserve sign-in for previously approved residents by
grandfathering active accounts before enabling email-verification checks:

```sql
UPDATE users
SET email_verified_at = NOW()
WHERE account_status = 'active'
  AND email_verified_at IS NULL
  AND role_id IN (SELECT role_id FROM roles WHERE role_name IN ('resident', 'user'));
```

Verification endpoints are `POST /api/auth/send-registration-verification`
with `{"email":"..."}`, `POST /api/auth/verify-registration-otp` with
`{"email":"...","code":"123456"}`, `POST /api/auth/verify-email` with `{"token":"..."}`,
and `POST /api/auth/resend-verification` with `{"email":"..."}` for registered
accounts. The sender address must be verified with Brevo.

Resident sign-in provides a password-reset link for verified resident accounts.
Reset tokens are stored hashed, expire after one hour, are single-use, and are
rate-limited to one email per account per minute. The API creates
`password_reset_tokens` on first use; if the database account cannot create
tables, run [`manual_migration_010_password_reset_tokens.sql`](./manual_migration_010_password_reset_tokens.sql)
in the selected database. The reset routes are
`POST /api/auth/request-password-reset` and `POST /api/auth/reset-password`.

Before deploying the admin user-deletion workflow to an existing database, run
[`manual_migration_014_user_deletion_preserve_history.sql`](./manual_migration_014_user_deletion_preserve_history.sql)
against the Aiven database. It changes user foreign keys on historical records
to `ON DELETE SET NULL` and makes known historical user-reference columns
nullable. Admin deletion removes transient notification and verification records
in the same transaction and preserves submitted reports, evidence, and audit history.

When an admin marks a hazard report `verified` or `approved`, the edit form
preselects the routed department email, shows the report details to be sent, and
allows the admin to change the recipient or disable email delivery before
approving. Categories without an automatic route require a valid recipient
address or the admin can turn off the email. The default destinations are DOH,
BFP, CDRRMO, and PCG as configured in `BrevoMailer`.

If a Brevo API key has been shared in chat, rotate/revoke it in Brevo and set the
replacement only in the backend `.env`.

The public Leaflet hazard map uses `GET /api/map/reports` and only returns category, status, and coordinates for located reports. Incident addresses and reporter details are not included. The map uses OpenStreetMap tiles and does not require a CARTO API key. Until the coordinate columns exist on an existing database, maps remain centered on Calapan without fabricated markers, and report submission continues to work using the address field.

When residents pin a report on the map, the frontend uses OpenStreetMap
Nominatim reverse geocoding to suggest an address. Residents can edit the
suggestion or enter an address manually if lookup is unavailable.

Create the admin role if it does not exist:

```sql
INSERT INTO roles (role_name, description)
VALUES ('admin', 'System administrator');
```

Generate a password hash locally, then use it when inserting an admin user:

```bash
php -r "echo password_hash('replace-with-a-strong-password', PASSWORD_DEFAULT), PHP_EOL;"
```

Store the complete output in `password_hash`; never store the raw password or an
MD5/SHA hash. To reset an existing account, generate a fresh hash the same way
and update only that account's row:

```sql
UPDATE users
SET password_hash = '<paste-generated-hash>'
WHERE username = 'admin';
```

Confirm the username/email belongs to the intended account and that
`account_status` is `active`. Admin and resident accounts use the same password
hash format, but must sign in through their matching login page.

```sql
INSERT INTO users (role_id, full_name, email, username, password_hash, account_status)
SELECT role_id, 'Administrator', 'admin@example.com', 'admin', '<paste-generated-hash>', 'active'
FROM roles
WHERE role_name = 'admin';
```

The admin can add, view, update, deactivate, and delete hazard categories. The schema restricts deleting categories already used by hazard reports; those categories should be deactivated instead.

### Base URL

**File:** `app/config/config.php`

```php
$config['base_url'] = 'http://localhost:3000/';
```

---

## Building a REST API

LavaLust supports REST API development out of the box. Controllers can return JSON responses for API endpoints.

```php
<?php

class Api extends Controller
{
    $this->call->library('api');

    public function users()
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt(); 

        $this->call->model('User_model');
        $users = $this->User_model->getAll();

        $this->api->respond(['data' => $users]);
    }
}
```

Route definition:

```php
$router->get('/api/users', 'Api::users');
```

---

## Philosophy

LavaLust is built on a single principle: **minimal core, maximum control.**

Modern frameworks often add layers of abstraction that benefit large enterprise teams but get in the way of developers who want to understand exactly what their code is doing. LavaLust provides structure and utilities without hiding the underlying logic — making it an excellent choice for:

- **Rapid prototyping** — Get an application running in minutes
- **Learning MVC** — Understand how each architectural layer works
- **Lightweight production apps** — Deploy without dragging in unused dependencies
- **Teaching PHP development** — Clear conventions, readable source code

---

## Documentation

Full documentation is available at **[https://lavalust.netlify.app](https://lavalust.netlify.app)**

Topics covered include:

- Installation and server configuration
- Routing: static, dynamic, and grouped routes
- Controllers and request handling
- Models and query builder
- Views, layouts, and partials
- Built-in libraries (sessions, form validation, file upload)
- Helper functions
- REST API development
- Security best practices

---

## Contributing

Contributions are welcome. To contribute:

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/your-feature-name`
3. Commit your changes: `git commit -m "Add your feature description"`
4. Push to your branch: `git push origin feature/your-feature-name`
5. Open a pull request against `main`

Please ensure your code follows the existing style conventions and includes relevant documentation or comments where appropriate.

---

## Roadmap

- [ ] CLI tool for generating controllers, models, and migrations
- [ ] Middleware support
- [ ] Improved query builder with relationship support
- [ ] Enhanced error handling and debugging tools

---

## License

LavaLust Framework is open-source software licensed under the **[MIT License](https://opensource.org/licenses/MIT)**.

---

## Links

- **GitHub Repository:** [https://github.com/ronmarasigan/lavalust](https://github.com/ronmarasigan/lavalust)
- **Documentation:** [https://lavalust.netlify.app](https://lavalust.netlify.app)
- **Report an Issue:** [https://github.com/ronmarasigan/lavalust/issues](https://github.com/ronmarasigan/lavalust/issues)