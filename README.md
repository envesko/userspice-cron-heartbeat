# Cron Heartbeat (UserSpice plugin)

Know whether your scheduled cron jobs are actually running. UserSpice's built-in Cron Manager
*schedules* jobs but does not verify they ran. This plugin adds liveness tracking + an IP guard.

## Install
1. Copy this folder to `usersc/plugins/cronheartbeat/`.
2. Admin → Plugin Manager → activate **Cron Heartbeat**.
3. Open its config page and set the IP your scheduler runs from.

## Use
At the top of any cron file:

```php
require_once '../../users/init.php';
$slug = cronGuard(['slug' => 'my-job']);    // enforces IP allowlist + records a heartbeat
// ... work ...
cronBeat($slug, 'ok', 'processed 12 rows', ['processed' => 12]); // outcome + optional metadata
```

The config page and the dashboard widget show each job's last run, run count, and flag any job
that has gone past the stale threshold. Expand a job to **drill into its recent runs**, and click a
run's magnifying glass to **inspect its JSON metadata** in a modal - the same UX as the core
UserSpice Logs metadata viewer. The 4th `cronBeat()` argument is a free-form array; put whatever
you want in it. A working example ships in `files/example_cron.php`.

## Access control
The gate is **closed by default**: a guarded job runs only when the request comes from loopback, the
core `settings.cron_ip`, an IP you add on the config page, or carries the **CLI secret**. Tick
"Allow all IPs" only if you must (the config page warns when it's on). This plugin **never overrides
`settings.cron_ip`** - it only adds to it.

- **CLI schedulers:** the IP gate is meaningless under `php-cli`, so use the secret instead:
  `php example_cron.php key=YOUR_SECRET` (or `?key=…` over HTTP, or the `CRON_KEY` env var). The
  secret is shown + regenerable on the config page.

## Stale alerts
Turn on "Email me when a job goes stale" and set an address, then schedule
`files/check_stale.php` (e.g. every 15 min). You get one email per stale episode (cleared on
recovery). Per job you can set an **expected interval (minutes)** - that job is flagged stale after
it misses two intervals, overriding the global hours threshold.

## Relationship to the built-in Cron Manager
Core's Cron Manager (the `crons` table + `users/cron/cron.php`) schedules its own jobs and logs them
to `crons_logs`. Cron Heartbeat is for **external / scheduler-driven** cron files (anything that
calls `cronBeat()`) that core does not track. Use both: Cron Manager to run jobs, Cron Heartbeat to
confirm external jobs ran.

## Tables
- `cron_heartbeat` - one row per job slug (LastRun, Runs, LastStatus, LastNote, ExpectedMins, Alerted)
- `cron_heartbeat_config` - allowlist IPs, stale-hours, OpenAccess, AlertEnabled/AlertEmail, CronSecret
- `cron_heartbeat_runs` - per-run history (RunAt, Status, Note, Metadata JSON); pruned to the last 50 per job

Tables are retained on uninstall so history survives a reinstall.

---

© 2026 **Envesko** - released under the MIT License (see `LICENSE`). Author, maintainer, and code owner: Envesko.
