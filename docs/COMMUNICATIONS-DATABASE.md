# Communications A–D database

Plugin version 4.0.3. Legacy schema version remains 2.5.1 (`OLAMA_MSG_LEGACY_DB_VERSION`). Independent Communications schema version is **4**, option `olama_msg_communications_db_version`. Schema 4 adds the immutable manual-action `request_hash` missing from an earlier schema-3 installation.

All new tables use the active WordPress prefix, InnoDB, utf8mb4 and UTC timestamps. Actor keys use binary collation because opaque business IDs must not collapse by case. No foreign keys, file blobs, or changes to existing column definitions.

| Suffix following `olama_msg_` | Purpose / uniqueness |
| --- | --- |
| internal_campaigns | Additional internal-only campaign configuration; campaign ID primary key. Snapshot ID, preparation cursor/window, completion flag, source metadata, publication/schedule, accountable actors. |
| campaign_targets | Immutable membership/name/context per campaign + snapshot + actor. Mutable reachability is explicitly timestamped and does not change membership. |
| internal_deliveries | Immutable rendered official notice; unique target ID; independent sent/delivered/read/ack timestamps. |
| notifications | Unique delivery ID; actor-scoped incremental ID, delivery and notification-seen timestamps. Preview title is joined at read time, not copied to an unprotected cache. |
| jobs | Unique deterministic job key; due time, attempts, lease/token, retry/failure/completion. |
| audit_log | Business actor + actual WP account, action/object and safe metadata. No private message-body logging. |
| threads | Unique deterministic direct key or requester/inbox/client UUID service key. Context snapshot, status, assignee, department timestamps, last message ID. |
| thread_participants | Unique thread + actor; name snapshot, monotonic delivery/read cursors, cached unread, independent unread/archive/mute/pin, last participation. |
| messages | Unique canonical sender + client UUID; immutable normalized-request hash, actual authenticated WP ID, name, text, reply ID, sent/edit/redaction timestamps. |
| message_revisions | Retained previous content with responsible actor and WP account. Accessible only through audited dedicated routes. |
| service_inboxes | Name, contact rules, employee-display option, active flag and explicit history policy/window. |
| inbox_members | Unique inbox + employee actor, active flag and manager flag. Removal deactivates membership, not authored replies. |
| thread_actions | Assignment/resolve/reopen history with actual employee, WP account and target. |
| restrictions | Subject actor or `*`, type, scope, optional target, private reason and start/end/revocation UTC times. |
| reports | Unique reported message + reporter; reason, queue state and resolution note. |
| chat_changes | Incremental metadata-only change IDs; thread and change kind, no message content. |
| chat_clock | One transactional sequence row serializes change IDs into commit order. |
| chat_rate_limits | Unique hashed actor/action/window bucket, atomic count and expiry. |

B adds 12 tables to A's six; C/D add ten tables below, for **28 Communications tables** in total. No upstream table is written. Read access to Core goes through its public read-model table whitelist; the School compatibility adapter isolates its existing assignment/section/subject table dependencies.

Chat writes lock the thread and relevant inbox/membership before mutation. Current locking membership reads prevent an earlier InnoDB snapshot from retaining revoked membership. The short change-clock lock is acquired after domain changes and held to commit, preventing a late commit with a lower feed ID from being skipped. UUID uniqueness is independent of this clock. Reads never imply receipts; explicit client receipt writes validate the cursor's thread.

Lists use `(pinned,last_message_id,thread_id)` keysets (30), messages use ID keysets (50), and feed pages are capped at 100. Cached unread counts are repaired in 100-participant batches on the existing tick; authorization-filtered count reads calculate from receipts/messages to remain correct for newly eligible inbox members. This favors correctness; measure aggregate count/relationship joins under actual hosting load before broad activation.

`olama_msg_campaigns` retains its existing definition; new rows use channel `internal`. The existing SMS handler and dispatcher explicitly filter channel `sms`. No internal rows go into SMS recipients, queue, payment tokens, or short links.

Transactions check write results and roll back on failure. The worker locks its job by current ownership token, then locks the campaign, then writes a bounded batch and its continuation before commit. Cancellation locks only the campaign, avoiding inverted job/campaign lock ordering. A stale worker cannot enter the side-effect handler. Repeated fanout uses unique delivery/notification constraints.

Snapshots represent the preparation window (Core exposes no stable transactional generation ID). Up to 100 source rows are handled per job; repeated target keys are deduplicated. Reset starts a new snapshot and retains obsolete unpublished snapshot rows for diagnosis until draft deletion. No publication while preparing/failed. Reachability can be refreshed by audited retry-delivery; existing delivery bodies are never rewritten.

Schema health checks table existence, required columns, InnoDB and required unique-index names. Its result is cached for 60 seconds for polling, while the health screen forces a fresh check. Migrations only advance the schema option after validation. They never automatically convert a legacy non-InnoDB table.

## C/D tables and additive columns

| Suffix | Purpose / uniqueness |
| --- | --- |
| attachments | Staged/linked private-file metadata, uploader account and actor, scope, checksum, scan status, private storage key and variants; no binary columns. |
| action_items | Stable business key and immutable manual-request hash; owner actor OR inbox; source, status, due time, versions and completion timestamps. |
| action_history | Retained versioned changes with actor/account attribution. |
| events | Unique source type/ID; projection, timezone, lifecycle, content/ack/RSVP versions and publication metadata. |
| event_audiences | Unique snapshot UUID; preparation cursor/window, source metadata and completion status. |
| event_targets | Unique event/actor/context hash; optional student UID; immutable audience membership; independent delivery/read/ack/RSVP versions. |
| event_revisions | Unique event/content version; immutable normalized content and required-response version pairs. |
| event_reminders | Unique event/content version/offset; due time and processing state. |
| activity_notifications | Unique actor/object/version/kind business hash; authorized-at-read references and separate delivered/seen timestamps. |
| communication_preferences | Unique actor; quiet interval and opt-in previews/sound. |

`internal_campaigns.workflow_json` stores action deadline/priority. Inboxes add response/resolution SLA minutes. Threads add numbered SLA cycles and separate escalation markers. All additions preserve existing legacy semantics. Event/action work locks the source before dependent action rows; workers retain the job-token fence. Cancellation invalidates old reminder jobs by domain state/version instead of taking job locks in reverse order. File staging is committed before filesystem writes; published ownership is claimed in the caller's transaction.
