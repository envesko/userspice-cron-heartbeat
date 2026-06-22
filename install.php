<?php

require_once 'init.php';
if (in_array($user->data()->id, $master_account)) {
    $db = DB::getInstance();
    include 'plugin_info.php';

    $queries = [
        [
            'Description' => 'Create cron_heartbeat table',
            'SQL' => 'CREATE TABLE IF NOT EXISTS cron_heartbeat (
                id INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                CronSlug VARCHAR(190) NOT NULL UNIQUE,
                LastRun DATETIME NULL,
                Runs INT(11) UNSIGNED NOT NULL DEFAULT 0,
                LastStatus VARCHAR(40) NULL,
                LastNote VARCHAR(255) NULL,
                ExpectedMins INT(11) UNSIGNED NULL,
                Alerted TINYINT(1) NOT NULL DEFAULT 0
            )',
        ],
        [
            'Description' => 'Create cron_heartbeat_config table',
            'SQL' => 'CREATE TABLE IF NOT EXISTS cron_heartbeat_config (
                id INT(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                AllowedIps TEXT NULL,
                StaleHours INT(11) UNSIGNED NOT NULL DEFAULT 25,
                OpenAccess TINYINT(1) NOT NULL DEFAULT 0,
                AlertEnabled TINYINT(1) NOT NULL DEFAULT 0,
                AlertEmail VARCHAR(190) NULL,
                CronSecret VARCHAR(80) NULL
            )',
        ],
        [
            'Description' => 'Create cron_heartbeat_runs table (per-run history + metadata)',
            'SQL' => 'CREATE TABLE IF NOT EXISTS cron_heartbeat_runs (
                id BIGINT(20) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                CronSlug VARCHAR(190) NOT NULL,
                RunAt DATETIME NOT NULL,
                Status VARCHAR(40) NULL,
                Note VARCHAR(255) NULL,
                Metadata MEDIUMTEXT NULL,
                KEY idx_slug_id (CronSlug, id)
            )',
        ],
    ];

    foreach ($queries as $query) {
        $db->query($query['SQL']);
        if (!$db->error()) {
            logger($user->data()->id, 'CronHeartbeat', "[INSTALL] [SQL] [SUCCESS] {$query['Description']}");
        } else {
            logger($user->data()->id, 'CronHeartbeat', "[INSTALL] [SQL] [WARNING] {$query['Description']} failed", json_encode(['ERROR' => $db->errorString()]));
        }
    }

    // Seed a single config row if none exists (gate CLOSED by default; random CLI secret)
    if ($db->query('SELECT id FROM cron_heartbeat_config WHERE id = 1')->count() == 0) {
        $db->insert('cron_heartbeat_config', [
            'id' => 1, 'AllowedIps' => '', 'StaleHours' => 25,
            'OpenAccess' => 0, 'AlertEnabled' => 0, 'AlertEmail' => '',
            'CronSecret' => bin2hex(random_bytes(16)),
        ]);
    }

    // Copy the dashboard widget into usersc/widgets
    $widgetSrc = "{$abs_us_root}{$us_url_root}usersc/plugins/{$plugin_name}/assets/widget/";
    $widgetDest = "{$abs_us_root}{$us_url_root}usersc/widgets/cronheartbeat/";
    if (!file_exists($widgetDest)) {
        @mkdir($widgetDest, 0777, true);
    }
    if (file_exists($widgetSrc) && file_exists($widgetDest)) {
        foreach (array_diff(scandir($widgetSrc), ['.', '..']) as $file) {
            if (copy($widgetSrc . $file, $widgetDest . $file)) {
                logger($user->data()->id, 'CronHeartbeat', "[INSTALL] [FILES] [SUCCESS] Copied widget/{$file}");
            }
        }
    }

    $hooks = [];
    registerHooks($hooks, $plugin_name);

    $check = $db->query('SELECT * FROM us_plugins WHERE plugin = ?', [$plugin_name])->count();
    if ($check > 0) {
        err($plugin_name . ' has already been installed!');
    } else {
        $db->insert('us_plugins', ['plugin' => $plugin_name, 'status' => 'installed']);
        if (!$db->error()) {
            err($plugin_name . ' installed');
            logger($user->data()->id, 'USPlugins', $plugin_name . ' installed');
        } else {
            err($plugin_name . ' was not installed');
            logger($user->data()->id, 'USPlugins', 'Failed to install plugin, Error: ' . $db->errorString());
        }
    }
}
