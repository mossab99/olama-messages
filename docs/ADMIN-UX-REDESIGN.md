# Olama Messages admin redesign

## Design goals

The 2.5 admin experience is organized around operator intent instead of database
tables. The visible navigation is:

1. Overview
2. Campaign Center
3. Quick Send
4. Phone Book
5. Message Library
6. Delivery Operations
7. Parent Report Links
8. Settings

Legacy queue, recipient-preview, agent, and campaign-progress URLs remain
registered as hidden compatibility pages. Queue delegates to Delivery
Operations; the other diagnostic views remain reachable from contextual links.

Phone Book is a read-only Olama Core view. Operators choose a study year and
see only active families with a non-withdrawn student-year record, including
father and mother names, phone numbers, and the synchronized family address.

## Campaign lifecycle

`draft → prepared → sending → paused → sending → completed`

Preparation and sending are deliberately separate:

- Drafts autosave purpose, audience, message, and `filters_json.ui_step`.
- Preparation checks Olama Core readiness, validates message fields, snapshots
  recipients and rendered bodies, and creates only `prepared` queue records.
- Authorization recounts the prepared queue, requires the exact phrase
  `SEND {count}`, checks a dispatcher-ready agent, and writes `started_by` and
  `started_at`.
- Completion writes `completed_at`.
- Reset removes prepared snapshots while preserving the editable message draft.

Quick Send uses the same lifecycle. It prepares one immutable direct-message
campaign and redirects to authorization; it never starts dispatch by itself.

## Olama Core ownership

Messages treats Olama Core as the system of record for audience, financial, and
transportation data. Campaign preparation uses the Core provider service rather
than querying Oracle-owned tables directly. The provider returns sync health and
row provenance (`core_family_uid`, source hash, and last-sync time), which are
stored with the campaign and recipient snapshots for auditability.

If a required source is unavailable or stale, operators may continue editing a
draft but preparation is blocked with a synchronization message.

## SMS segmentation

The shared PHP and JavaScript calculators use:

- GSM-7: 160 septets for one part, 153 per part for multipart messages.
- GSM-7 extension characters (`^{}\\[~]|€`): two septets each.
- Unicode: 70 characters for one part, 67 per part for multipart messages.

Run:

```text
php tests/test-sms-segmentation.php
node tests/sms-segmentation.test.js
```

Tests are pure calculations and never call a sending agent or create queue data.

## Security rules

Every campaign mutation requires the Messages capability, a nonce, and POST.
AJAX draft save and preview endpoints also check the capability and shared AJAX
nonce. Phone numbers in delivery operations are masked. Sending cannot begin
from a GET request or from campaign preparation.
