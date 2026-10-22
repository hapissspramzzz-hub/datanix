# Datanix — Implementation & design plan

## Product intent

Build **Datanix**, a new and independent AI Data Assistant. It does not replace or modify QUR’AN VISUAL or EventHub. The primary path is **Upload/connect data → ask a natural-language question → see deterministic facts and a source table → receive bounded AI interpretation → save an insight → build/report from it**.

## Implementation approach

- **Stack:** native PHP 8.3+, MySQL/MariaDB through PDO, session authentication, Bootstrap 5.3, vanilla JavaScript, and a same-origin PHP REST API. Run locally on Apache/XAMPP/Laragon or through the PHP router used by the preview.
- **Persistence:** one user can own multiple workspaces. Workspaces own memberships, source connections, datasets, normalized column metadata, stored JSON rows, conversations/messages, queries/usage, dashboards/widgets, reports, saved insights and subscription/payment/audit records. Every private query scopes by workspace membership and role before reading the underlying rows. Fresh schema imports contain no seeded users or fabricated billing totals; an operator promotes the first registered administrator through a restricted database console.
- **Truth and AI:** parsing, filtering, date boundaries, totals, comparisons, ranks, groups, charts and table rows are calculated deterministically in PHP from stored rows. The model cannot write or execute SQL and cannot invent or supply KPI values. Only aggregate facts and schema—not raw uploaded row contents or connector secrets—may be sent to the server-side, configurable, OpenAI-compatible provider to produce non-numeric narrative insights/recommendations. Validate/sanitize the model response; render numeric values exclusively from local calculation output. If input, metric or comparison data are insufficient, return exactly: **“Saya belum bisa memastikan karena data yang tersedia tidak mencukupi.”** If the AI key/provider is not configured, clearly use a deterministic response instead of fabricating an AI answer.
- **Model/provider:** provider-neutral server adapter reads `AI_PROVIDER`, `AI_API_BASE`, `AI_API_KEY`, `AI_MODEL` from environment. Default preview model `gpt-5-mini` was confirmed in the live model catalog; allow replacement through config, never expose keys to browser code. Require an explicit disclosure before the first AI request explaining that aggregate summaries go to the configured provider.
- **Data ingestion:** validate size, extension, MIME, row/column limits and headers; support CSV, XLSX first worksheet and JSON. MySQL/PostgreSQL/API connectors are separate source records with status, owner, schema, record count and last sync. Encrypt stored connection secrets with AES-GCM using `APP_ENCRYPTION_KEY`; keep connectors read-only, sanitize identifiers, apply safe query row limits, verify TLS for HTTPS APIs, restrict private/localhost network targets by default and document an operator-managed host allowlist for controlled deployments. A public sample file comes from the real UCI Online Retail dataset (CC BY 4.0), with visible attribution and its 2010–2011 date range; no fabricated business data are seeded.
- **Model/provider:** provider-neutral server adapter reads `AI_PROVIDER`, `AI_API_BASE`, `AI_API_KEY`, `AI_MODEL` from environment. Default preview model `gpt-5-mini` was confirmed in the live model catalog; allow replacement through config, never expose keys to browser code. Require an explicit disclosure before the first AI request explaining that aggregate summaries go to the configured provider.
- **Data ingestion:** validate size, extension, MIME, row/column limits and headers; support CSV, XLSX first worksheet and JSON. MySQL/PostgreSQL/API connectors are separate source records with status, owner, schema, record count and last sync. Encrypt stored connection secrets with AES-GCM using `APP_ENCRYPTION_KEY`; keep connectors read-only, sanitize identifiers, apply safe query row limits, verify TLS for HTTPS APIs, restrict private/localhost network targets by default and document an operator-managed host allowlist for controlled deployments. A public sample file comes from the real UCI Online Retail dataset (CC BY 4.0), with visible attribution and its 2010–2011 date range; no fabricated business data are seeded.
- **Freemium:** Free permits 1 workspace, 3 datasets and 100 AI questions/month, basic charts/dashboard. Pro uses a demo price of Rp79.000/month within the brief’s illustrative Rp49.000–99.000 range; it enables unlimited personal workspaces/datasets, higher configurable AI quota, advanced analytics, dashboard builder, exports and saved insights. Business exposes team memberships/roles/shared dashboards/API integrations/higher usage. A manual subscription request exists, but there is no payment gateway or automatic charge; admin revenue reflects only recorded confirmed payments, not projected/simulated sales.
- **Reports/export:** CSV and genuine XLSX use local calculated results; PDF offers a clean print layout and browser “Save as PDF” flow. Reports include executive summary, KPIs, charts, findings and recommendations derived from available data.
- **Privacy:** content remains in workspace storage. Query logs avoid retaining raw data rows. Explain provider disclosure and retention in the privacy/data page. Uploads live outside the public route tree; credentials are not included in sample configs or ZIP.

## Project structure

```text
datanix/
  index.php, login.php, register.php, logout.php, forgot-password.php, reset-password.php
  workspaces/              workspace list, create, switch and role-scoped settings
  app/                      signed-in app shell, overview and global settings
  datasets/                 upload/import, explorer, sync and source management
  chat/                     conversations and deterministic/AI-backed questions
  dashboards/               AI-assisted create, widget builder and saved dashboards
  reports/                  report generator, export and print view
  insights/                 saved insight library
  profile/                  profile, password and privacy preferences
  admin/                    visually distinct admin home, users, plans, audit, usage and revenue
  api/index.php             authenticated REST routes and JSON contract
  assets/css|js|img/        responsive visual system, charts, original wordmark/icons
  config/                   environment-driven PDO/AI configuration (no secrets committed)
  database/schema.sql       full multi-tenant schema plus least-privilege demo users
  includes/                  bootstrap, roles, CSRF, analytics, import/export, safe UI components
  samples/                   attributed UCI sample data
  uploads/ and storage/      private files/rows, denied through HTTP
  router.php + .htaccess     local PHP router and Apache protections
  manus-routes.json          complete declarative page-route manifest
  README.md                  installation, demo, API, provider and connector setup
```

## Design system

- **Design movement:** restrained Swiss information design meets editorial enterprise SaaS: crisp grid, high legibility and a warm, approachable data workspace. Brand inspiration is original; do not copy a competitor’s assets, layout, language or identity.
- **Core principles:** (1) facts before flourish, (2) every figure has dataset/column provenance, (3) progressive disclosure for no-code users, (4) workspaces feel calm and precise rather than crowded.
- **Color philosophy:** white and near-black/graphite establish trust; fog gray creates generous whitespace; a distinctive electric-cobalt-to-indigo accent signals action and AI without turning the product neon. Use green/amber/red only as restrained semantic states, never as decoration.
- **Layout paradigm:** asymmetrical, two-column hero with a bespoke in-browser dashboard mockup on the right; application uses a collapsible vertical workspace sidebar and a spacious, scrollable analysis canvas; admin uses a separate darker graphite control-plane shell, not a reskinned user dashboard.
- **Signature elements:** (1) a custom D-mark formed from linked data nodes/rows, (2) “fact provenance” chips showing source/field/row counts, (3) cobalt query rails and compact confidence/source strips beneath every result.
- **Interaction philosophy:** instant local changes for sort/filter/widget controls; explicit confirmation before destructive deletion, connector changes or first external AI disclosure; visible usage meter and load/error/empty states; no dead links.
- **Animation:** 140–200 ms transitions, small chart draw-in and skeleton shimmer only while loading; respect `prefers-reduced-motion`; no parallax or perpetual movement.
- **Typography:** Inter (fallback system sans) for UI, IBM Plex Mono for SQL-free schema values and numeric metadata; hierarchy uses 32–52 px editorial page titles, 20–24 px panel headings, 14–16 px body, tabular numerals for KPIs.
- **Brand essence:** “A clear, source-grounded AI analyst for people who need business answers, not another query language.” Personality: **precise, calm, empowering**.
- **Brand voice:** concise, candid and non-technical. Example: “Tanyakan penjualan—kami tunjukkan cara angkanya dihitung.” / “Data belum cukup untuk menjawab itu. Tambahkan rentang tanggal atau kolom yang relevan.”
- **Wordmark/logo:** original lowercase “datanix” wordmark paired with a custom 3-node D glyph; inline SVG source belongs to this project and avoids external logo dependencies.
- **Signature color:** Datanix cobalt `#4E5BFF`, supported by deep-indigo `#222A72` and slate ink `#172033`.

## Material assumptions

- The selected name is **Datanix**, per user. No claim of trademark or domain availability is made.
- English phrases explicitly requested (“Ask your data anything.” and sample prompts) remain visible in the bilingual interface, while primary navigation/help text is Indonesian.
- This deliverable is a runnable prototype/production-shaped codebase. Database connectors require operator allowlisting and the end user’s own credentials; no real payment provider is configured.
