<?php
/**
 * AJAX endpoint: returns the metadata for one run as a formatted block for the modal.
 * Mirrors the core UserSpice Logs metadata viewer (users/parsers/logMetadataById.php):
 * master/perm-2 gated, CSRF-checked, renders via dump().
 */
require_once dirname(__DIR__, 4) . '/users/init.php';

if ((!isset($user) || !$user->isLoggedIn()) || !hasPerm([2], $user->data()->id)) {
    die('You do not have permission to be here.');
}
if (!Token::check(Input::get('token'))) {
    echo json_encode(['success' => false, 'msg' => 'Invalid token']);
    die;
}

global $db;
$id = (int)Input::get('id');
$row = $db->query('SELECT * FROM cron_heartbeat_runs WHERE id = ?', [$id]);
if ($row->count() !== 1) {
    dump(null);
    die();
}
$run = $row->first();
$meta = $run->Metadata;
if ($meta !== null && $meta !== '' && function_exists('validateJson') && validateJson($meta)) {
    dump(json_decode($meta));
} elseif ($meta !== null && $meta !== '') {
    dump($meta);
} else {
    dump('(no metadata recorded for this run)');
}
die();
