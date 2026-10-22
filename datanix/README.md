# Datanix — AI Data Assistant

Datanix is a standalone PHP 8+ / MySQL application for asking questions about structured data. It keeps workspaces and datasets isolated, computes every numeric result locally with deterministic PHP, and optionally uses an OpenAI-compatible provider only for bounded qualitative narrative after explicit user consent.

## What is included

- Responsive Indonesian-first web app with the requested English chat heading and prompts.
- Password registration/login, logout, profile/password changes, reset-token flow, CSRF protection, rate-limited login attempts, secure password hashes and workspace roles.
- Multiple workspace and dataset management; roles: owner, admin, editor, analyst and viewer.
- CSV, XLSX (first worksheet) and JSON import, private upload storage, schema detection, paginated Explorer, search/filter/sort, column visibility and CSV export.
- Read-only MySQL/PostgreSQL/API connectors with operator host allow-listing, bounded result rows and encrypted saved connection settings.
- Workspace-scoped conversations and analytics for supported aggregates, ranks, comparisons, trends, growth, anomaly signals, simple forecasts and conversion ratios when the schema supports them.
- Dashboard builder, report creation, XLSX/CSV export, print-to-PDF, saved insights and separate admin console.
- Free/Pro/Business feature and usage limits. Upgrade requests are manual only; the app does not collect card details, charge a card or connect to a payment gateway. Admin revenue is the sum of confirmed payment records only.
- `samples/uci-online-retail.csv`: exactly 5,000 authentic records sampled deterministically from UCI Online Retail, with visible source, license and historical-date attribution. It is not synthetic or current sales data.

## Requirements

- PHP 8.1+ (PHP 8.3 recommended) with `pdo_mysql`, `mbstring`, `curl`, `openssl`, `fileinfo`, `zip` and `SimpleXML` enabled.
- MySQL 8+ or MariaDB 10.5+.
- Optional: `pdo_pgsql` for PostgreSQL connections; working outbound HTTPS and an OpenAI-compatible provider for AI narrative; a configured mail transport for delivering password-reset links.

## Local setup

1. Create an empty database and a dedicated application user. Do not run the web app as a database superuser. Example (replace the password with a generated secret):

   ```sql
   CREATE DATABASE datanix CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'datanix_app'@'127.0.0.1' IDENTIFIED BY 'REPLACE_WITH_A_LONG_RANDOM_PASSWORD';
   GRANT SELECT, INSERT, UPDATE, DELETE ON datanix.* TO 'datanix_app'@'127.0.0.1';
   ```

2. Import the schema as a database administrator:

   ```sh
   mysql -u root -p < database/schema.sql
   ```

   The schema intentionally creates **no account with a published default password**. Register your own account at `/register.php`.

3. Set the environment variables from `.env.example` in your shell, PHP-FPM/Apache pool, container or hosting control panel. The application reads process environment variables; it does not load a `.env` file automatically. At minimum configure:

   ```sh
   export DATANIX_DB_HOST=127.0.0.1
   export DATANIX_DB_NAME=datanix
   export DATANIX_DB_USER=datanix_app
   export DATANIX_DB_PASSWORD='your-generated-database-password'
   export APP_ENCRYPTION_KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
   export APP_ENV=production
   ```

   Keep this configuration outside the public document root and never commit real values. For use behind an HTTPS reverse proxy, set `APP_BASE_URL` to the external application base path (or leave it blank at the site root); ensure the proxy forwards `X-Forwarded-Proto: https`.

4. Optional AI narrative configuration:

   ```sh
   export AI_PROVIDER=openai-compatible
   export AI_API_BASE='https://your-provider.example/v1'
   export AI_API_KEY='your-server-side-key'
   export AI_MODEL='gpt-5-mini'
   ```

   `AI_API_BASE` must be HTTPS. If no provider/key is configured, deterministic calculations remain available and no data is sent to an AI provider. With a provider configured, the chat UI discloses that the question and selected aggregate facts are sent for qualitative commentary and requires consent before the first request. Raw dataset rows, connector secrets and KPI values for calculation are not delegated to the model. The model cannot execute SQL.

5. For local preview from the project directory:

   ```sh
   php -S 0.0.0.0:4175 -t . router.php
   ```

   Open `http://localhost:4175/`. The declarative page manifest is available at `/manus-routes.json`. Production deployments should use HTTPS, a supported web server/PHP-FPM, restrictive filesystem permissions, backups and a non-public private-storage directory.

6. Bootstrap the first platform admin after registering the intended account. Run this as a database administrator, substituting that account's email:

   ```sql
   UPDATE users SET role='admin' WHERE email='you@example.com';
   ```

   Admin pages are at `/admin/index.php`. Platform role (`admin`) is separate from workspace membership roles.

## Using the app

1. Register and sign in; registration creates a personal Free workspace.
2. Import CSV/XLSX/JSON, or load the attributed UCI sample from the workspace. Review its actual date range before asking period-specific questions.
3. Open AI Chat and choose a supported question. The PHP query engine calculates values, dates, groups, chart/table data and provenance from rows stored in the selected workspace. Unsupported or insufficient questions use the exact response: “Saya belum bisa memastikan karena data yang tersedia tidak mencukupi.”
4. Create dashboards and reports from available dataset fields. Metrics that have no supporting columns are not invented. Free limits are enforced in server-side routes, not only hidden in the interface.
5. To request Pro/Business, use the Plans page. Requests enter a pending manual-review queue. An admin may activate a plan only after independent verification; confirming a payment record is an administrative ledger action, not a charge initiated by Datanix.

## Connectors and security configuration

- For a database/API connector, the operator must explicitly configure `CONNECTOR_HOST_ALLOWLIST` and provide the end user's own read-only credentials. Private/local hosts are blocked by default; only enable `CONNECTOR_ALLOW_PRIVATE_NETWORK=true` on an isolated development network after assessing the risk. Use TLS for remote API endpoints.
- Connector secrets are encrypted with AES-256-GCM using `APP_ENCRYPTION_KEY`; preserve and back up that key securely or stored credentials cannot be decrypted after rotation.
- Uploaded files and stored rows belong to the workspace and are kept under private `storage/` paths; config, database, storage and upload directories are denied by the supplied Apache/router protections. The DB user should have only the permissions required by the application.
- The reset flow creates expiring, single-use tokens and uses PHP's mail transport. Configure a working mail service (`MAIL_FROM` and the host mailer) before relying on password resets in production; do not expose reset tokens in logs.
- There are no pre-created test users, test payments, fabricated revenue totals or live billing credentials. Public deployments must configure HTTPS, secure environment variables, SMTP, backups, monitoring, outbound connector policies and appropriate retention/consent notices.

## Main route groups

- Public: `/`, `/how-it-works.php`, `/plans.php`, `/privacy.php`, `/register.php`, `/login.php`, `/forgot-password.php`, `/reset-password.php`
- Workspace app: `/app/index.php`, `/workspaces/`, `/datasets/`, `/chat/index.php`, `/dashboards/`, `/reports/`, `/insights/`, `/profile/`
- Admin: `/admin/index.php`, `/admin/users.php`, `/admin/usage.php`, `/admin/subscriptions.php`, `/admin/logs.php`
- REST API: same-origin `/api/...`, JSON responses, cookie-session writes protected by CSRF. Start with `GET /api/health`; application endpoints are routed in `api/index.php`.

## Sample dataset attribution

The included records are attributed to Chen, D. (2015), *Online Retail* [Dataset], UCI Machine Learning Repository, [https://doi.org/10.24432/C5BW33](https://doi.org/10.24432/C5BW33), under **CC BY 4.0**. The source spans 2010-12-01 through 2011-12-09; `UnitPrice` is in GBP. Selected rows preserve cancellations and missing values. See [`samples/README.md`](samples/README.md) for selection and interpretation details. Do not present the sample as current-month sales.

## Project map

- `config/`: PDO and environment-driven AI configuration.
- `database/schema.sql`: multi-tenant MySQL/MariaDB schema (empty of default accounts/revenue).
- `includes/`: sessions, CSRF, permissions, importer/exporter, connectors, deterministic query engine and AI narrative adapter.
- `api/index.php`: same-origin REST endpoints.
- `chat/`, `datasets/`, `dashboards/`, `reports/`, `insights/`, `workspaces/`, `profile/`: standalone page modules.
- `admin/`: separate platform operations console.
- `storage/`, `uploads/`: denied from public HTTP access; uploaded data must remain private.
- `manus-routes.json`: user-facing page route manifest.
