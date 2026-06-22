<?php
// Numbered migrations, applied after a Spice Shaker update (and on activation).
// Add future schema changes as uniquely-numbered blocks (oldest at top); applied state is
// tracked in us_plugins.updates so each runs once. Wrapped in the master_account check for security.

include 'plugin_info.php';
if (in_array($user->data()->id, $master_account) && pluginActive($plugin_name, true)) {
    $count = 0;
    $db = DB::getInstance();

    $checkQ = $db->query('SELECT id, updates FROM us_plugins WHERE plugin = ?', [$plugin_name]);
    if ($checkQ->count() > 0) {
        $check = $checkQ->first();
        $existing = ($check->updates == '') ? [] : json_decode($check->updates);

        // --- migrations go here, each with a unique code ---
        // $update = '00001';
        // if (!in_array($update, $existing)) {
        //     // ...apply the change (ALTER TABLE, etc.)...
        //     logger($user->data()->id, 'Migrations', "$update applied for $plugin_name");
        //     $existing[] = $update;
        //     $count++;
        // }

        $new = json_encode($existing);
        $db->update('us_plugins', $check->id, ['updates' => $new, 'last_check' => date('Y-m-d H:i:s')]);
        if (!$db->error()) {
            logger($user->data()->id, 'Migrations', "$count migration(s) triggered for $plugin_name");
        } else {
            logger($user->data()->id, 'USPlugins', 'Failed to save updates, Error: ' . $db->errorString());
        }
    }
}
