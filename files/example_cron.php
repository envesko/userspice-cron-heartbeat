<?php
/**
 * Example guarded cron job.
 * Copy this pattern into your own cron files. Point your system scheduler (or the built-in
 * UserSpice Cron Manager) at the URL of your job, e.g.:
 *
 *   https://yoursite.com/usersc/plugins/cronheartbeat/files/example_cron.php
 *
 * cronGuard() enforces the IP allowlist (configured on the plugin's config page) and records
 * a heartbeat. cronBeat() at the end records the outcome and a short note.
 */

require_once dirname(__DIR__, 4) . '/users/init.php';

// 'admin_ok' => true lets a logged-in master account trigger it from a browser for testing.
$slug = cronGuard(['slug' => 'example-cron', 'admin_ok' => true]);

// --- your real work goes here ---
$processed = 0;
// e.g. $processed = doSomeWork();
// --------------------------------

// The 4th arg is an optional metadata array - stored as JSON and inspectable in the admin UI
// (click the magnifying glass on a run), just like the UserSpice Logs metadata viewer.
cronBeat($slug, 'ok', "example run, processed {$processed} items", [
    'processed'  => $processed,
    'ran_at'     => date('c'),
    'php'        => PHP_VERSION,
]);

echo "example-cron ran OK\n";
