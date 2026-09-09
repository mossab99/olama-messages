# Integrated Communications delivery — 4.0.2

This completes the remaining core C/D implementation in the existing Messages plugin. The original specification is constrained by the mandatory architecture amendment and final clarification. Features are independently gated and default off. No production messages, capability grants, public pages, or live rollout settings are created by the implementation.

## Private attachments

`Olama_Messages_Attachment_Service` provides validation, staging, transactional ownership, authorized metadata/downloads, image variants and cleanup. Files do not enter WordPress Media Library or public uploads. The local storage adapter implements `Olama_Messages_Attachment_Storage_Interface`.

The administrator configures `private_storage_path`, or the host sets `OLAMA_MSG_PRIVATE_STORAGE_PATH`. The directory must be outside both WordPress ABSPATH and the detected document root. Absolute canonical paths, generated storage keys, exclusive file creation and symlink checks apply. A path-specific `private_storage_reviewed_path` acknowledgement is mandatory: filesystem checks cannot prove that nginx/Apache aliases, another virtual host, a CDN or backup publication do not expose the directory. The settings checkbox records the administrator's infrastructure review; it does not perform that review. Health reports readiness and failures. No public fallback exists.

Allowed by default: JPEG, PNG, WebP, PDF, TXT, CSV, DOCX, XLSX and PPTX. Content is checked with fileinfo; raster dimensions and Office ZIP structure/size are checked separately. SVG, scripts, executables, arbitrary archives, video and audio are rejected. HEIC is explicitly unavailable. Legacy DOC/XLS/PPT require the separate legacy option and a clean malware scan.

Default limits: five attachments; 25 MiB combined; image 8 MiB, document 15 MiB, presentation 20 MiB. Settings retain byte-based server limits. PHP/web-server request and upload limits must also accommodate the intended upload size. Actor upload rate limiting applies independently of message rate limits.

The scanner interface is `Olama_Messages_Malware_Scanner_Interface::scan($path)`. Use the `olama_messages_malware_scanner` filter to provide an implementation. The bundled ClamAV adapter uses local INSTREAM, with `OLAMA_MSG_CLAMAV_SOCKET` optionally specifying a local TCP or Unix socket. Policies are `required`, `if_available` (default), and `disabled`. Infection/error states fail closed; unavailable scanning is accepted only under the configured permissive policy and never for legacy Office. Scanner availability is checked per upload; health does not claim that an uninstalled scanner is operational. A stricter policy set after staging is rechecked when claiming the file.

A committed `writing` staging record precedes filesystem creation. This lets cleanup find a PHP process interrupted mid-upload. Successful validation/storage produces `ready`. A send, campaign save or event save claims the files in its own transaction and produces `linked`. Claiming checks actor, authenticated WordPress account, scope, expiry, size, readiness and policy. Message retry hashes include sorted attachment IDs, preserving older text-only retry hashes. Ownership never leaks from a failed send.

Staged files expire after one hour; cleanup handles at most 30 records per tick, including interrupted/failed writes. Linked originals and historical inactive files are retained. Removed attachments are unavailable to normal readers; explicitly reasoned audit access can inspect retained evidence. Originals and thumbnail/display previews pass the same object authorization. Removed inbox members and redacted messages lose normal file access. Every download uses authenticated REST headers and private/no-store responses; no filesystem paths or public file URLs appear in normal JSON.

## Actions and service workflow

Actions have one actor owner or one service inbox, a source reference, title, priority, optional UTC due time, creator identity/account, optimistic version, started/resolved timestamps, resolution note and retained change history. States are open, in_progress, resolved and cancelled. Completion/cancellation requires a note. Manual creation retries use a stable client UUID and immutable request hash; changed payloads conflict. Reading or acknowledging a notice never completes its action.

Supported creation sources are authorized threads/messages and staff-created standalone work. Campaign deliveries and event targets create their own idempotent linked actions inside the fenced delivery transaction. Action-required campaigns require the actions feature; recipients without its capability are reported as unreachable for this purpose. A service action retains the thread's current access rules. Removed membership cannot be bypassed through the action list or notifications. Families do not receive authenticated staff WordPress IDs or hidden employee history details in action responses.

Staff can reassign standalone actions to verified eligible owners. Shared service requests retain manager-controlled assignment/unassignment and current-member self-claiming. Inbox actions belong to the inbox. Campaign/event recipient ownership is stable; it cannot be reassigned to a different family. Parent actions tied to a child recheck the current child context on mutation.

Service requests support open, in_progress and resolved. In-progress requests count as active under every inbox history policy and remain sendable. Each inbox defines response/resolution SLA minutes (defaults 1440/4320; zero disables that deadline). Deadlines use elapsed minutes, not a business-hours calendar. Reopening a resolved request starts a new numbered SLA cycle while retaining prior history. Bounded maintenance emits separate response/resolution overdue notifications to active eligible managers, once per cycle. It sends no SMS/email. General action overdue notifications are version-idempotent.

Configurable school response hours are informational; after-hours messages are still stored and sent. The client displays an expected-response-hours note. No presence or exact last-seen tracking exists.

## Events, sources and audience

Standalone events belong to Communications. Source events have a unique `(source_type, source_id)` and source version. Source adapters control title, existence, dates/timezone/all-day status and authoritative cancellation. Communications owns description/instructions, location, presentation/category/priority, publication, audience, files, reminders, acknowledgements and RSVP. Browser writes cannot override source-owned fields. School's adapter reads `Olama_School_Academic::get_event()`; no upstream write or synchronization mutation is invoked.

Register read-only sources through `olama_messages_event_sources`, returning objects implementing `Olama_Messages_Event_Source_Interface`. `read($source_id)` returns the normalized projection or **null only for authoritative removal**; throw when unavailable. A changed source creates a new retained projection revision. Missing/cancelled sources retain local evidence and invalidate future reminders. Outages retain the existing projection and record a visible sync error. Maintenance checks up to ten stale sources per tick, with a five-minute per-source minimum interval. Managers can also request reconciliation.

Event lifecycle: draft → preparing → prepared → published, then cancelled/completed/archived or source_removed. Published edits retain published status and increment content version. Published events cannot be deleted or reset. Unpublished preparation can be reset to a new snapshot; old jobs are invalidated. Draft deletion is available only before any publication.

Audience preparation uses the same bounded resolver as campaigns. Every preparation is a separately identified snapshot with a preparation window, source metadata and completion status. No publication occurs before finalization. Responses can be actor-level or per child. Child targets carry stable student UID and snapshot context; class/section restrictions are preserved. `(event,actor,context)` is unique. Explicit audience addition prepares a separate snapshot and releases only newly added targets; it does not silently replace the published audience. Current access and child eligibility are rechecked at delivery and response time. Targets with missing accounts or capabilities are not counted as unread.

Events store UTC; UI event entry accepts local date/time in the event's timezone and converts on the server. All-day events require local midnight boundaries and an exclusive end date. Calendar detail displays school-local dates. The native Arabic RTL calendar has month/list views and bounded pagination; it needs no external CDN.

## Versions, acknowledgements and reminders

`content_version`, `ack_required_version` and `rsvp_required_version` are separate. Immutable `event_revisions` preserve their exact pairing. Title-only changes preserve required-response versions. Time, timezone, all-day, location, instruction text, attachment changes or explicit critical changes advance the required versions when applicable. A critical event always requires acknowledgement.

Clients submit the displayed content version and its required-response version. The server verifies that exact pair against an existing revision, then compares the required version to the current event while holding its lock. Unknown/future pairs and stale major revisions fail with HTTP 409. An older minor revision can still be acknowledged when the required version is unchanged. Siblings have independent targets, acknowledgements and RSVP. No record claims which individual guardian read the material or that a human comprehended it.

Delivery/read are explicit client receipts, separate from sent, acknowledged, RSVP and action completion. Aggregate sent/delivered/read counts represent historical progress; current-version acknowledgement and RSVP use the separate required versions. Detail includes reachability, RSVP distributions and action-state statistics.

Each reminder is tied to event, content version and offset. Up to five offsets within 30 days are supported. Workers recheck current version/state, event time, finalized target membership, current entitlement and completion. Obsolete jobs have no effects; delayed pre-event reminders do not fire after the event begins. Cancellation/source removal/archival also cancels outstanding linked event actions, preserving their audit history. Previously resolved actions retain their completion evidence.

Completion providers implement `Olama_Messages_Completion_Provider_Interface::completion($event,$target)` through `olama_messages_completion_providers`. Return `complete`, `incomplete`, or `unknown`. A blank provider uses acknowledgement/RSVP relevance; `action` checks the associated action. Unknown or unavailable configured providers fail the job for retry instead of assuming incomplete. Providers must be read-only. No unverified finance/exam/booking API has been fabricated.

ICS export is authorized per event, with stable UID, sequence, escaped text, UTF-8-safe 75-octet folding, UTC timed events, exclusive all-day dates and cancellation status. It is a downloaded snapshot, not a public calendar subscription URL.

## Notifications, search, reporting and retention

Activity notifications store object references, not private text copies. Their list and receipts recheck current object access. The existing account/session/actor multi-tab coordinator also polls suite activity and critical obligations. Quiet hours suppress normal toasts/sound; previews and sound default off. Critical banners persist until each required acknowledgement, independent of mute or quiet preferences. Actor/session clearing removes preview dialogs and revokes object URLs. The notification center links to event/action/service activity; none of these channels invokes external messaging.

Search is limited to currently authorized chat content, excluding redacted messages. It requires a phrase and a bounded UTC window (30 days by default, maximum 90), with thread/sender/teacher/student/attachment filters and 30-result pagination. Privileged search additionally requires an employee audit capability, a specific thread and a recorded reason. No unrestricted global transcript search is exposed.

The dashboard requires a separate employee metadata capability and shows counts, workflow states, SLA breaches, response/resolution averages and job states. It has no private bodies or teacher ranking. Retention defaults to retain: an explicit staff dry run identifies up to 100 sufficiently old closed published events. An independently enabled policy permits audited archive-only execution. There is no transcript/audit/file purge or destructive down migration.

## Deployment boundary

Legacy SMS schema remains 2.5.1. Communications schema is 4 with 28 dedicated tables; migrations are additive, checked and repeatable. Schema 4 repairs the `action_items.request_hash` column omitted from an earlier installed schema-3 table. Initialization also performs a throttled structural repair if the stored version matches but health finds an interrupted or missed additive migration. No upstream business tables are written. New capabilities and flags are documented in COMMUNICATIONS-PERMISSIONS.md; infrastructure and pilot checks are in COMMUNICATIONS-DEPLOYMENT.md.

Local integration uses real WordPress/dbDelta/InnoDB with fictional Core/Users/source/scanner fixtures, plus real local image processing. These checks do not certify the school's nginx/Apache aliases, ClamAV installation, actual upstream/theme integration, PHP 7.4 runtime, concurrent hosting capacity or real SMS devices. Those are deployment acceptance checks, not deferred implementation stages. Browser push, audio/video, arbitrary groups, student chat, complex recurrence and appointment booking remain outside the approved core scope.
