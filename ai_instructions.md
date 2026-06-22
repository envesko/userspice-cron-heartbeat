# Cron Heartbeat - AI instructions

Liveness tracking for external/scheduler-driven cron jobs in UserSpice. Complements the built-in
Cron Manager (which schedules + logs its own jobs to `crons_logs`); this tracks anything that calls
`cronBeat()`.

## Public functions (defined in functions.php, auto-loaded globally when active)
- `cronGuard($opts = [])` - call FIRST in a cron file. Gates access (loopback ∪ `settings.cron_ip` ∪
  plugin allowlist, or a CLI secret, or `admin_ok` in a browser), opens a run row, returns the slug.
  Opts: `slug` (default = folder name), `admin_ok` (bool).
- `cronBeat($slug, $status='ok', $note='', $metadata=null)` - finalize the run (status/note/JSON
  metadata, clamped to 16 KB). One execution = one history row.
- `cronHeartbeatJobs()` / `cronHeartbeatRuns($slug, $limit)` - read summary + per-run history.
- `cronHeartbeatRunAlerts()` - email the configured address once per stale episode.
- `cronGuardAllowedIps()` - effective allowlist + open flag.

## Tables
- `cron_heartbeat` - per-slug summary (LastRun, Runs, LastStatus, LastNote, ExpectedMins, Alerted).
- `cron_heartbeat_config` - single row (AllowedIps, StaleHours, OpenAccess, AlertEnabled, AlertEmail, CronSecret).
- `cron_heartbeat_runs` - per-run history (RunAt, Status, Note, Metadata); pruned to last 50/slug.

## Lifecycle files
install.php (create tables) · activate.php (status active) · uninstall.php (deactivate, retain data) ·
delete.php (drop tables) · migrate.php (numbered schema updates) · configure.php (admin UI).

## Key conventions
Gate is CLOSED by default. Secrets/IPs compared with `hash_equals`/bound SQL. Metadata rendered only
through the admin modal via core `dump()`. AJAX inspector (`files/run_inspect.php`) is master + CSRF
gated, mirroring core `logMetadataById.php`. Dashboard widget at `usersc/widgets/cronheartbeat`.
