# Communications A–D integration

Read the ecosystem map for actual upstream ownership and supported APIs. No upstream plugin files are changed.

## Parent shell

Messages provider: `olama_student_gateway_messages_data($data,$context)` returns safe counts only after validating `family_uid` against current actor. `olama_student_gateway_render_messages` embeds the full official notice interface. The gateway already requires its own messages-view capability; grant that separately when appropriate. `[olama_communications]` embeds the same interface on an existing page. No pages are created automatically.

The global launcher/polling script loads for logged-in mapped, permitted actors on OLAMA shortcode/admin pages only. Additional trusted portal shells can opt in through `olama_messages_is_portal_page`. Public payment-report pages do not match this default. Standard non-OLAMA pages and anonymous users do not load it.

## REST namespace

All new routes live below `olama-messages/v1/communications`, separate from legacy agent endpoints. Send cookie authentication, `X-WP-Nonce`, and optional validated `X-Olama-Actor`.

| Route | Method / behavior |
| --- | --- |
| me | GET verified actor, available actors, fallback mode, management eligibility |
| notices | GET newest 30; `before` keyset pagination |
| notices/{id} | GET authorized full plain-text content; does not mark read |
| notifications | GET up to 50; `after`, `before`, `unseen=1` |
| counts | GET unread notices and unseen notifications separately |
| receipts | POST `{kind,ids}`; delivery IDs, at most 100; kind delivered/read/acknowledge/seen |
| campaigns | GET paged internal list; POST draft |
| campaigns/{id} | GET metadata and reach/receipt statistics; POST draft edit |
| campaigns/{id}/{command} | POST prepare/publish/cancel/archive/reset/delete/retry_delivery |
| health | GET bounded diagnostics/audit and failed work |
| jobs/{id}/retry | POST audited failed-job retry |

Publish accepts optional `scheduled_at_utc` in `YYYY-MM-DDTHH:mm` format. Draft body supports plain text and `{recipient_name}`; no SMS placeholders or public payment token generation. Internal templates beyond this merge field are a future extension, not a silently reused SMS template.

## Supported audience contract

`type`: general, employees, selected, collection, transportation, renewal_reminder. `study_year` defaults to current Core context. General supports verified Core `class_id`, `section_id`, `family_id` filters. Selected supports up to 1000 exact canonical `actor_keys`. Other unknown filters are rejected. All finance/transport/renewal eligibility uses Core's public audience methods, not SMS phone validation. Unsupported teacher/subject/route-specific selection needs a verified provider extension; it is not exposed by this A interface.

Publication is one-way and snapshots title/body/targets. Target data is not implicitly refreshed after publication. Audited retry-delivery refreshes reachability and delivers only missing eligible targets. Browser push, events and arbitrary business-event integrations remain later release work.

## Release B REST

Same namespace/session/nonce/actor headers, additionally gated by `chat_enabled` and `olama_messages_chat`. Services enforce relationship, membership or dedicated privileged capability. GETs never mark chat read.

| Route | Contract |
| --- | --- |
| chat/contacts | GET child-first family or staff contacts: `student_uid`, staff `after`. Teachers can use `directory=assigned_families`, `after_student`/`after_assignment`. |
| chat/threads | GET 30, optional `archived=1`, `inbox_id`, composite `cursor` from `page_cursor`. POST direct `{target,context}` or service `{kind:"service",inbox_id,subject,client_thread_id}`. |
| chat/threads/{id} | GET 50 messages, `before`; send policy, personal settings, direct receipts or department state. |
| chat/threads/{id}/messages | POST `{body,client_message_id,reply_to?}`. Version-4 UUID; default max 5000 text characters. |
| chat/threads/{id}/receipt | POST `{kind:"delivered"|"read",cursor}`. Cursor must belong to thread. |
| chat/threads/{id}/preferences | POST booleans `archived`, `muted`, `pinned`, `manual_unread`. |
| chat/threads/{id}/{assign,resolve,reopen} | POST service action. Assignment takes canonical `target`; managers may clear assignment. |
| chat/messages/{id}/edit | POST own text within configured window, default 15 minutes. |
| chat/messages/{id}/report | POST reason; one report per actor/message. |
| chat/feed | GET `after` sequence, up to 100 metadata-only changes/high-water cursor and authorized unread count. Alert hints respect mute/archive. |
| chat/inboxes | GET authorized contact/member inboxes (50), `admin=1` for manager configuration; POST new configuration/members. |
| chat/inboxes/{id} | POST full configuration and active-member replacement, with audit. |
| chat/reports | GET moderator queue, status/`before` (30). POST `chat/reports/{id}` with status/note. |
| chat/context | POST audited `{report_id,reason,before?}` for moderator, or `{thread_id,reason,before?}` for dedicated auditor. |
| chat/messages/{id}/revisions | POST audited reason, optional report scope/`before`; 30 revisions. |
| chat/messages/{id}/redact | POST `{report_id,reason}`, scoped to reported conversation. |
| chat/restrictions | GET paged private moderator records; POST actor/type/scope/target/private reason/start/end UTC. |
| chat/restrictions/{id}/revoke | POST audited revocation. |

Chat uses A's account/session/actor polling coordinator. Generic alert hints contain IDs/kinds, no content previews. Opening/refreshing a visible conversation fetches authorized text and acknowledges delivery/read. A toast never marks read. Every open refresh rechecks access even with no new message. Draft text is stored in tab session storage with 15-minute expiry and cleanup on actor switch, logout/auth failure and page exit; transcripts are not persisted.

The notice notification center retains A's notice/seen semantics. Chat has a separate unread badge, optional generic toasts and conversation list. No uploads, forwarding, arbitrary groups, WebSocket, browser-push server or external notification provider is enabled.

## Integrated suite REST

All routes below are relative to `olama-messages/v1/communications/suite/`, use the same authenticated nonce/actor headers, and send private/no-store responses.

| Route | Behavior |
| --- | --- |
| me | GET feature/capability availability and upload limits. |
| feed | GET authorized activity, critical obligations and actor notification preferences; uses the existing polling coordinator. |
| activity | GET bounded cursor list (`before`); POST `{kind: delivered or seen, ids}`. |
| preferences | GET/POST quiet interval in school-local minutes, previews and sound. |
| attachments | POST multipart `file`, `scope_type` (thread/campaign/event), `scope_id`; PHP-uploaded files only. |
| attachments/{id} | GET authorized metadata only. |
| attachments/{id}/download | GET authenticated stream, optional `variant=thumb|preview`, otherwise original. Reasoned privileged audit is supported. |
| actions | GET `before`/optional `thread_id`; POST authorized source, title, owner, priority, due time, `client_id` UUID. |
| actions/{id} | GET detail/history; POST current `version`, status and resolution note; scoped assignment/deadline changes. |
| events | GET calendar window `from`/`to`, optional admin and after cursor; POST draft. |
| events/{id} | GET detail/current revisions/own targets; `target_after` paginates large actor target sets. POST versioned draft or published edit. |
| events/{id}/{command} | POST prepare/publish/add_audience/reset/cancel/complete/archive/delete/sync, with state and authority checks. |
| events/{id}/ics | GET authorized snapshot download, never a public subscription URL. |
| event-targets/{id} | POST `kind`, displayed `content_version`, and for ack/RSVP the exact `required_version`; RSVP also sends yes/no/maybe. |
| search | POST bounded phrase/date filters and optional thread/sender/teacher/student/attachment criteria. Audit search requires thread and reason. |
| dashboard | GET employee metadata-only operational reporting. |
| retention | POST dry run by default; `{execute:true}` requires the archive policy to be explicitly enabled. No deletion. |

Chat message sends optionally accept `attachment_ids`, including attachment-only messages. Campaign draft saves accept attachments and `purpose=action_required`, `action_due_at_utc`, `action_priority`. Inbox configuration accepts `response_sla_minutes`/`resolution_sla_minutes`; `/chat/threads/{id}/start` marks in-progress. Existing text-only retries retain their original hash format.

Event input can use UTC `starts_at_utc`/`ends_at_utc` or local `starts_at_local`/`ends_at_local` with the event timezone. All-day end dates are exclusive. Required response versions are server-derived; the client cannot force them. Source-owned fields are replaced by the registered source projection.

## Read-only source, scanner and completion interfaces

See [COMMUNICATIONS-SUITE.md](COMMUNICATIONS-SUITE.md) for contracts and failure semantics. Extension hooks are `olama_messages_event_sources`, `olama_messages_completion_providers`, and `olama_messages_malware_scanner`. Implement their declared PHP interfaces; do not write School/Core/Users business state from a projection or completion callback. Null source reads mean authoritative removal, exceptions mean unavailable. Unknown completion means retry, never permission to send blindly.

Background routing is `Olama_Messages_Suite_Operations::handle_job`; its domain handlers execute inside the existing job transaction. Internal action creation and activity emission are caller-transaction APIs, not standalone external webhook endpoints. Register future modules through these explicit contracts; no speculative upstream payment/exam/booking mutations are present.
