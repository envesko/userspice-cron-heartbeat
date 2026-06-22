<?php

require_once 'init.php';
// Called immediately after install.php on activation. Sets the plugin active.
// For security it is MANDATORY this be wrapped in the master_account check.
if (in_array($user->data()->id, $master_account)) {
    $db = DB::getInstance();
    include 'plugin_info.php';

    $checkQ = $db->query('SELECT * FROM us_plugins WHERE plugin = ?', [$plugin_name]);
    if ($checkQ->count() > 0) {
        $db->update('us_plugins', $checkQ->first()->id, ['status' => 'active']);
        if (!$db->error()) {
            err($plugin_name . ' activated');
            logger($user->data()->id, 'USPlugins', $plugin_name . ' Activated');
        } else {
            err($plugin_name . ' was not activated');
            logger($user->data()->id, 'USPlugins', $plugin_name . ' failed to activate, Error: ' . $db->errorString());
        }
    } else {
        err($plugin_name . ' is not found! Has it been installed?');
        logger($user->data()->id, 'USPlugins', $plugin_name . ' plugin not found - possibly not installed');
    }
}
