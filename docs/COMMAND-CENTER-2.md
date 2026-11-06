# Command Center 2 implementation

## Architecture audit

The admin controller registers capability-scoped WordPress pages, POST actions
and nonce-protected AJAX endpoints. `Olama_Messages_Workspace` wraps both modern
and legacy renderers; `workspace.css/js` are loaded only on Messages pages.
Modern admin owns Overview, Campaigns, wizard, Delivery activity and Phone Book.
Legacy admin retains campaign progress, templates, settings and agent views.

Campaign service owns draft persistence, authoritative audience preview,
preparation, immutable recipient snapshots and explicit `SEND {count}`
authorization. Operations service provides read-only queue/health summaries.
Core/provider services own school, finance, transportation and store audiences.
Agent/dispatcher services own reservations, KDE submissions and recovery.
Existing queue, campaign, recipient, dispatch-attempt and agent tables supply
the command center. No schema change is needed.

## Integration boundaries

The supplied HTML is a visual reference, not a source of operational records.
Preserve all nonce/capability checks and existing preparation/authorization
actions. Keep WordPress's admin bar and dashboard return link. Use scoped CSS
and existing Dashicons rather than adding a second Bootstrap runtime.
Never equate `sent` with recipient delivery: it records a KDE submission.
Missing delivery confirmation and missing history must be explicit.

Verification uses disposable database fixtures with external HTTP disabled;
never authorize or dispatch a real campaign for UI testing.

## Implemented screens and files

- `admin/class-olama-messages-workspace.php`: grouped navy navigation, Arabic
  labels, semantic main region, mobile bottom navigation and WordPress return.
- `assets/workspace.css` and `assets/workspace.js`: scoped navy/indigo design
  tokens, responsive cards, charts, forms, drawers, focus management and a
  keyboard page finder. Debounced GET filters progressively replace the main
  region using the same PHP routes; ordinary GET submission works without JS.
- `admin/class-olama-messages-modern-admin.php`: live overview hero, year filter,
  linked metrics and chart, compact recent campaigns, attention panel, campaign
  audience/year/status/search/sort/pagination, audience-source shortcuts and
  paginated frozen campaign exclusions.
- `assets/admin-campaign-wizard.js`: three visible stages. Legacy step IDs remain
  valid: 1/2 share Audience, 3 is Message, 4/5 share Review and Authorization.
  Financial checkbox capture accepts both first-stage IDs. Preparation remains
  separate from the existing typed `SEND {count}` authorization.
- `admin/class-olama-messages-admin.php`: campaign recipient filtering, bounded
  pagination, CSV export, on-demand detail drawer, explicit KDE lifecycle and
  unavailable recipient-delivery confirmation. Library search/pagination use
  `includes/class-olama-messages-template-service.php`.
- `includes/class-olama-messages-operations-service.php`: read-only dashboard
  aggregates and shared bounded recipient queries; formula-safe CSV cells.
- `assets/admin.js`: filtered progress refresh every 10 seconds while active;
  hidden tabs defer polling, terminal campaigns stop polling.

## Metric definitions

Submitted today counts unique queue rows currently `sent`, with `sent_at` within
the school's current local day, converted to UTC bounds. Dispatch attempts today
counts recorded attempt rows created during those same bounds, including retries
and attempts still in progress. These are deliberately distinct measures;
successful KDE submission is never described as delivery to a phone.

Attention totals combine `unknown` and `failed` queue rows across years, while
prepared campaign counts are shown separately as awaiting authorization. Annual
campaign totals include archived SMS campaigns in the selected school year.
The chart shows current submissions for up to seven recent non-draft,
non-archived campaigns in that year; it does not invent historical time series.

## API and data changes

No database migration, sending protocol, agent implementation or campaign-service
mutation has changed. Existing capabilities and nonce mechanisms are retained.

- New AJAX action `olama_msg_recipient_detail`: shared Messages nonce and
  `olama_access_messages`, campaign/queue ID scoping, masked phone, latest 20
  recorded attempt summaries. It never sends or retries a message.
- New admin-post action `olama_msg_export_delivery`: same capability plus a
  campaign-specific nonce, current search/status/sort filters, masked phones,
  spreadsheet formula neutralization and streamed batches of at most 500 rows.
- Existing progress AJAX accepts recipient search/status/sort filters so polling
  refreshes the same rows as the initial server-rendered view (12 per page).

## Verification

`php tests/test-command-center.php` extends the disposable WordPress/MariaDB
workspace suite. It checks real statistics, academic-year scope, SQL escaping,
search/sort/pagination, CSV cells, frozen exclusions, unauthorized/invalid-nonce
and cross-campaign detail access, masked phones, actual attempt history, draft
save semantics, prepared-campaign locking and zero new dispatch attempts.

Generate fixtures by setting `OLAMA_WORKSPACE_FIXTURE` to a temporary directory.
Then run `node tests/command-center-browser-check.js` with an external Playwright
installation (`OLAMA_BROWSER_MODULE`) and `OLAMA_WORKSPACE_SCREENSHOTS` output
directory. Browser fixtures use the actual PHP-rendered views and production
assets with synthetic data; their HTTP responses are intercepted inside the
browser test. They cannot reach a sending transport.

Additional regressions: SMS recovery/dispatch, dispatcher readiness, agent
telemetry, PHP/JavaScript segmentation, communications integration, renewal
audiences and phone-book export. PHP lint, JavaScript syntax checks and
`git diff --check` are part of verification.

Verified browser coverage: 32 RTL layouts across 360, 390, 768 and 1440 pixels;
navigation/search, debounced filtering with focus preservation, recipient drawer
and Escape/focus restoration, wizard validation, literal-text preview, and the
review step at mobile width. No uncaught browser or console errors were observed.

## Practical limits

The backend does not provide reliable recipient delivery receipts or a complete
status-transition event log. The UI explicitly shows confirmation as unavailable
and shows only stored attempt summaries. Legacy records can lack attempt history.
Template search covers existing names/content; an authored category taxonomy was
not added to the schema. Existing advanced diagnostics retain some English text.
Fonts use IBM Plex Sans Arabic when installed, with local system fallbacks.

Screenshots are from isolated RTL fixtures at desktop/tablet/mobile widths, not
a claim of authenticated live-school, load, or actual device testing. Large-data
performance and exact hosting/plugin combinations still require staging checks.
