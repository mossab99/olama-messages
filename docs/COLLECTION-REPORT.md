# Collection and transport suspension review report

Location: Messages → Reports → التحصيل ومراجعة إيقاف المواصلات.

Requires `olama_access_messages` plus `olama_manage_financial_accounts` or
`manage_options`. Global policy changes require `manage_options`.

The report reads synchronized Core tables only. It does not contact Oracle,
send messages, alter accounting, or change transport subscriptions.

## Calculation

- One assessment per active family and academic year with enrolled students.
- Report date is today in the WordPress timezone. Historical reconstruction is
  deliberately unavailable: Core replaces its source allocations during sync.
- Due = installment face value minus allocated discounts for due dates <= today.
- Covered = due minus the nonnegative remaining balance, capped to due.
- Overdue = sum of remaining due balances. Future installments are excluded.
- `paid_amount` is discounts; `receipt_paid` is payments and carried credit.
  Do not add the ledger credits to these allocations: that duplicates coverage.
- Typical installment = median positive net schedule amount, or an authorized
  family/year override. Exceptional schedules should be reviewed and overridden.
- Counts distinguish fully unpaid and partially paid due installments.
- Default financial qualification: overdue >= 2 typical installments, and an
  installment with a balance > JOD 1 is at least 30 days overdue. Tiny residuals
  remain in totals and counts, but cannot extend the qualifying age.
- Finance staff can preview alternate thresholds without saving. Admins can
  save the previewed policy. Default maximum sync age is two calendar days.

No numeric risk score is needed for the agreed simplified rule. Coverage,
arrears size and age remain separately visible and sortable.

## Recommendation and administrative verification

Financial qualification alone is visible separately from transport candidacy.
Confirmed candidacy additionally needs a source-confirmed active transport
subscription within its date range and a current, explicit finance verification
that no approved exception or compliant payment arrangement applies.
No verification or expired verification leaves the case pending. Exceptions and
compliant arrangements prevent candidacy. This is still a review recommendation,
not an operational suspension instruction.

Verification notes, expiry, user, timestamp, policy, calculation version and up
to 20 previous verification versions are local WordPress options per family/year.
They are administrative attestations; external agreements are not auto-imported.

Missing schedules, invalid dates, inconsistent balances, non-JOD currency, stale
syncs or future/undated ledger transactions block confirmed candidacy. Original
source allocation is preserved; receipt validity remains Oracle's responsibility.
The report is not a cash collection or historical payment-frequency analysis.
The scope includes families with a synchronized financial summary, not families
whose financial data has never been synchronized.

CSV exports respect filters and require financial permission plus a nonce.
The student export contains only confirmed review candidates, one row per active
student, with no debt figures. All CSV cells are formula-injection protected.

## Checks

`php tests/test-collection-report.php` covers the 459/985 ERP examples, fils
precision, boundaries, partial balances, advance coverage, tolerance, overrides,
exceptions, expiry, access, CSV protection, siblings, subscriptions and staleness.

`php tests/test-collection-report-wordpress.php` uses the existing disposable
WordPress/MySQL test harness and actual Core schema to check joins, Arabic HTML,
escaping, filters, capability separation and CSRF protection. Optional environment
variable `OLAMA_COLLECTION_FIXTURE` writes a synthetic HTML preview file.
