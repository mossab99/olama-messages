# Sending agent workspace (4.0.16)

The Sending Agents directory now leads with operational agent cards. Registration is a disclosure, and credentials are still shown only once through the existing registration flow.

Each agent workspace has a status summary, target and observed cadence, last submission, campaign progress, unknown-outcome count, and a paginated attempt history. Each attempt appears once with its latest outcome; identifiers and errors can be expanded. Phone numbers are masked in the activity list. Configuration, the audit snapshot, and destructive administration controls are secondary disclosures.

The read model is `includes/class-olama-messages-agent-dashboard.php`; the view is `admin/class-olama-messages-agent-page.php`. These components do not reserve work, invoke KDE, alter settings or change delivery outcomes. Detail refreshes use a capability- and nonce-protected read-only AJAX action every 5 seconds; the agent directory refreshes every 15 seconds. Requests do not overlap, pause in hidden tabs, and back off after failures. Inspection details retain focus and are not replaced while open.

Queue/heartbeat/attempt timestamps are UTC and displayed in the WordPress site timezone. Legacy registration and audit timestamps already use site-local time and are not shifted again. The displayed timezone and relative ages make this distinction visible. Current reservations and server-side results take precedence over a stale phase label in a heartbeat; no new device discovery calls are added.

`php tests/test-agent-dashboard.php` covers operational state precedence, timezone conversion, cadence/count aggregation, safe output, masked phones, read-only behavior, and AJAX capability/nonce checks in the isolated disposable database. Set `OLAMA_AGENT_UI_FIXTURE` to an output directory to generate fictional browser fixtures from the real view and assets. Visual checks cover desktop, mobile, RTL, pagination, activity filters, background refresh, retained inspection and registration disclosure. Existing SMS dispatch, heartbeat and readiness regressions also pass.
