# Communications A–D permissions

Declare capabilities through OLAMA Users under the OLAMA Communications module:

- `olama_messages_use`: authenticated, active, mapped family/employee can use enabled official notices.
- `olama_messages_manage_campaigns`: additionally requires selected employee actor; manages internal campaigns, reach reporting, health and job repair.
- `olama_messages_configure`: technical administration of rollout settings. Unmapped technical admins may configure, but cannot publish as a fabricated employee. Their settings audit uses `system:configuration` plus the actual WP account ID.
- `olama_messages_chat`: additionally requires chat flag and a verified family/employee actor; relationship/object policy remains mandatory.
- `olama_messages_contact_teachers`: selected administrative employee may contact teachers. It grants no family directory or arbitrary employee-to-employee messaging.
- `olama_messages_service_inbox`: selected employee also needs an active membership in the requested inbox.
- `olama_messages_manage_inboxes`: employee can configure inbox metadata/members. Full inbox history additionally requires active manager membership and the service-inbox capability.
- `olama_messages_moderate`: employee can review reports, open the reported thread with an audited reason, redact within that report's scope, and manage restrictions.
- `olama_messages_audit`: employee can open arbitrary private threads/revisions with a recorded reason. Ordinary configuration/campaign administration grants no private-content access.

No family/staff role receives automatic grants. Existing `olama_access_messages` and SMS permissions are preserved. The new admin menu uses its own parent capability so granting notices does not require legacy SMS access.

Business REST requests require session login, REST nonce, server-resolved actor, feature/pilot permission and object ownership. Acting context travels in `X-Olama-Actor`; this is an untrusted requested context, never authority. Each tab holds its own selection. The current Users API returns one verified identity; additional mappings are not invented. Account suspension and current enrollment/employee status are checked independently of historical target membership.

No individual father/mother receipt claims. No student actor in A. No employee powers while using a family actor, even if the WP account has administrative capabilities. Notifications may only contain the selected actor's notices; no cross-identity feed. All REST output is private/no-store. Browser coordination keys include WP account, hashed session scope and actor; this is cache routing, not a security boundary against arbitrary same-origin scripts.

Cancellation/archive preserve already delivered notices. They stop further fanout. In B, an active verified family identity can read its own historical notices/chat after current enrollment changes; eligibility still gates new targeting/sends. No destructive deletion of published campaigns or ordinary chat messages. Feature/pilot changes can revoke current access without deleting evidence.

## Shared-inbox history

All policies require active membership and the service-inbox capability. Active managers with the manager capability see full history. For regular members:

| Policy | Access |
| --- | --- |
| active_only | Open requests, plus resolved requests still assigned to that actor. |
| active_and_recent | Above, plus resolved requests in which the actor participated, within `recent_days` (default 30, configurable 1–365). |
| all_history | All requests in that inbox. |
| manager_history_only | Open requests only; resolved history requires manager authorization. |

The requester retains their own request. Membership removal ends staff access immediately, including receipts, polling hints and previously authored threads. Privileged audit/report access uses separate audited routes and does not restore ordinary membership.

Restrictions support `send_block`, `new_thread_block`, `target_block`, `chat_block`, and reserved `attachment_block`. Scope is global, actor, service inbox or thread; the restricted actor may be `*`. Start/end/revocation apply on requests without waiting for cron. Reasons remain private. Reports and personal reading remain available under a chat restriction; account suspension blocks everything. Messages default to 20/minute and new threads to 10/10 minutes per canonical actor, shared across sessions. These are fixed-window limits, not presence metrics.

## Remaining core capabilities

- `olama_messages_attachments`: additionally requires the private-attachments flag and current owner-object authorization, for both original and preview.
- `olama_messages_actions`: action list, authorized creation and versioned state changes; separate from read/acknowledgement.
- `olama_messages_manage_actions`: selected employee can create/assign standalone work to eligible actors. Does not override private thread access or allow recipient-family reassignment.
- `olama_messages_events`: own released event targets, calendar, receipts, acknowledgement, RSVP and private ICS.
- `olama_messages_manage_events`: selected employee can author/publish event projections, finalize audiences, add explicit targets and reconcile sources. School source fields remain read-only.
- `olama_messages_view_dashboard`: selected employee can view metadata counts/SLA metrics, without transcript access or teacher rankings.

Private file audit additionally requires employee audit capability and a recorded reason. Ordinary removal/redaction denies both thumbnails and originals. Service actions, search and activity notifications enforce current membership and history policy. Event responses enforce exact displayed/required revision pairs and current child eligibility. Action-required campaign/event work requires both the underlying feature entitlement and actions entitlement.

Use separate `attachments_enabled`, `actions_enabled`, and `events_enabled` switches. Defaults are off. Enable chat moderation/restrictions with chat; configuring C/D never grants identities or roles. Account suspension blocks current business operations, while a scoped interactive-chat restriction does not remove official notices or event access.
