# Consolidated architecture and release contract

Authority: master specification, architecture amendment, final clarification, and final review corrections, in that precedence order (latest wins). The supplied originals are design history; this document and the ecosystem map are the implementation contract.

## Release A

Official informational/acknowledgement notices only. Shared campaign metadata routes to a separate internal campaign service; the SMS service refuses non-SMS input. New additive InnoDB domain tables hold campaign configuration, target snapshots, deliveries, notifications, audit, and durable jobs. Legacy tables and URLs are preserved. Release flags default off.

Server-derived family/employee actors, explicit account capabilities, current business eligibility, finalized snapshots, bounded fanout, fenced jobs, unique deliveries, client-delivery receipts, explicit read/acknowledgement, truthful reach metrics, safe RTL frontend, gateway summary/entry point, and operational health ship together. Actor-scoped multi-tab polling belongs to A, not B. Configuration is available while frontend rollout is disabled. No automatic page creation or capability grants to families/staff.

## Release B

Implemented on the user's explicit second-stage instruction, behind the separate `chat_enabled=false` default. Development proceeded after A's local verification; A's real-school operational acceptance remains a gate for **enabling B**, not a claim that staging has already passed.

The read-only relationship provider joins current Core students/enrollment and academic mappings to School assignments, then verifies the canonical employee through Users and the current teacher role. Families choose a child; teachers can initiate contact with assigned students' families. Teacher-to-teacher and explicitly authorized administrative employee-to-teacher conversations use the same business-actor boundary. No global family directory, inferred identities, student chat or arbitrary groups.

Direct keys include both actors and server-derived student/year/section/subject/assignment context. Existing authorized history remains readable when a relationship expires; each new send/edit checks current sources. Successor teachers get distinct threads. Active verified families retain their own history after enrollment withdrawal; suspension or identity removal still wins.

Messages are plain text with immutable request hashes and canonical-actor UUID idempotency. Delivery and read cursors remain separate and monotonic. Manual unread does not reverse receipts. Edits retain revisions; redaction retains evidence and removes quoted previews. A commit-ordered change sequence covers edits, receipts, redactions and service actions. The existing actor/session polling coordinator carries metadata-only hints; message bodies are fetched under current authorization.

Shared inboxes have explicit active membership, manager capabilities, first-view/first-response state, assignment history and basic resolve/reopen. First reply to an unassigned request claims it atomically. Removed members immediately lose server access even if they authored messages. History policies apply to content, listing, feed, receipts and actions. Moderation, restrictions and audited privileged access ship with chat.

## Integrated remaining core

| Release | Scope | Gate |
| --- | --- | --- |
| C | Private attachments and variants, scanner policy, actions, action-required campaigns, SLA | B private access and moderation verified |
| D | Source-aware events, calendar, content/ack/RSVP versions, reminders, source reconciliation, ICS | Exact-version acknowledgement and cancellation tests |

The A-only flag state exposes notices; B additionally installs its additive tables but requires explicit chat enablement and capabilities. C/D are now implemented together in 4.0.1; attachments, actions and events each require their own flag and capabilities. See [the integrated suite contract](COMMUNICATIONS-SUITE.md) for the current implementation, exact version rules, source interfaces and deployment boundaries.

## Invariants across releases

Account is not actor; actor is not capability; target snapshot is not current entitlement; notification persistence is not delivered; delivered is not read; read is not acknowledgement or completion. Family acknowledgement never identifies the individual guardian. Account suspension overrides messaging access; chat restrictions do not block notices or authorized history.

Published evidence cannot be permanently deleted through normal UI. Cancel stops pending work but retains delivered evidence; archive retains all evidence. Only never-published drafts may be deleted. UTC for all new timestamps; legacy timestamp semantics remain unchanged.

Jobs use leases and ownership tokens. Domain side effects must execute while the job ownership and campaign row are locked, not merely check the job token when setting completion. Unique business keys independently prevent duplicate deliveries/notifications. Cancellation and authorization are rechecked before effects.

Browser caches/polling are scoped to account, session and selected actor. Tab contexts are independent. Stale asynchronous responses are discarded after switching. No cross-identity aggregate feed, presence tracking or last-seen UI.

In D, validate displayed version pairs against immutable server-owned revisions, reject unknown/future pairs, and atomically compare acknowledgement-required version during acknowledgement. Recording the displayed version does not prove human comprehension. Content edits, RSVP reconfirmation and reminder invalidation have separate policies.

## Release A acceptance matrix

- Migration repeatability and preservation of legacy rows.
- Invalid/suspended identity denied; family cannot inherit employee powers.
- SMS service and dispatcher never process internal campaigns.
- Partial preparation cannot publish; retries do not duplicate targets.
- Draft deletion allowed, published deletion denied, cancellation retains evidence.
- Expired worker token cannot write effects; retries recover without duplicate notices.
- Delivery/read/ack remain separate and authorized by current actor/object entitlement.
- Notifications and caches never cross actor context; no user-supplied HTML rendering.
- Flags disable exposure; health identifies failed work; audit records privileged changes.
- Existing PHP/JavaScript regression suites pass; remaining staging/load acceptance is stated explicitly.
