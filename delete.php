<?php

require_once 'init.php';
// Called only when a plugin is actively DELETED (not on deactivate). Safe place to drop tables,
// since the admin has chosen to remove the plugin and its data for good.
if (in_array($user->data()->id, $master_account)) {
    $db = DB::getInstance();
    include 'plugin_info.php';

    foreach (['cron_heartbeat_runs', 'cron_heartbeat_config', 'cron_heartbeat'] as $table) {
        $db->query('DROP TABLE IF EXISTS ' . $table);
    }
    logger($user->data()->id, 'USPlugins', $plugin_name . ' tables dropped on delete');
}
