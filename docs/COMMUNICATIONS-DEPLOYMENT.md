# Communications deployment and rollback — 4.0.3

## Current state

All approved core A–D features are implemented in the existing plugin. Communications schema is **4**, with **28 dedicated tables**. Schema 4 repairs existing schema-3 installations that lack `action_items.request_hash`. Legacy SMS schema remains **2.5.1**. Main Communications, chat, attachments, actions and events default off on a new installation. Development did not change school accounts, upstream plugins, public pages, messages or SMS queues.

## School staging and pilot

1. Back up the database, plugin and any existing private files; verify a restore point.
2. Deploy the complete plugin directory. Let its additive migration run on WordPress init. Check schema health, including required unique indexes and InnoDB on shared campaigns. Existing non-InnoDB legacy tables are not silently converted.
3. Verify current Core/Users/School identity, enrollment, assignment and source-event contracts with school records. The Users integration remains explicitly `single_verified`; no multiple-identity API is fabricated.
4. Configure HTTPS, school timezone, regular system-driven WordPress cron and a small pilot account list. Set relationship freshness thresholds against the actual school synchronization cadence.
5. Grant the separate capabilities through OLAMA Users. Include events/actions/attachments capabilities for intended recipients, not just the main use capability. Grant employee publishing, inbox membership/management, moderation, audit and metadata reporting independently. No broad grants are automated.
6. Configure the private storage directory outside WordPress/document roots, or define `OLAMA_MSG_PRIVATE_STORAGE_PATH`. Review nginx/Apache aliases, other virtual hosts, symlinks, CDN rules and backup publication. Record the exact path-specific review in Communications settings. Confirm direct HTTP access cannot retrieve originals or variants. The application filesystem check does not establish web-server privacy by itself.
7. Install/configure ClamAV when required by school policy; optionally set `OLAMA_MSG_CLAMAV_SOCKET`. Choose required, if_available, or disabled deliberately. Legacy Office always requires a clean scan. Verify scanner outage behavior, PHP upload limits, directory ownership and original/preview generation. Nothing silently falls back to public uploads.
8. Configure service inbox members/history, response/resolution SLA minutes and school response hours. SLA minutes are elapsed-clock minutes; response hours inform senders without blocking after-hours messages.
9. Exercise private sends/uploads, removed membership, redaction, family ownership, action completion, deadline escalation, child-specific RSVP/acknowledgement, source reschedule/removal, stale revision rejection, reminder retries and authorized ICS using the intended pilot accounts.
10. Confirm actual Gateway/theme/mobile integration and existing SMS/payment-report/device behavior on school staging. Tests never send real SMS. Extend rollout only after these hosting and operational checks pass.

Enable only the intended flags. Event publication requires a finalized audience snapshot. Enabling a feature does not publish any draft or send messages by itself. Maintenance and durable jobs run on `olama_msg_communications_tick`, independently of the legacy SMS tick. Workers handle at most 20 jobs / approximately 20 seconds per tick, using bounded source/target pages. Configure cron; site visits alone are insufficient for reliable reminders.

## Verification already completed

Real WordPress, wpdb, dbDelta and MariaDB 10.6.23: 60 announcement assertions, 96 chat assertions and 93 remaining-suite assertions passed. Existing phone normalization/export, renewal-audience (22 cases), PHP SMS segmentation and JavaScript SMS segmentation passed. PHP/JavaScript syntax checks passed. Browser fixtures verified Arabic desktop/mobile views, administrator access and directory loading, separate child acknowledgement/RSVP, workflow completion, source-field locking, quiet preferences and actor-dependent controls. Real GD generated authorized private previews.

Tests used PHP 8.4.12 and fictional Core/Users/source/scanner records in disposable databases. They are not production throughput, actual PHP 7.4 execution, a real ClamAV installation, web-server alias review, multi-process load or real upstream/theme/device acceptance. Validate those deployment conditions on the actual host. No additional implementation stage is being reserved for them.

## Capacity checks

An initial staging target can be 100 simultaneous authenticated clients, 20-second foreground / 60-second background polling and 100-target fanout pages. Measure empty-poll latency, query counts, source reads, lock waits, PHP worker utilization and publication throughput before broad activation. These are suggested measurement conditions, not measured capacity claims. Current authoritative Users lookups are per actor because there is no approved upstream batch identity API.

## Retention and rollback

Retention defaults to retain. The optional policy offers a dry run followed by explicitly enabled archive-only execution for old closed published events, in batches of 100. It does not purge transcripts, audit, published attachments or action history. Expired unclaimed uploads are a separate private-file cleanup process.

Disable individual flags to pause a feature while preserving its evidence, or the main flag to stop access and new Communications processing. Pending durable jobs remain inspectable; after configuration is corrected, use the audited failed-job retry control. Feature flags never erase tables/files. Preserve private storage together with the database in backups.

Deactivation clears the internal cron hook. Prefer a forward fix. An older build must retain SMS channel guards whenever internal campaigns exist; unmodified legacy 2.5.1 is not a safe rollback against those rows. A full restore can lose changes made after the restore point. No destructive down migration exists.

Browser push, external chat delivery, complex recurrence, appointment booking, student chat, arbitrary groups and audio/video remain outside the approved core scope. In-app polling is not an emergency-delivery guarantee.
