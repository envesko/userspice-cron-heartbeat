<?php
/**
 * Cron Heartbeat - global helpers (Envesko).
 * Auto-loaded by UserSpice (users/helpers/helpers.php) for every active plugin.
 * Fail-silent throughout: a missing table or bad config must never break a job.
 *
 * Relationship to UserSpice core:
 *   - Core's built-in Cron Manager (the `crons` table + users/cron/cron.php) schedules and logs its
 *     OWN jobs to `crons_logs`. Cron Heartbeat is for EXTERNAL / scheduler-driven cron files
 *     (anything that calls cronBeat()), which core does not track.
 *   - Core gates those jobs with a single `settings.cron_ip` (+127.0.0.1). This plugin UNIONS that
 *     value with its own allowlist - it never overrides or rewrites settings.cron_ip.
 */

if (!defined('CRONHEARTBEAT_META_MAX')) {
    define('CRONHEARTBEAT_META_MAX', 16000); // clamp metadata JSON to bound table growth
}

if (!function_exists('cronHeartbeatConfig')) {
    function cronHeartbeatConfig()
    {
        global $db;
        static $cfg = null;
        if ($cfg !== null) {
            return $cfg ?: null;
        }
        try {
            $q = $db->query('SELECT * FROM cron_heartbeat_config WHERE id = 1');
            $cfg = $q->count() ? $q->first() : false;
        } catch (Exception $e) {
            $cfg = false;
        }
        return $cfg ?: null;
    }
}

if (!function_exists('cronGuardAllowedIps')) {
    /**
     * Effective allowlist = loopback ∪ settings.cron_ip ∪ plugin AllowedIps.
     * Returns ['ips' => [...], 'open' => bool]. 'open' is true ONLY when the admin has explicitly
     * enabled OpenAccess - otherwise the gate is CLOSED by default (loopback/cron_ip/allowlist only).
     */
    function cronGuardAllowedIps()
    {
        global $settings;
        $ips = ['127.0.0.1', '::1'];

        if (isset($settings) && !empty($settings->cron_ip)) {
            $ips[] = trim($settings->cron_ip);
        }
        $cfg = cronHeartbeatConfig();
        if ($cfg && trim((string)$cfg->AllowedIps) !== '') {
            foreach (array_filter(array_map('trim', explode(',', $cfg->AllowedIps))) as $ip) {
                $ips[] = $ip;
            }
        }
        $open = $cfg ? ((int)$cfg->OpenAccess === 1) : false;
        return ['ips' => array_values(array_unique($ips)), 'open' => $open];
    }
}

if (!function_exists('cronHeartbeatTouchSummary')) {
    /** Upsert the per-slug summary row. $bumpRuns controls whether the Runs counter increments. */
    function cronHeartbeatTouchSummary($slug, $status, $note, $bumpRuns)
    {
        global $db;
        $now = date('Y-m-d H:i:s');
        $existing = $db->query('SELECT id, Runs FROM cron_heartbeat WHERE CronSlug = ?', [$slug]);
        $data = ['LastRun' => $now, 'LastStatus' => $status, 'LastNote' => $note];
        if ($existing->count()) {
            $row = $existing->first();
            if ($bumpRuns) {
                $data['Runs'] = (int)$row->Runs + 1;
            }
            // a fresh run clears any outstanding stale-alert flag (recovery)
            $data['Alerted'] = 0;
            $db->update('cron_heartbeat', $row->id, $data);
        } else {
            $data['CronSlug'] = $slug;
            $data['Runs'] = $bumpRuns ? 1 : 0;
            $db->insert('cron_heartbeat', $data);
        }
    }
}

if (!function_exists('cronBeat')) {
    /**
     * Record that a job ran. ONE execution = ONE run-history row (cronGuard opens it, cronBeat
     * finalizes the SAME row). $metadata: optional array/object stored as JSON (clamped), inspectable
     * in the admin UI like the UserSpice Logs metadata viewer.
     */
    function cronBeat($slug, $status = 'ok', $note = '', $metadata = null)
    {
        global $db;
        $slug = preg_replace('/[^A-Za-z0-9_\-.]/', '', (string)$slug);
        if ($slug === '') {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $status = substr((string)$status, 0, 40);
        $note = substr((string)$note, 0, 255);

        $metaJson = null;
        if ($metadata !== null) {
            $metaJson = is_string($metadata) ? $metadata : json_encode($metadata, JSON_UNESCAPED_SLASHES);
            if (strlen((string)$metaJson) > CRONHEARTBEAT_META_MAX) {
                $metaJson = json_encode(['_truncated' => true, 'original_bytes' => strlen((string)$metaJson)]);
            }
        }

        try {
            $openId = $GLOBALS['__cronRun'][$slug] ?? null;
            if ($openId) {
                cronHeartbeatTouchSummary($slug, $status, $note, false);
                try {
                    $db->update('cron_heartbeat_runs', (int)$openId, [
                        'Status' => $status, 'Note' => $note, 'Metadata' => $metaJson, 'RunAt' => $now,
                    ]);
                } catch (Exception $e) {
                }
            } else {
                cronHeartbeatTouchSummary($slug, $status, $note, true);
                try {
                    $db->insert('cron_heartbeat_runs', [
                        'CronSlug' => $slug, 'RunAt' => $now, 'Status' => $status, 'Note' => $note, 'Metadata' => $metaJson,
                    ]);
                    cronHeartbeatPrune($slug);
                } catch (Exception $e) {
                }
            }
            return !$db->error();
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('cronHeartbeatPrune')) {
    /** Keep only the most recent N run rows per slug to bound table growth. */
    function cronHeartbeatPrune($slug, $keep = 50)
    {
        global $db;
        $keep = max(1, (int)$keep);
        try {
            $db->query(
                'DELETE FROM cron_heartbeat_runs WHERE CronSlug = ? AND id NOT IN
                 (SELECT id FROM (SELECT id FROM cron_heartbeat_runs WHERE CronSlug = ? ORDER BY id DESC LIMIT ' . $keep . ') keep)',
                [$slug, $slug]
            );
        } catch (Exception $e) {
        }
    }
}

if (!function_exists('cronGuard')) {
    /**
     * Gate + heartbeat for a cron endpoint. FIRST line of a cron file:
     *
     *   require_once '../../users/init.php';
     *   $slug = cronGuard(['slug' => 'my-job']);
     *   // ... work ...
     *   cronBeat($slug, 'ok', 'done', ['processed' => 12]);
     *
     * Options:
     *   slug      string  job id (defaults to the cron file's folder name)
     *   admin_ok  bool    allow a logged-in master/perm-2 admin to run it from a browser
     *
     * Access rules (in order):
     *   1. A matching secret (config CronSecret) supplied via ?key=… (web) or CRON_KEY env / key=…
     *      argv (CLI) always passes - this is the recommended path for php-cli schedulers where the
     *      IP gate is meaningless.
     *   2. Otherwise the client IP must be in the effective allowlist
     *      (loopback ∪ settings.cron_ip ∪ plugin allowlist), UNLESS OpenAccess is enabled.
     *   3. admin_ok lets a logged-in master run it in a browser for testing.
     */
    function cronGuard($opts = [])
    {
        global $user, $db;

        $slug = isset($opts['slug']) && $opts['slug'] !== ''
            ? $opts['slug']
            : basename(dirname((string)Server::get('SCRIPT_FILENAME')));
        $slug = preg_replace('/[^A-Za-z0-9_\-.]/', '', (string)$slug);
        if ($slug === '') {
            $slug = 'unknown';
        }

        $cfg = cronHeartbeatConfig();
        $secret = $cfg && !empty($cfg->CronSecret) ? (string)$cfg->CronSecret : '';

        // 1) secret path (works under CLI + web)
        $secretOk = false;
        if ($secret !== '') {
            $provided = '';
            if (PHP_SAPI === 'cli') {
                $provided = (string)getenv('CRON_KEY');
                if ($provided === '' && isset($GLOBALS['argv'])) {
                    foreach ($GLOBALS['argv'] as $arg) {
                        if (strpos($arg, 'key=') === 0) {
                            $provided = substr($arg, 4);
                        }
                    }
                }
            } else {
                $provided = (string)Input::get('key');
            }
            if ($provided !== '' && hash_equals($secret, $provided)) {
                $secretOk = true;
            }
        }

        // 2) IP path
        $ipOk = false;
        if (!$secretOk) {
            $allow = cronGuardAllowedIps();
            $ip = Server::getClientIp();
            $ipOk = $allow['open'] ? true : in_array($ip, $allow['ips'], true);
        }

        // 3) admin path
        $adminOk = !empty($opts['admin_ok'])
            && isset($user) && $user->isLoggedIn()
            && hasPerm([2], $user->data()->id);

        if (!$secretOk && !$ipOk && !$adminOk) {
            $ip = Server::getClientIp();
            cronBeat($slug, 'blocked', 'IP ' . $ip . ' not allowed', ['ip' => $ip]);
            if (function_exists('logger')) {
                logger(1, 'CronHeartbeat', "Blocked cron '$slug' from $ip");
            }
            http_response_code(403);
            exit('Forbidden');
        }

        // open a run row for this request; cronBeat() finalizes the same row
        cronHeartbeatTouchSummary($slug, 'ok', '', true);
        try {
            $db->insert('cron_heartbeat_runs', [
                'CronSlug' => $slug, 'RunAt' => date('Y-m-d H:i:s'), 'Status' => 'ok', 'Note' => '', 'Metadata' => null,
            ]);
            $GLOBALS['__cronRun'][$slug] = $db->lastId();
            cronHeartbeatPrune($slug);
        } catch (Exception $e) {
        }
        return $slug;
    }
}

if (!function_exists('cronHeartbeatStaleHours')) {
    function cronHeartbeatStaleHours()
    {
        $cfg = cronHeartbeatConfig();
        $h = $cfg ? (int)$cfg->StaleHours : 25;
        return $h > 0 ? $h : 25;
    }
}

if (!function_exists('cronHeartbeatJobIsStale')) {
    /** Stale if: never run, failed/blocked, or past its threshold. Per-job ExpectedMins wins over the global StaleHours. */
    function cronHeartbeatJobIsStale($row)
    {
        if (empty($row->LastRun)) {
            return true;
        }
        if (in_array($row->LastStatus, ['fail', 'blocked'], true)) {
            return true;
        }
        $ageMin = (time() - strtotime($row->LastRun)) / 60;
        if (!empty($row->ExpectedMins) && (int)$row->ExpectedMins > 0) {
            return $ageMin > (int)$row->ExpectedMins * 2; // missed two expected intervals
        }
        return $ageMin > cronHeartbeatStaleHours() * 60;
    }
}

if (!function_exists('cronHeartbeatJobs')) {
    function cronHeartbeatJobs()
    {
        global $db;
        $out = [];
        try {
            $rows = $db->query('SELECT * FROM cron_heartbeat ORDER BY CronSlug ASC')->results();
            foreach ($rows as $r) {
                $r->stale = cronHeartbeatJobIsStale($r);
                $r->ageHours = $r->LastRun ? (time() - strtotime($r->LastRun)) / 3600 : null;
                $out[] = $r;
            }
        } catch (Exception $e) {
        }
        return $out;
    }
}

if (!function_exists('cronHeartbeatRuns')) {
    function cronHeartbeatRuns($slug, $limit = 50)
    {
        global $db;
        $limit = max(1, min(200, (int)$limit));
        try {
            return $db->query('SELECT * FROM cron_heartbeat_runs WHERE CronSlug = ? ORDER BY id DESC LIMIT ' . $limit, [$slug])->results();
        } catch (Exception $e) {
            return [];
        }
    }
}

if (!function_exists('cronHeartbeatRunAlerts')) {
    /**
     * Send a stale alert (once per stale episode) for any job that is stale and not yet alerted.
     * Returns the number of alerts sent. Uses the core email() mailer.
     */
    function cronHeartbeatRunAlerts()
    {
        global $db, $settings;
        $cfg = cronHeartbeatConfig();
        if (!$cfg || (int)$cfg->AlertEnabled !== 1 || empty($cfg->AlertEmail)) {
            return 0;
        }
        $sent = 0;
        foreach (cronHeartbeatJobs() as $j) {
            if ($j->stale && (int)($j->Alerted ?? 0) === 0) {
                $site = isset($settings->site_name) ? $settings->site_name : 'UserSpice';
                $subject = "[$site] Cron job '{$j->CronSlug}' is stale";
                $body = "<p>The cron job <strong>" . htmlspecialchars($j->CronSlug) . "</strong> has not checked in as expected.</p>"
                    . "<ul><li>Last run: " . htmlspecialchars((string)$j->LastRun) . "</li>"
                    . "<li>Last status: " . htmlspecialchars((string)$j->LastStatus) . "</li>"
                    . "<li>Total runs: " . (int)$j->Runs . "</li></ul>"
                    . "<p>Check your scheduler.</p>";
                $ok = false;
                try {
                    $ok = email($cfg->AlertEmail, $subject, $body);
                } catch (Exception $e) {
                }
                $db->query('UPDATE cron_heartbeat SET Alerted = 1 WHERE CronSlug = ?', [$j->CronSlug]);
                if (function_exists('logger')) {
                    logger(1, 'CronHeartbeat', "Stale alert for '{$j->CronSlug}' " . ($ok ? 'sent' : 'FAILED') . " to {$cfg->AlertEmail}");
                }
                if ($ok) {
                    $sent++;
                }
            }
        }
        return $sent;
    }
}
