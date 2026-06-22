<?php

require_once 'init.php';
if (in_array($user->data()->id, $master_account)) {
    $db = DB::getInstance();
    include 'plugin_info.php';

    // Data tables are left in place on uninstall so heartbeat history is not lost.
    // To fully remove, drop cron_heartbeat and cron_heartbeat_config manually.

    // Remove the copied widget
    $widgetDest = "{$abs_us_root}{$us_url_root}usersc/widgets/cronheartbeat/";
    if (file_exists($widgetDest)) {
        foreach (array_diff(scandir($widgetDest), ['.', '..']) as $file) {
            @unlink($widgetDest . $file);
        }
        @rmdir($widgetDest);
    }

    $db->query('DELETE FROM us_plugins WHERE plugin = ?', [$plugin_name]);
    $db->query('DELETE FROM us_plugin_hooks WHERE folder = ?', [$plugin_name]);
    logger($user->data()->id, 'USPlugins', $plugin_name . ' uninstalled (data tables retained)');
    err($plugin_name . ' uninstalled. Heartbeat tables were retained - drop them manually if you want the history gone.');
}
