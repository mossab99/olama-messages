# OLAMA Communications ecosystem contracts

Repository audit: 2026-09-08. The table below records the A audit; the B contract below supersedes its future-tense adapter notes.

## Verified providers

| Owner | Actual contract | Identifiers / behavior | Failure and fallback |
| --- | --- | --- | --- |
| Users | `olama_users_get_identity($wp_user_id)` | Read-only; returns one identity with `identity_type`, opaque `oracle_identifier`, `account_status`, `wp_user_id`. Underlying lookup chooses first row. | Release A explicitly reports `single_verified`. No additional family/employee identity is inferred. Missing/suspended mapping denies non-administrator business operations. |
| Users | `Olama_Users_DB::get_identity($type, $oracle_identifier)` | Read-only reverse account lookup for audience reachability. | Missing means no provisioned account, not unread/offline. Adapter contains all usage. |
| Users | `olama_users_register_modules` action, `olama_users_register_module()` | Declares module and granular capabilities. Non-administrators require grants in Users. | Never grant parent/staff roles automatically. Configuration permission alone does not authorize sending as an employee. |
| Core | `families()->get_by_oracle_id()`, `get_by_uid()` | Map Oracle external reference to canonical opaque `family_uid`. Read-only. | Missing record denies actor resolution; no name/phone identity inference. |
| Core | `employees()->get_by_employee_id()`, `active($args)` | Opaque employee number; active status in current source is `مستمر`. Bounded pagination supported. | Missing/inactive record denies actor access. |
| Core | `audiences()->query($filters)`, `get_sync_health($type,$year)` | General, finance, transportation, renewal family audiences; offset/limit, academic filters. General API is independent of SMS phone validation. | Missing/not-ready sources fail preparation. Only relevant source health is checked. |
| Core | `student_years()->get_by_family($uid,$year)`, `academic_context()->current()` | Current entitlement uses canonical academic context and active enrollment, independently of published targets. | Missing current context/active student denies private family access. |
| Core | `families()->get_by_uids()`, `employees()->get_by_employee_ids()`, `read_models()->table('student_years')` | Public batched profile/read-model contracts. Resolver batches enrollment checks in a bounded job transaction; no persistent permission cache. | Failed reads stop the batch. Reverse identity lookup remains in Users' single-record public contract. |
| School | `Olama_School_Teacher` assignment/office-hours APIs | Existing teacher IDs are WP user IDs; mapped employee numbers are also used. Some assignment methods invoke bridge synchronization. | NOT called on Release A authorization. Release B needs a strictly read-only relationship adapter; no blind reuse of mutating methods. |
| School | `Olama_School_Academic::{get_events,get_event,add_event,update_event,delete_event}` | School owns existing academic events, Core owns years/semesters. | Release D needs source reconciliation, including upstream deletion; no duplicate event ownership. |
| Gateway | `olama_student_gateway_messages_data` filter; `olama_student_gateway_register_providers` action; `olama_student_gateway_render_messages` action | Read model receives verified family/student context. Template calls the rendering action when provider data is nonempty. | Supply safe counts through the filter and embed the app through the verified render action. Standalone shortcode also supported. |
| Messages | `Olama_Messages_Campaign_Service`, dispatcher, activator | 2.5.1, PHP 7.4, WordPress 6+. Existing campaign lifecycle is SMS-specific, including reset/delete. | SMS service explicitly rejects internal records; separate internal handler uses shared campaign metadata and its own domain tables. Preserve routes/table names. |

## Actor and permission decisions

Family receipts describe the account, never a named physical guardian. Store business actor and authenticated WP account in audit. Employee actor IDs are employee numbers, not WP user IDs. WordPress administrators use the explicit `administrator:<wp_user_id>` actor, receive every Communications capability, and may contact any currently eligible Communications account without an academic relationship. They never impersonate or fabricate an employee identity.

The current verified identity API supports a single identity. The resolver has an available-actors contract for later authoritative Users integration; no guessed future function is called. Selected actor is supplied per request, validated against the account, and stored only in tab-local client state. There is no mutable session-global current actor. Selected family context never inherits staff business permission.

## Consistency and freshness

Core read models are synchronized data; School assignments are locally owned. Never infer stale local assignments from elapsed wall time alone. Family access checks current enrollment; audience preparation checks relevant Core sync health. Finance failure does not disable general notices. Source APIs do not expose a transactional audience generation ID. Batched preparation therefore represents its documented preparation window; immutable targets finalize before publication. Retries resume the same snapshot/cursor, and reset creates a new snapshot. No stronger point-in-time guarantee is claimed.

## Missing contracts / release boundaries

- Authoritative multi-identity API: unavailable; single-verified fallback is the supported production mode.
- Source event revisions/deletion hooks: require audit in D; not invented now.
- Pure read-only teacher relationship API: requires B adapter; chat remains disabled in A.
- Student authentication: no student actor delivery in A.
- Core audience filters outside the verified API are rejected, not silently ignored.

No upstream plugin writes are needed for Release A. Configuration, domain data, jobs, and audit remain owned by Messages.

## Release B relationship and freshness contract

`Olama_Messages_Relationship_Provider` isolates School's compatibility dependency. It never calls synchronizing teacher/academic bridge methods. It reads School assignments/sections/subjects, then checks Core's public `academic_grade_subjects` and `academic_grade_sections` read models. A stale School active-subject mirror cannot override an inactive Core subject.

| Source | Semantics / acceptance |
| --- | --- |
| Core student and student-year | Oracle-synchronized. Exact family/student UID, active enrollment, current year; both timestamps within `student_context_max_age`. |
| Core employee | Oracle-synchronized. Active employee number within `employee_mapping_max_age`; Users must map that exact number to the verified account. |
| Core subject/section mapping | Oracle-synchronized. Active subject and year/class/section mapping within `academic_mapping_max_age`. |
| Current academic context | Live relational year/semester selection. Both IDs required; no time-based expiry. |
| School teacher assignment | Locally authored live rows; School toggles an assignment with individual insert/delete statements. No source timestamp/history generation exists. Check current row, canonical employee, WP teacher ID and current `olama_teacher` role on each send. |

Each synchronized-domain threshold defaults independently to **86,400 seconds**, configurable in settings (60 seconds–30 days) or filters `olama_messages_student_context_max_age`, `olama_messages_employee_mapping_max_age`, `olama_messages_academic_mapping_max_age`. These are initial staging settings, not an assumed school SLA. Core timestamps are WordPress local time, converted with `wp_timezone()`; Communications check times are UTC. Unknown, missing or invalid timestamps fail closed. No invented assignment time expiry is imposed on live locally authored rows.

Results expose `valid`, `invalid`, `stale`, `unknown`, `mapping_missing` with required-source status. Finance/transport health is not consulted. No cross-plugin atomic generation or substitute history is claimed. New assignment/teacher keys do not inherit old private transcripts. Family contacts are child-first; teacher family-directory scans are bounded to current assigned sections and revalidate every candidate. Nonteachers cannot enumerate that family directory. No upstream writes are introduced in B.
