<?php
/**
 * Stale-alert checker. Point your scheduler at this (e.g. every 15 min):
 *   https://yoursite.com/usersc/plugins/cronheartbeat/files/check_stale.php
 *   or via CLI:  php check_stale.php key=YOUR_CRON_SECRET
 *
 * Emails the configured alert address once per stale episode (recovery clears the flag).
 * Itself guarded + heartbeated like any other cron.
 */
require_once dirname(__DIR__, 4) . '/users/init.php';

$slug = cronGuard(['slug' => 'cronheartbeat-check', 'admin_ok' => true]);
$sent = cronHeartbeatRunAlerts();
cronBeat($slug, 'ok', "stale check: {$sent} alert(s) sent", ['alerts_sent' => $sent]);

echo "cronheartbeat-check ran: {$sent} alert(s) sent\n";
