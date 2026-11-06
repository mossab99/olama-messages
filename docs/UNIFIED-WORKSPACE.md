# Unified workspace — 4.1.0

The plugin now uses one scoped admin shell with six primary destinations:
Overview, Campaigns, Audience, Message library, Delivery, and Settings.
Context navigation retains Quick Send, reports, parent report links, and agent
workspaces. Existing WordPress page URLs and capability checks remain valid.
The WordPress admin bar and a dashboard return link remain available.

Shared styles cover headings, forms, buttons, notices, status labels, tables,
cards, campaign progress, and the existing live agent workspace. Tables scroll
locally on small screens. Mobile navigation, keyboard page search (Ctrl/Cmd+K),
visible focus states, Arabic message previews, RTL positioning, and reduced
motion are supported. No remote fonts or new libraries are required.

The overview separates prepared campaigns from the authorized queue, counts
submissions from midnight in the school timezone, and highlights unknown
outcomes. Delivery filters current message outcomes and masks phone numbers.
The library shows readable message cards and can prefill a new campaign.
The campaign editor includes a draft preview, segmentation count, and summary.
Preparation and authorization remain separate actions with existing nonce and
confirmation requirements. No dispatcher or Windows agent behavior is changed.

## Verification

- `php tests/test-unified-workspace.php` renders the actual controllers inside
  the shared shell using isolated WordPress/MariaDB data, verifies totals,
  escaping, template reuse, authorization controls, and no new dispatch attempts.
- Set `OLAMA_WORKSPACE_FIXTURE` to an output directory to render browser fixtures.
  The tests use synthetic school data and disable external HTTP and mail.
- Existing SMS dispatch, worker telemetry, dispatcher readiness, agent dashboard,
  and internal communications integration suites pass.
- Browser QA covers desktop, tablet, mobile, RTL, page search, navigation,
  escaped Arabic preview, segmentation output, and JavaScript errors.

This release has no new database migration and requires no Windows agent update.
