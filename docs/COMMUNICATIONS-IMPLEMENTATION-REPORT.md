# Communications implementation report — 2026-09-09

## Integrated delivery: version 4.0.1

All remaining approved core C/D stages are now implemented together, preserving the existing A/B work and legacy SMS boundaries. New feature flags default off. No real school messages, external SMS, accounts, role grants or public pages were created during implementation.

Delivered:

- Private attachments for messages, campaigns and events; MIME/size/Office validation, configurable malware policy, private image previews, atomic ownership and interrupted-upload cleanup.
- Required actions and action-required campaigns; assignment, in-progress/resolve/cancel/reopen workflow, version conflicts, completion notes and history.
- Service response/resolution deadlines, manager escalation, workflow metrics and configurable informational office hours.
- Standalone and School-source events, immutable audience snapshots, explicit audience additions, separate child responses and current eligibility checks.
- Independent content/acknowledgement/RSVP versions, major/minor edit rules, source reconciliation, versioned reminders, completion providers, retained cancellation and private ICS export.
- Arabic RTL month/list/detail calendar, action and management screens, notification preferences, persistent critical obligations and existing-coordinator polling.
- Permission-aware bounded search, metadata dashboard, archive-only retention preview/execution, capabilities and deployment documentation.

Communications schema **4** has 28 dedicated tables. The 4.0.1 upgrade repairs an existing schema-3 `action_items` table missing `request_hash`, and settings save retries additive schema repair before refusing configuration. Legacy schema remains **2.5.1**. No upstream business table is written. See [the integrated suite contract](COMMUNICATIONS-SUITE.md) and [deployment notes](COMMUNICATIONS-DEPLOYMENT.md).

## Verification

**243 Communications integration assertions passed:** A 57, B 93, C/D 93, using real WordPress/wpdb/dbDelta/InnoDB and fictional upstream records. Coverage includes private original/thumbnail authorization, scanner failure policy, interrupted staging cleanup, attachment-only retry hashes, removed membership, SLA cycles, child isolation, stale/future revision pairs, minor edits, source outages/removals, cancellation, unknown completion providers, 150-target paged fanout and target pagination.

Existing PHP phone normalization, Google Contacts export, renewal audience (22 cases), SMS segmentation and JavaScript SMS segmentation checks passed. PHP and JavaScript syntax checks passed. Browser fixtures verified Arabic calendar/list/detail, staff controls hidden for family actors, separate sibling acknowledgement/RSVP, action completion/history, School field locking, quiet preferences, literal script text and 390px layout without horizontal overflow. Private preview generation used real GD.

The isolated database and browser test services are stopped after verification. Tests use disposable databases/private files and do not load school wp-config or real users. Development runtime was PHP 8.4.12 / MariaDB 10.6.23. Actual PHP 7.4, school Core/Users/School/Gateway/theme integration, hosting concurrency/cron, web-server alias privacy, installed ClamAV and real SMS device acceptance remain deployment checks. No claim of production rollout or measured production capacity is made.

The post-core exclusions in the approved architecture remain excluded: push/audio/video, arbitrary groups, student chat, complex recurrence, advanced booking and speculative upstream business integrations.

## Historical delivery: Release B, version 3.1.0

Stage B is implemented inside the existing plugin, preserving Release A. Its separate `chat_enabled` flag defaults **off**. This development session did not enable school rollout, modify upstream plugins, create school messages or send SMS. The user's second-stage instruction authorized development; actual A/B staging acceptance remains required before enablement.

Delivered:

- Family ↔ assigned teacher (either side can initiate), teacher ↔ teacher, and authorized administrative employee ↔ teacher chat. Child-first family contacts and assignment-limited teacher family directory; no global family directory or fabricated identity mappings.
- Read-only Core/Users/School relationship provider with per-domain freshness, current Core subject/section checks, canonical employee IDs and explicit failure states. Historical read-only behavior and successor-teacher isolation.
- Plain-text messages, quoted replies, immutable request hashes and UUID retry deduplication; 5000-character default limit and configurable 15-minute own-edit window with retained revisions.
- Independent sent/delivered/read semantics, monotonic cursors, manual unread, archive/mute/pin, composite keyset lists, commit-ordered change feed and bounded unread reconciliation.
- Service inbox configuration, membership, four history policies, separate department first-view/response state, assignment history, first-reply claim, manager assignment/unassignment and basic resolve/reopen.
- Reports and moderation queue, audited scoped content access, retained redaction/revisions, scoped temporary restrictions with immediate request-time expiry, and canonical-actor rate limits.
- Arabic RTL desktop/mobile chat, receipts, inbox/report/restriction screens, generic muted-aware alert hints, account/session/actor polling coordination and short-lived draft cleanup.

New B modules: `chat-schema`, `relationship-provider`, `chat-policy`, `chat-service`, `service-inbox-service`, `moderation-service`; dedicated chat REST controller; `assets/communications-chat.js`; isolated B integration/browser fixtures. Communications schema **2** adds 12 tables to A's six. Legacy schema remains **2.5.1**. The general health screen exposes audit metadata, not private moderation reasons or transcript bodies.

Verification: **93 B integration assertions** and **56 A integration assertions** passed on real WordPress/`wpdb`/`dbDelta` with MariaDB 10.6.23 and fictional business records. Existing PHP phone normalization, phone-book export, renewal audience (22 cases), SMS segmentation and JavaScript SMS segmentation checks passed. PHP lint and JavaScript syntax checks passed. Browser checks covered simultaneous isolated employee/family tabs, staff-control hiding, literal HTML/script text, sending/editing, draft cleanup after actor switch, inbox configuration, and 390px layout without horizontal overflow. The mobile launcher/composer overlap and primary-button hover contrast were corrected.

Still required before school rollout: actual Core/Users/School/Gateway/theme acceptance, the school's sync cadence and freshness settings, PHP 7.4 execution if deployed, concurrent multi-process/hosting load, cron behavior and school-approved SMS device regression. Tests used PHP 8.4.12; no production throughput or upstream multi-identity API is claimed. At that historical checkpoint, attachments, actions/SLA and events were still C/D work; they are included in 4.0.1 above.

See COMMUNICATIONS-DEPLOYMENT.md for pilot enablement and rollback, and COMMUNICATIONS-TESTING.md for reproducible checks.

## Historical Release A delivery

Release A was delivered as **3.0.0** with internal functionality disabled. The sections below record that delivery; current B behavior and rollout status are described above.

## Delivered

- Verified family/employee identity adapter and explicit `single_verified` fallback; no manufactured multi-identity relationships.
- Account + actor + current-eligibility permission boundary, registered Users capabilities, pilot account restriction.
- Internal informational/acknowledgement campaigns: draft/edit, batched prepare, finalized snapshot, immediate/scheduled publication, cancellation, archive, draft deletion, audited delivery retry.
- Independent internal deliveries and notifications, client delivery acknowledgement, read receipts, explicit family-account acknowledgement and separate notification-seen state.
- Arabic RTL notice feed, notification center, badges/toasts, actor/session-scoped multi-tab coordination, mobile editor and basic operational UI.
- Gateway provider/render-hook integration and `[olama_communications]` shortcode.
- Durable fenced jobs, transactional side effects, backoff/reclaim, unique fanout keys, schema/identity/job health and audit.
- Explicit SMS channel guards across legacy lookup, preview, listings, dispatcher and aggregate administrative counts.

## Files

New service files in `includes/communications/`: `class-olama-messages-communications-db.php`, `class-olama-messages-actor-resolver.php`, `class-olama-messages-communication-policy.php`, `class-olama-messages-audience-resolver.php`, `class-olama-messages-job-service.php`, `class-olama-messages-internal-campaign-service.php`, `class-olama-messages-notification-service.php`, `class-olama-messages-communications.php`.

New controller: `includes/rest/class-olama-messages-communications-rest-controller.php`.

New assets: `assets/communications-app.js`, `assets/communications-app.css`.

New tests: `tests/communications-bootstrap.php`, `tests/test-communications.php`, `tests/communications-browser-fixture.js`.

Modified: `olama-messages.php`, `includes/class-olama-messages-plugin.php`, `includes/class-olama-messages-activator.php`, `includes/class-olama-messages-campaign-service.php`, `includes/class-olama-messages-dispatcher-service.php`, `includes/class-olama-messages-operations-service.php`, `admin/class-olama-messages-admin.php`.

Documentation: ecosystem map, consolidated architecture, database, permissions, integration, deployment, testing and this report under `docs/COMMUNICATIONS-*.md`.

## Database and integration surface

Six additive tables: `olama_msg_internal_campaigns`, `olama_msg_campaign_targets`, `olama_msg_internal_deliveries`, `olama_msg_notifications`, `olama_msg_jobs`, `olama_msg_audit_log`, all with the installation's WordPress prefix. Existing campaign schema is reused unchanged; internal rows are channel tagged. No existing table is dropped/renamed or has a column definition changed.

New REST routes are listed in COMMUNICATIONS-INTEGRATION.md. They are all below `olama-messages/v1/communications`. Capabilities: `olama_messages_use`, `olama_messages_manage_campaigns`, `olama_messages_configure`. Existing `olama_access_messages` stays intact.

Rollout option `olama_msg_communications` contains `enabled` (internal official notices), `notifications`, `pilot_users`, `poll_seconds`. New cron hook: `olama_msg_communications_tick`. New admin entry: OLAMA Communications, with a separate settings submenu. No production pages or accounts are automatically created.

## Verification

- **56 integration assertions passed** at the Release A checkpoint using real WordPress database/migration/REST APIs and MariaDB 10.6.23, with fictional Core/Users records in disposable databases. The current integrated A suite has 57 assertions, including the unmapped-identity UI diagnostic.
- Existing PHP phone normalization, phone-book export, SMS segmentation and renewal-audience suites passed (renewal suite: 22 cases).
- Existing JavaScript SMS segmentation suite passed.
- PHP lint passed for all changed/new PHP files; JavaScript syntax check and Git whitespace checks passed.
- Browser fixture: safe plain-text rendering, explicit acknowledgement, separate family/employee tabs, staff controls hidden in family context, desktop layout and 390px mobile layout/editor with no horizontal overflow.

Development PHP: 8.4.12. No claim of testing the unavailable PHP 7.4 runtime or production throughput. No real SMS/device send was performed. Upstream business records were fixtures, so actual Core synchronization/capability/Gateway-theme acceptance remains a staging step.

## Scope and safety

No MongoDB, translation, WhatsApp integration, public attachments, video, parent-parent chat or student direct chat introduced. Attachments/chat/events/actions are not exposed in A. Authorization is server-side. Legacy SMS and payment-report implementations are retained; channel isolation and existing regression tests pass, with physical-device verification remaining for staging.

Current audience UI covers verified general/class/section/family, employees, selected actors and existing Core finance/transport/renewal audiences. Advanced teacher/subject/route targeting and richer internal templates require additional verified contracts. In-app delivery cannot guarantee emergency reach.

Follow COMMUNICATIONS-DEPLOYMENT.md for backups, migrations, pilot enablement, cron and rollback. Do not run unmodified legacy 2.5.1 code against internal campaigns without preserving channel guards. B development is now present; enabling B still requires the real staging gate.
