# Communications A–D verification

## Automated integration

`php tests/test-communications.php`

Uses the installed WordPress runtime and real `wpdb`/`dbDelta`/InnoDB transactions against a **new random disposable database**, named `olama_comm_test_<12 hex characters>`. The harness never loads the site's wp-config, active plugins, existing users, or data. Core/Users contracts are test fixtures. Network/email are disabled. Its shutdown handler drops only the exact generated test database.

Provide `OLAMA_TEST_DB_HOST`, `OLAMA_TEST_DB_PORT`, `OLAMA_TEST_DB_USER`, `OLAMA_TEST_DB_PASSWORD` for a disposable development database server with create/drop database permission. Defaults target the implementation's isolated localhost test server on port 13387, not the school database. Never point this harness at production infrastructure.

The suite passes **60 assertions**. It checks Hub card discovery; repeated migrations/legacy row preservation; channel isolation; incomplete snapshots; batching and deduplication; event-free informational/acknowledgement purposes; draft/published retention rules; exact actor authorization; current eligibility; suspended accounts; independent receipt states; audit idempotency; stale worker fencing; rollback/retry; cancellation after reservation; nonce/REST permission; pilot isolation; disabled flags; missing unique-index detection/repair; and distinct UI diagnostics for a disabled site, pilot exclusion and an authorized account without a verified OLAMA identity.

## Legacy checks

```
php tests/test-phone-normalizer.php
php tests/test-phone-book-exporter.php
php tests/test-renewal-audience.php
php tests/test-sms-segmentation.php
node --test tests/sms-segmentation.test.js
```

Run PHP lint across modified/new PHP files and `node --check assets/communications-app.js`. No package install, Composer or frontend build pipeline is required.

## Browser fixture

`node tests/communications-browser-fixture.js` serves a local-only fixture at `http://127.0.0.1:13388`. This Node server is a test tool, never production infrastructure. It loads the actual CSS/JS with fictional records and two simulated authorized identities; it does not establish a real Users multi-identity API.

Checks performed: desktop Arabic notice list/detail, literal script text rendered safely, explicit acknowledgement UI, family switching hides staff controls/content, independent family/employee tabs, 390px responsive list/editor with no horizontal overflow. The production multi-identity mode remains unavailable until Users provides the authoritative contract.

## Remaining acceptance before production

- Real Core/Users/Gateway data and capabilities, including full theme/plugin integration.
- Production PHP 7.4 if still in use; actual minimum-version runtime was not available in this development session.
- Concurrent multi-process load, hosting worker limits, real cron behavior and campaign throughput.
- School-approved actual SMS device regression; no real SMS was sent by tests.
- Security/access review with pilot accounts and verification of current source synchronization behavior.

Passing local tests does not authorize all-family activation or waive the Release A staging gate.

## Release B automated verification

`php tests/test-chat.php` uses the same disposable WordPress/MariaDB harness. **96 assertions pass**. Fixtures model the explicit WordPress administrator actor, current Users identities, Core enrollment/student/employee and academic read models, and School's actual assignment/section/subject columns; no live school records are loaded.

Coverage includes defaults/migration repeatability, family and teacher initiation, child-scoped contacts, canonical mappings, stale/missing sources, Core subject removal overriding a stale School mirror, employee/family capability boundaries, UUID retries before/after edits, text limits, edit expiry/revisions, quote boundaries/redaction, delivery/read/manual unread, successor and withdrawn-enrollment history, report scope/audited content access, restriction expiry and notice independence, service ownership and all four history policies, removed-member content/send/receipt/feed denial, mute hints, pin/keyset order, rate limits, unread repair, REST nonce/actor/no-store checks, and zero SMS queue effects.

Run `node --check assets/communications-chat.js` alongside the A checks. The browser fixture additionally loads the real chat assets through `communications-chat-browser-fixture.js`; it contains fictional UI responses, not a substitute for backend authorization tests. Verified sending/editing, safe literal HTML, independent family/employee content in simultaneous tabs, draft deletion on actor switch, admin inbox form and 390px layout. The floating launcher no longer overlaps the bottom send control; primary hover contrast is preserved.

This is local sequential integration plus browser verification, not a claim of concurrent multi-process stress testing. Measure aggregate unread queries, relationship joins, assignment changes and lock waits with actual hosting/pilot accounts before broad rollout. Verify real hidden-tab/device receipt behavior and real upstream synchronization cadence in staging.

## Remaining-suite verification

`php tests/test-suite.php` passed **93 assertions**. Together with A (60) and B (96), this is **249 Communications integration assertions**. The fixture uses real GD for image variants, actual Office-capable PHP dependencies, real SQL transactions, a controllable scanner interface and a read-only source fixture. It does not certify an installed production scanner or arbitrary Office content.

Coverage: reviewed/outside-web-root storage, MIME spoofing, blocked formats, scanner infection/outage, staged ownership, attachment-only retries/conflicts, removed member download/search denial, restricted claiming rollback, deadlines/escalation/reopen cycles, independent per-child acknowledgements and RSVP, future/stale/mismatched pairs, minor edits, source outage/removal, action history, private ICS and all-day dates, interrupted uploads, office hours, 150-target fanout continuation and full per-actor target pagination. Read, acknowledgement and completion remain distinct.

The browser server also includes `communications-suite-browser-fixture.js` and loads the real suite script. CUA checks exercised Arabic month/list/detail, child acknowledgements and RSVP, School source field locking, action completion/readable history, quiet preferences and a persistent critical banner. At 390px there was no horizontal overflow. The viewport was reset afterward. Binary uploads and authorization are covered in PHP; the browser mock is not a real authenticated WordPress/ClamAV deployment.

Run `node --check assets/communications-chat.js` and `node --check assets/communications-suite.js` alongside the base script check. No new production package installation/build step is needed. Stop the localhost browser fixture and isolated database service when finished.
