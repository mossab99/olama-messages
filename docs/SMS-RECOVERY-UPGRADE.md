# SMS recovery upgrade — dispatch protocol 2

This release changes both the WordPress plugin and the Windows agent. Pause sending, let any active SMS finish, and exit the old tray agent. Deploy the plugin, launch the new agent, and resume the campaign. Old agents receive HTTP 426 rather than reserving new work. The new agent also refuses to send through an older server. Existing pending results from the previous agent can still be reported for legacy reservations.

The plugin schema version is 2.6.0. Its normal upgrade adds a queue attempt ID, an immutable attempt ledger, and device pacing rows. Dispatch fails closed unless all dispatch tables use InnoDB. Existing installations using other engines need an administrator to convert the campaigns and queue tables before sending. Back up the database before a production upgrade.

Pause stops new reservations and preserves the active attempt. A timeout, expired reservation, crash after persisted intent, or uncertain KDE error is held as `unknown`; it is never automatically sent again. The campaign progress page provides outcome review: check the phone history, then mark submitted, confirm not submitted and retry, or mark failed. A late result for a reviewed or superseded attempt is acknowledged without changing a newer attempt.

A successful KDE exit is shown as “Submitted via KDE”; it is not a recipient delivery receipt. Cancellation cannot recall a command already handed to KDE. Cancelled jobs retain their audit identity, and late callbacks do not revive them.

Version 4.0.13 and agent 2.0.1 use a 10-second minimum and 360 attempts per rolling hour. The agent waits 10 seconds after each completed cycle; request and KDE processing time add to that gap. Update both packages to remove the previous one-minute limiter. Conservative rate accounting includes submission intents even if launch later fails. Manual test SMS uses the same local limiter. ETA combines the current pacing limit with recent observed processing time, so old one-minute gaps do not carry into the faster estimate; paused time, review time, competing campaigns and offline periods can extend completion.

## Checks

`php tests/test-sms-dispatch.php` uses the existing communications bootstrap, an isolated disposable WordPress/MariaDB database, fictional recipients, and disabled outbound network/email. It checks migration repeatability, device serialization, pause, idempotent success/failure, stale-attempt fencing, unknown recovery/review, failed-write rollback, cancellation, pacing, ETA and old-agent rejection.

`php tests/test-dispatcher-readiness.php` is a standalone diagnostic regression.

In the Windows repository, `tests/run-recovery-tests.ps1` builds a fake CLI and local HTTP fixture. It checks pre-send persisted intent, result identity, acknowledgements, persistent pacing, ambiguous exit/timeout, restart recovery without resending, recovery while the phone is offline, protocol mismatch, corrupt pending data and unwritable intent storage. No real KDE executable or phone is used.

The agent keeps `pending-dispatch-result.json` and `sms-rate-history.json` in its existing settings directory. Do not delete pending outcomes to bypass an error; resolve them through the server review flow. Corrupt local files stop sending for inspection.


## Telemetry correction (plugin 4.0.14 / agent 2.0.2)

Settings “Test Connection” now authenticates through a read-only GET to the dispatcher configuration endpoint. It does not post a heartbeat or publish dispatcher state. The regular worker heartbeat remains responsible for current device/dispatcher telemetry.

The server also accepts older partial heartbeats without erasing omitted worker fields or a connected device name. It preserves the actual worker poll timestamp, respects explicit disabled/disconnected values, and discards old device/worker metadata when the selected target changes. Oversized telemetry is rejected rather than replacing the healthy snapshot. Heartbeat responses report the saved operational status instead of always saying online.

`php tests/test-agent-telemetry.php` verifies these cases in the disposable database. The Windows recovery fixture checks that connection testing never publishes a heartbeat or reserves work. The ten-second policy remains in this release.
