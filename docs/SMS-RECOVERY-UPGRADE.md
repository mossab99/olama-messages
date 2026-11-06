# SMS recovery upgrade — dispatch protocol 2

This release changes both the WordPress plugin and the Windows agent. Pause sending, let any active SMS finish, and exit the old tray agent. Deploy the plugin, launch the new agent, and resume the campaign. Old agents receive HTTP 426 rather than reserving new work. The new agent also refuses to send through an older server. Existing pending results from the previous agent can still be reported for legacy reservations.

The plugin schema version is 2.6.0. Its normal upgrade adds a queue attempt ID, an immutable attempt ledger, and device pacing rows. Dispatch fails closed unless all dispatch tables use InnoDB. Existing installations using other engines need an administrator to convert the campaigns and queue tables before sending. Back up the database before a production upgrade.

Pause stops new reservations and preserves the active attempt. A timeout, expired reservation, crash after persisted intent, or uncertain KDE error is held as `unknown`; it is never automatically sent again. The campaign progress page provides outcome review: check the phone history, then mark submitted, confirm not submitted and retry, or mark failed. A late result for a reviewed or superseded attempt is acknowledged without changing a newer attempt.

A successful KDE exit is shown as “Submitted via KDE”; it is not a recipient delivery receipt. Cancellation cannot recall a command already handed to KDE. Cancelled jobs retain their audit identity, and late callbacks do not revive them.

Both server reservations and the local sender enforce the existing 20-second minimum and 60 attempts/hour. Their combined effective spacing is at least 60 seconds. Conservative rate accounting includes submission intents even if launch later fails. Manual test SMS uses the same local limiter. ETA uses the slower of the configured limit and observed campaign reservation throughput; paused time, review time, competing campaigns and offline periods can extend completion.

## Checks

`php tests/test-sms-dispatch.php` uses the existing communications bootstrap, an isolated disposable WordPress/MariaDB database, fictional recipients, and disabled outbound network/email. It checks migration repeatability, device serialization, pause, idempotent success/failure, stale-attempt fencing, unknown recovery/review, failed-write rollback, cancellation, pacing, ETA and old-agent rejection.

`php tests/test-dispatcher-readiness.php` is a standalone diagnostic regression.

In the Windows repository, `tests/run-recovery-tests.ps1` builds a fake CLI and local HTTP fixture. It checks pre-send persisted intent, result identity, acknowledgements, persistent pacing, ambiguous exit/timeout, restart recovery without resending, recovery while the phone is offline, protocol mismatch, corrupt pending data and unwritable intent storage. No real KDE executable or phone is used.

The agent keeps `pending-dispatch-result.json` and `sms-rate-history.json` in its existing settings directory. Do not delete pending outcomes to bypass an error; resolve them through the server review flow. Corrupt local files stop sending for inspection.
