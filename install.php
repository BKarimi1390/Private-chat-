<?php
/* ============================================================
   Private Chat - installer
   Open this file once in the browser after editing config.php,
   then DELETE it from the server.
   ============================================================ */

require_once __DIR__ . '/lib.php';

$messages = array();
$okAll = true;

function pc_row($label, $ok, $note = '')
{
    $color = $ok ? '#1c8f52' : '#c0392b';
    $mark = $ok ? 'OK' : 'FAILED';
    echo '<div class="row"><b style="color:' . $color . '">' . $mark . '</b> <span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
        . ($note !== '' ? '<div class="note">' . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '</div>' : '')
        . '</div>';
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Private Chat installer</title>
<style>
body{font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f1420;color:#e9edf6;margin:0;padding:26px}
.card{max-width:720px;margin:0 auto;background:#151c2c;border:1px solid #263049;border-radius:16px;padding:22px}
h1{font-size:20px;margin:0 0 4px}
p{color:#a8b3c9;font-size:14px;line-height:1.7}
.row{background:#101726;border:1px solid #222c44;border-radius:10px;padding:10px 12px;margin:8px 0;font-size:14px}
.note{color:#8d99b3;font-size:12.5px;margin-top:4px}
code{background:#0b1120;padding:2px 6px;border-radius:6px;font-size:12.5px}
.done{background:#12251a;border-color:#1c8f52}
.warn{background:#2a1a12;border-color:#c0722b}
a.btn{display:inline-block;margin-top:14px;background:#3b82f6;color:#fff;text-decoration:none;padding:10px 16px;border-radius:10px;font-weight:600}
</style>
</head>
<body>
<div class="card">
<h1>Private Chat &mdash; installer</h1>
<p>This page checks your hosting, creates the database tables and the first administrator account.</p>
<?php

$phpOk = version_compare(PHP_VERSION, '7.1.0', '>=');
pc_row('PHP version ' . PHP_VERSION, $phpOk, $phpOk ? '' : 'PHP 7.1 or newer is required.');
$okAll = $okAll && $phpOk;

$pdoOk = class_exists('PDO');
pc_row('PDO extension', $pdoOk, $pdoOk ? '' : 'Ask your host to enable PDO.');
$okAll = $okAll && $pdoOk;

$drivers = $pdoOk ? PDO::getAvailableDrivers() : array();
$driverNeeded = (DB_DRIVER === 'sqlite') ? 'sqlite' : 'mysql';
$driverOk = in_array($driverNeeded, $drivers, true);
pc_row('PDO driver "' . $driverNeeded . '"', $driverOk, $driverOk ? '' : 'Available drivers: ' . implode(', ', $drivers));
$okAll = $okAll && $driverOk;

$jsonOk = function_exists('json_encode');
pc_row('JSON support', $jsonOk);
$okAll = $okAll && $jsonOk;

$hashOk = function_exists('password_hash');
pc_row('Password hashing', $hashOk, $hashOk ? '' : 'PHP 5.5+ required.');
$okAll = $okAll && $hashOk;

if ($okAll) {
    try {
        $result = pc_install();
        pc_row('Database tables', true, 'users, sessions, app_state, login_fails');
        pc_row('Administrator account', true, $result === 'created'
            ? 'Created with the ID and password from config.php.'
            : 'Already existed - left untouched.');
        $state = pc_load_state();
        pc_row('Shared state row', true, 'version ' . $state['version']);
        echo '<div class="row done"><b>Installation finished.</b><div class="note">Now delete <code>install.php</code> from the server, then open <code>index.html</code>.</div></div>';
        echo '<a class="btn" href="index.html">Open Private Chat</a>';
    } catch (Exception $e) {
        pc_row('Database setup', false, $e->getMessage());
        echo '<div class="row warn">Check the database name, user and password in <code>config.php</code>.</div>';
    }
} else {
    echo '<div class="row warn">Fix the failed checks above, then reload this page.</div>';
}

?>
</div>
</body>
</html>
