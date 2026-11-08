<?php
/* ============================================================
   Private Chat - single API endpoint
   POST api.php  body: {"action":"...", ...}
   GET  api.php?action=ping  for a readiness check
   ============================================================ */

require_once __DIR__ . '/lib.php';
pc_ensure_schema();

$raw = file_get_contents('php://input');
$body = array();
if ($raw !== false && strlen($raw) > 0) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}
if (!count($body) && count($_POST)) {
    $body = $_POST;
}

$action = '';
if (isset($body['action'])) {
    $action = (string) $body['action'];
} elseif (isset($_GET['action'])) {
    $action = (string) $_GET['action'];
}
$action = preg_replace('/[^a-zA-Z0-9._]/', '', $action);

function pc_str($body, $key, $max = 500)
{
    if (!isset($body[$key]) || !is_scalar($body[$key])) {
        return '';
    }
    $v = trim((string) $body[$key]);
    if (function_exists('mb_substr')) {
        return mb_substr($v, 0, $max, 'UTF-8');
    }
    return substr($v, 0, $max);
}

function pc_len($s)
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

/* convert Persian/Arabic digits in a string to plain ASCII digits */
function fixnum($s)
{
    $map = array('۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
                 '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9');
    return strtr((string) $s, $map);
}

try {
    switch ($action) {

        case 'ping':
            /* pc_db() self-installs: creates the SQLite file, the tables
               and the administrator account on the very first call. */
            $ready = true;
            $note = 'ready';
            try {
                pc_db()->query('SELECT 1 FROM users LIMIT 1')->fetch();
            } catch (Exception $e) {
                $ready = false;
                $note = 'database not ready';
            }
            pc_json(array(
                'ok' => true,
                'server' => 'private-chat',
                'api' => 2,
                'driver' => DB_DRIVER,
                'installed' => $ready,
                'autoInstall' => true,
                'note' => $note,
                'time' => pc_now_ms()
            ));
            break;

        /* The "health" action used to expose the database file path, the
           number of accounts and the number of stored messages on the
           sign-in card. It was removed because it leaked sensitive info
           to anyone without authentication. If you need that data, log in
           as administrator and use the Diagnostics screen. */

        case 'auth.register':
            $name = pc_str($body, 'name', 64);
            $handle = pc_norm_handle(pc_str($body, 'id', 32));
            $pass = (string) (isset($body['pass']) ? $body['pass'] : '');
            if (pc_len($name) < 2) {
                pc_fail('Enter the display name other people will see (2+ characters).');
            }
            if (!pc_valid_handle($handle)) {
                pc_fail('User IDs are 3-20 characters: letters, numbers, dots or underscores.');
            }
            if (strlen($pass) < 6) {
                pc_fail('Passwords must be at least 6 characters long.');
            }
            $db = pc_db();
            $st = $db->prepare('SELECT id FROM users WHERE handle = ?');
            $st->execute(array($handle));
            if ($st->fetch()) {
                pc_fail('@' . $handle . ' is already taken. Please pick another ID.');
            }
            $row = pc_create_user($name, $handle, $pass, 'member');
            $token = pc_start_session((int) $row['id']);
            $state = pc_load_state((int) $row['id']);
            pc_json(array(
                'ok' => true,
                'token' => $token,
                'user' => pc_public_user($row),
                'users' => pc_all_users(),
                'version' => $state['version'],
                'data' => $state['data'],
                'cursor' => 0
            ));
            break;

        case 'auth.login':
            $handle = pc_norm_handle(pc_str($body, 'id', 32));
            $pass = (string) (isset($body['pass']) ? $body['pass'] : '');
            if ($handle === '' || $pass === '') {
                pc_fail('Enter both your user ID and password.');
            }
            if (pc_rate_limited($handle)) {
                pc_fail('Too many failed attempts. Please wait a few minutes and try again.', 429);
            }
            /* Look up the user by primary handle OR by alias. This lets
               users sign in with any of their IDs (primary + premium). */
            $row = pc_find_user_by_handle_or_alias($handle);
            if (!$row || !password_verify($pass, $row['pass_hash'])) {
                pc_log_fail($handle);
                pc_fail('Incorrect user ID or password.', 401);
            }
            if ($row['status'] === 'blocked') {
                pc_fail('This account has been suspended by an administrator.', 403);
            }
            $token = pc_start_session((int) $row['id']);
            $state = pc_load_state((int) $row['id']);
            pc_json(array(
                'ok' => true,
                'token' => $token,
                'user' => pc_public_user($row),
                'users' => pc_all_users(),
                'version' => $state['version'],
                'data' => $state['data']
            ));
            break;

        case 'auth.me':
            $me = pc_current_user($body);
            pc_json(array('ok' => true, 'user' => pc_public_user($me), 'users' => pc_all_users()));
            break;

        case 'auth.logout':
            $token = pc_token_from_request($body);
            if (preg_match('/^[a-f0-9]{16,64}$/', (string) $token)) {
                pc_db()->prepare('DELETE FROM sessions WHERE token = ?')->execute(array($token));
            }
            pc_json(array('ok' => true));
            break;

        /* ---------- sessions (real, server-side) ----------
           sessions.list     - returns all active sessions for the current user
           sessions.terminate- kills either one session (by token) or every
                                other session (when token is omitted).
           The 12-hour cooldown is enforced here: if the current session
           is younger than 12h, terminating OTHER sessions is refused
           with a clear "wait N hours" message. Terminating the current
           session is always allowed (it's just signing out). */
        case 'sessions.list':
            $me = pc_current_user($body);
            $token = pc_token_from_request($body);
            $sessions = pc_list_sessions((int) $me['id'], $token);
            /* find the current session so the client knows how old it is */
            $cur = null;
            foreach ($sessions as $s) { if ($s['isCurrent']) { $cur = $s; break; } }
            $age = $cur ? (pc_now_ms() - $cur['created']) : 0;
            $cooldownRemaining = max(0, 43200000 - $age); /* 12h in ms */
            pc_json(array(
                'ok' => true,
                'sessions' => $sessions,
                'currentCreated' => $cur ? (int) $cur['created'] : 0,
                'cooldownRemaining' => $cooldownRemaining,
                'canTerminate' => $cooldownRemaining === 0
            ));
            break;

        case 'sessions.terminate':
            $me = pc_current_user($body);
            $token = pc_token_from_request($body);
            $target = isset($body['target']) ? (string) $body['target'] : ''; /* 'all' or a token */
            /* If the user wants to terminate OTHER sessions, enforce the
               12-hour cooldown. Per-session removal of a single non-current
               session also falls under the same rule. */
            if ($target !== '' && $target !== $token) {
                $cur = null;
                foreach (pc_list_sessions((int) $me['id'], $token) as $s) {
                    if ($s['isCurrent']) { $cur = $s; break; }
                }
                $age = $cur ? (pc_now_ms() - $cur['created']) : 0;
                if ($age < 43200000) {
                    $hoursLeft = ceil((43200000 - $age) / 3600000);
                    pc_fail('For your security, you must wait ' . $hoursLeft . ' more hour' . ($hoursLeft > 1 ? 's' : '') . ' before terminating other sessions. This cooldown prevents account takeover on freshly-logged-in devices.', 403);
                }
            }
            if ($target === 'all' || $target === '') {
                $count = pc_terminate_other_sessions((int) $me['id'], $token);
                pc_json(array('ok' => true, 'terminated' => $count));
            } else {
                if (!preg_match('/^[a-f0-9]{16,64}$/', $target)) {
                    pc_fail('Invalid session token.');
                }
                $ok = pc_terminate_session((int) $me['id'], $target);
                pc_json(array('ok' => true, 'terminated' => $ok ? 1 : 0));
            }
            break;

        case 'auth.changePassword':
            $me = pc_current_user($body);
            $old = (string) (isset($body['old']) ? $body['old'] : '');
            $new = (string) (isset($body['new']) ? $body['new'] : '');
            if (!password_verify($old, $me['pass_hash'])) {
                pc_fail('The current password is not correct.', 403);
            }
            if (strlen($new) < 6) {
                pc_fail('Passwords must be at least 6 characters long.');
            }
            pc_db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')
                ->execute(array(password_hash($new, PASSWORD_DEFAULT), (int) $me['id']));
            pc_db()->prepare('DELETE FROM sessions WHERE user_id = ? AND token <> ?')
                ->execute(array((int) $me['id'], pc_token_from_request($body)));
            pc_json(array('ok' => true));
            break;

        case 'account.delete':
            /* Self-service account deletion. The user must provide their
               password to confirm. The account and ALL its data (sessions,
               app_state, messages sent/received) are permanently removed.
               The user's @id becomes available for anyone to register.
               This does NOT require admin approval. */
            $me = pc_current_user($body);
            $pass = (string) (isset($body['pass']) ? $body['pass'] : '');
            if (!password_verify($pass, $me['pass_hash'])) {
                pc_fail('The password is not correct.', 403);
            }
            if ($me['role'] === 'admin') {
                pc_fail('The administrator account cannot self-delete. Use the admin panel instead.', 403);
            }
            /* delete the account and all its data */
            pc_delete_user((int) $me['id']);
            /* also kill the current session */
            $token = pc_token_from_request($body);
            if (preg_match('/^[a-f0-9]{16,64}$/', (string) $token)) {
                pc_db()->prepare('DELETE FROM sessions WHERE token = ?')->execute(array($token));
            }
            pc_json(array('ok' => true, 'freed' => '@' . $me['handle']));
            break;

        case 'user.find':
            /* Server-side lookup of any account by ID. Used by the New
               conversation dialog so a brand-new account (one that the
               caller has not synchronised yet) can still be found. */
            $me = pc_current_user($body);
            $handle = pc_norm_handle(pc_str($body, 'id', 32));
            if ($handle === '') {
                pc_fail('Enter a user ID to look up.');
            }
            $db = pc_db();
            $st = $db->prepare('SELECT * FROM users WHERE handle = ?');
            $st->execute(array($handle));
            $row = $st->fetch();
            if (!$row) {
                pc_json(array(
                    'ok' => false,
                    'found' => false,
                    'error' => 'No account found with the ID @' . $handle . '.'
                ));
            }
            pc_json(array(
                'ok' => true,
                'found' => true,
                'user' => pc_public_user($row)
            ));
            break;

        case 'state.pull':
            $me = pc_current_user($body);
            $state = pc_load_state((int) $me['id']);
            pc_json(array(
                'ok' => true,
                'version' => $state['version'],
                'data' => $state['data'],
                'users' => pc_all_users(),
                'me' => pc_public_user($me)
            ));
            break;

        case 'state.push':
            $me = pc_current_user($body);
            $client = isset($body['data']) && is_array($body['data']) ? $body['data'] : null;
            if ($client === null) {
                pc_fail('Nothing to save.');
            }
            $state = pc_load_state((int) $me['id']);
            $merged = pc_merge_state($state['data'], $client);
            $version = pc_save_state((int) $me['id'], $merged);
            pc_json(array(
                'ok' => true,
                'version' => $version,
                'data' => $merged,
                'users' => pc_all_users()
            ));
            break;

        case 'msg.send':
            $me = pc_current_user($body);
            $items = isset($body['items']) && is_array($body['items']) ? $body['items'] : array();
            $sent = array();
            $skipped = array();
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $to = isset($it['to']) ? (int) $it['to'] : 0;
                $peer = $to > 0 ? pc_find_user($to) : null;
                if (!$peer) {
                    $skipped[] = isset($it['mid']) ? (int) $it['mid'] : 0;
                    continue;
                }
                pc_queue_message((int) $me['id'], $to, $it);
                $sent[] = isset($it['mid']) ? (int) $it['mid'] : 0;
            }
            pc_json(array('ok' => true, 'sent' => $sent, 'skipped' => $skipped));
            break;

        case 'msg.fetch':
            /* Long-poll support: pass wait=N (seconds, capped at 20) and
               the server holds the connection open until a new message
               arrives or the deadline expires. This gives near-instant
               delivery between two clients without WebSockets. */
            $me = pc_current_user($body);
            $since = isset($body['since']) ? (int) $body['since'] : 0;
            $wait = isset($body['wait']) ? max(0, min(20, (int) $body['wait'])) : 0;
            if ($wait > 0) {
                @set_time_limit(max(30, $wait + 10));
                @ini_set('max_execution_time', max(30, $wait + 10));
            }
            $deadline = pc_now_ms() + $wait * 1000;
            $res = pc_fetch_messages((int) $me['id'], $since);
            while ($wait > 0 && count($res['items']) === 0 && pc_now_ms() < $deadline) {
                usleep(700000); /* 0.7s between checks */
                $res = pc_fetch_messages((int) $me['id'], $since);
            }
            pc_json(array('ok' => true, 'items' => $res['items'], 'cursor' => $res['cursor']));
            break;

        case 'msg.cursor':
            $me = pc_current_user($body);
            pc_json(array('ok' => true, 'cursor' => pc_message_cursor((int) $me['id'])));
            break;

        case 'admin.syncUsers':
            $me = pc_current_user($body);
            pc_require_admin($me);
            $list = isset($body['users']) && is_array($body['users']) ? $body['users'] : array();
            $db = pc_db();
            foreach ($list as $u) {
                if (!is_array($u) || !isset($u['id'])) {
                    continue;
                }
                $id = (int) $u['id'];
                if ($id <= 0) {
                    continue;
                }
                $name = isset($u['name']) ? (string) $u['name'] : '';
                $status = (isset($u['status']) && $u['status'] === 'blocked') ? 'blocked' : 'active';
                $pro = isset($u['proDays']) ? max(0, (int) $u['proDays']) : 0;
                $rep = isset($u['reports']) ? max(0, (int) $u['reports']) : 0;
                $st = $db->prepare('UPDATE users SET name = ?, status = ?, pro_days = ?, reports = ? WHERE id = ? AND role <> \'admin\'');
                $st->execute(array($name, $status, $pro, $rep, $id));
                if ($status === 'blocked') {
                    $db->prepare('DELETE FROM sessions WHERE user_id = ?')->execute(array($id));
                }
            }
            pc_json(array('ok' => true, 'users' => pc_all_users()));
            break;

        case 'admin.deleteUser':
            $me = pc_current_user($body);
            pc_require_admin($me);
            $uid = isset($body['uid']) ? (int) $body['uid'] : 0;
            if ($uid <= 0) {
                pc_fail('Unknown member.');
            }
            $peer = pc_find_user($uid);
            if (!$peer) {
                pc_json(array('ok' => true, 'freed' => '', 'users' => pc_all_users()));
            }
            if ($peer['role'] === 'admin') {
                pc_fail('The administrator account cannot be deleted.', 403);
            }
            pc_delete_user($uid);
            pc_json(array('ok' => true, 'freed' => '@' . $peer['handle'], 'users' => pc_all_users()));
            break;

        case 'admin.setPassword':
            $me = pc_current_user($body);
            pc_require_admin($me);
            $uid = isset($body['uid']) ? (int) $body['uid'] : 0;
            $new = (string) (isset($body['pass']) ? $body['pass'] : '');
            if ($uid <= 0 || !pc_find_user($uid)) {
                pc_fail('Unknown member.');
            }
            if (strlen($new) < 6) {
                pc_fail('Passwords must be at least 6 characters long.');
            }
            $db = pc_db();
            $db->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')
                ->execute(array(password_hash($new, PASSWORD_DEFAULT), $uid));
            $db->prepare('DELETE FROM sessions WHERE user_id = ?')->execute(array($uid));
            pc_json(array('ok' => true, 'users' => pc_all_users()));
            break;

        case 'admin.setVerified':
            /* Grant or remove the blue verified tick for a user. */
            $me = pc_current_user($body);
            pc_require_admin($me);
            $uid = isset($body['uid']) ? (int) $body['uid'] : 0;
            $verified = !empty($body['verified']) ? 1 : 0;
            if ($uid <= 0 || !pc_find_user($uid)) {
                pc_fail('Unknown member.');
            }
            $db = pc_db();
            $db->prepare('UPDATE users SET verified = ? WHERE id = ?')
                ->execute(array($verified, $uid));
            pc_json(array('ok' => true, 'users' => pc_all_users()));
            break;

        case 'admin.setGold':
            /* Grant or remove the gold verified tick for a user. */
            $me = pc_current_user($body);
            pc_require_admin($me);
            $uid = isset($body['uid']) ? (int) $body['uid'] : 0;
            $gold = !empty($body['gold']) ? 1 : 0;
            if ($uid <= 0 || !pc_find_user($uid)) {
                pc_fail('Unknown member.');
            }
            $db = pc_db();
            $db->prepare('UPDATE users SET gold_verified = ? WHERE id = ?')
                ->execute(array($gold, $uid));
            pc_json(array('ok' => true, 'users' => pc_all_users()));
            break;

        case 'admin.setProTier':
            /* Set the pro tier for a user: '' (none), 'pro', 'gold'. */
            $me = pc_current_user($body);
            pc_require_admin($me);
            $uid = isset($body['uid']) ? (int) $body['uid'] : 0;
            $tier = pc_str($body, 'tier', 10);
            if (!in_array($tier, array('', 'pro', 'gold'), true)) {
                pc_fail('Invalid tier.');
            }
            if ($uid <= 0 || !pc_find_user($uid)) {
                pc_fail('Unknown member.');
            }
            $db = pc_db();
            $db->prepare('UPDATE users SET pro_tier = ? WHERE id = ?')
                ->execute(array($tier, $uid));
            pc_json(array('ok' => true, 'users' => pc_all_users()));
            break;

        /* ---------- Pro subscription requests (receipt upload) ---------- */
        case 'pro.submit':
            /* A member uploads a payment receipt image and selects a plan.
               The request is stored in the tickets table with topic 'Pro'
               so the admin can review it from the Pro panel. */
            $me = pc_current_user($body);
            $plan = pc_str($body, 'plan', 20);
            $receipt = isset($body['receipt']) ? (string) $body['receipt'] : '';
            $allowed_plans = array('pro_1m','pro_3m','pro_12m','gold_1m','gold_3m','gold_12m');
            if (!in_array($plan, $allowed_plans, true)) {
                pc_fail('Pick a valid plan.');
            }
            if (strlen($receipt) < 50 || strpos($receipt, 'data:image/') !== 0) {
                pc_fail('Please upload a valid receipt image.');
            }
            if (strlen($receipt) > 262144) {
                pc_fail('Receipt image is too large. Maximum 256 KB.');
            }
            $plan_names = array(
                'pro_1m'=>'Pro 1 month','pro_3m'=>'Pro 3 months','pro_12m'=>'Pro 12 months',
                'gold_1m'=>'Gold 1 month','gold_3m'=>'Gold 3 months','gold_12m'=>'Gold 12 months'
            );
            $id = pc_create_ticket(
                (int) $me['id'],
                $me['handle'] !== '@' ? $me['handle'] : ('@' . $me['handle']),
                $me['name'],
                'Pro',
                $plan_names[$plan] . ' subscription request',
                'User requested: ' . $plan_names[$plan] . '. Receipt attached.',
                $plan
            );
            /* attach the receipt to the ticket as a note with image */
            if ($id) {
                pc_update_ticket($id, 'open', 'Receipt: ' . substr($receipt, 0, 200));
            }
            pc_json(array('ok' => true, 'ticket' => pc_find_ticket($id)));
            break;

        case 'admin.proRequests':
            /* List all Pro subscription requests for the admin panel.
               Only tickets with topic 'Pro' are returned - Premium ID
               requests have topic 'Premium ID' and are shown in their
               own panel. */
            $me = pc_current_user($body);
            pc_require_admin($me);
            $all = pc_list_tickets('all');
            $proReqs = array();
            foreach ($all as $t) {
                if ($t['topic'] === 'Pro') {
                    $proReqs[] = $t;
                }
            }
            pc_json(array('ok' => true, 'requests' => $proReqs));
            break;

        case 'admin.setPricing':
            /* Admin saves custom pricing config to a global file so all
               members can read it. */
            $me = pc_current_user($body);
            pc_require_admin($me);
            $pricing = isset($body['pricing']) ? $body['pricing'] : array();
            $file = __DIR__ . '/data/pricing.json';
            @file_put_contents($file, json_encode($pricing, JSON_UNESCAPED_UNICODE));
            pc_json(array('ok' => true));
            break;

        case 'pricing.get':
            /* Public endpoint: any member can fetch the current pricing. */
            $file = __DIR__ . '/data/pricing.json';
            $pricing = array();
            if (is_file($file)) {
                $raw = @file_get_contents($file);
                if ($raw) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) $pricing = $decoded;
                }
            }
            if (!$pricing) {
                $pricing = array(
                    'pro_1m' => array('price' => 20000, 'days' => 30),
                    'pro_3m' => array('price' => 50000, 'days' => 90),
                    'pro_12m' => array('price' => 90000, 'days' => 365),
                    'card' => '6219-8619-4925-2497',
                    'holder' => 'MOHAMMAD KARIMI'
                );
            }
            pc_json(array('ok' => true, 'pricing' => $pricing));
            break;

        case 'admin.approvePro':
            /* Admin approves a Pro request: sets the user's pro_tier and
               grants pro_days based on the plan, then sends a notification
               message from @premium_bot to the user so they see it in their
               bot chat on their next long-poll. */
            $me = pc_current_user($body);
            pc_require_admin($me);
            $tid = isset($body['id']) ? (int) $body['id'] : 0;
            $t = pc_find_ticket($tid);
            if (!$t) {
                pc_fail('Request not found.');
            }
            $plan = $t['want'];
            $plan_days = array(
                'pro_1m'=>30, 'pro_3m'=>90, 'pro_12m'=>365,
                'gold_1m'=>30, 'gold_3m'=>90, 'gold_12m'=>365
            );
            $plan_tier = (strpos($plan, 'gold') === 0) ? 'gold' : 'pro';
            $days = isset($plan_days[$plan]) ? $plan_days[$plan] : 30;
            $peer = pc_find_user((int) $t['uid']);
            if ($peer && $peer['role'] !== 'admin') {
                $db = pc_db();
                /* add days to existing balance (don't overwrite) */
                $newDays = (int) $peer['pro_days'] + $days;
                $db->prepare('UPDATE users SET pro_days = ?, pro_tier = ? WHERE id = ?')
                    ->execute(array($newDays, $plan_tier, (int) $peer['id']));
                /* Send a bot notification from @premium_bot to the user */
                $plan_names = array(
                    'pro_1m'=>'Pro 1 month','pro_3m'=>'Pro 3 months','pro_12m'=>'Pro 12 months',
                    'gold_1m'=>'Gold 1 month','gold_3m'=>'Gold 3 months','gold_12m'=>'Gold 12 months'
                );
                $planLabel = isset($plan_names[$plan]) ? $plan_names[$plan] : $plan;
                $msg = "Pro activated.\n\nPlan: " . $planLabel . "\nDays: " . $newDays . "\n\nAll premium features are now unlocked.";
                pc_queue_bot_message('premium_bot', (int) $peer['id'], $msg, array(
                    array('label' => 'Check my status', 'action' => 'pro_status')
                ));
            }
            pc_update_ticket($tid, 'resolved', 'Pro subscription approved. Plan: ' . $plan . ', Tier: ' . $plan_tier . ', Days: ' . $days);
            pc_json(array('ok' => true, 'ticket' => pc_find_ticket($tid), 'users' => pc_all_users(), 'requests' => pc_list_tickets('all')));
            break;

        case 'admin.declinePro':
            /* Admin declines a Pro request: marks the ticket as declined
               and notifies the user via @premium_bot. */
            $me = pc_current_user($body);
            pc_require_admin($me);
            $tid = isset($body['id']) ? (int) $body['id'] : 0;
            $reason = pc_str($body, 'reason', 500);
            $t = pc_find_ticket($tid);
            if (!$t) {
                pc_fail('Request not found.');
            }
            $peer = pc_find_user((int) $t['uid']);
            if ($peer) {
                $msg = "Receipt declined.\n\nRequest #" . $tid . " was declined.";
                if ($reason !== '') $msg .= "\nReason: " . $reason;
                $msg .= "\n\nYou can upload a new receipt.";
                pc_queue_bot_message('premium_bot', (int) $peer['id'], $msg, array(
                    array('label' => 'Buy Pro', 'action' => 'pro_buy')
                ));
            }
            pc_update_ticket($tid, 'declined', 'Pro request declined. ' . $reason);
            pc_json(array('ok' => true, 'ticket' => pc_find_ticket($tid), 'requests' => pc_list_tickets('all')));
            break;

        /* ---------- Premium ID requests ---------- */
        case 'pro.requestId':
            /* A member requests a premium ID. Stored as a ticket with
               topic 'Premium ID' so the admin can review it from the
               Premium ID requests panel. */
            $me = pc_current_user($body);
            $reqId = pc_norm_handle(pc_str($body, 'id', 32));
            $note = pc_str($body, 'note', 1000);
            if (!pc_valid_handle($reqId)) {
                pc_fail('Premium IDs are 3-20 characters: letters, numbers, dots or underscores.');
            }
            /* refuse if the ID is already taken by someone else */
            $db = pc_db();
            $st = $db->prepare('SELECT id FROM users WHERE handle = ?');
            $st->execute(array($reqId));
            if ($st->fetch() && $reqId !== $me['handle']) {
                pc_fail('@' . $reqId . ' is already taken by another account.');
            }
            /* refuse if a pending request for the same ID already exists */
            $existing = pc_list_tickets('all');
            foreach ($existing as $t) {
                if ($t['topic'] === 'Premium ID' && $t['want'] === $reqId && ($t['status'] === 'open' || $t['status'] === 'progress')) {
                    pc_fail('A pending request for @' . $reqId . ' already exists. Please wait for the admin to review it.');
                }
            }
            $id = pc_create_premium_id_request((int) $me['id'], $me['handle'], $me['name'], $reqId, $note);
            pc_json(array('ok' => true, 'ticket' => pc_find_ticket($id)));
            break;

        case 'admin.proIdRequests':
            /* List all Premium ID requests for the admin panel. */
            $me = pc_current_user($body);
            pc_require_admin($me);
            $all = pc_list_tickets('all');
            $idReqs = array();
            foreach ($all as $t) {
                if ($t['topic'] === 'Premium ID') $idReqs[] = $t;
            }
            pc_json(array('ok' => true, 'requests' => $idReqs));
            break;

        case 'admin.approveProId':
            /* Admin approves a premium ID request: ADDS the requested ID
               as an alias for the user (does NOT replace their primary
               handle). The user can now sign in with either their primary
               ID or the new premium ID. Premium aliases show a gold badge. */
            $me = pc_current_user($body);
            pc_require_admin($me);
            $tid = isset($body['id']) ? (int) $body['id'] : 0;
            $price = pc_str($body, 'price', 50);
            $customId = pc_norm_handle(pc_str($body, 'customId', 32));
            $t = pc_find_ticket($tid);
            if (!$t) pc_fail('Request not found.');
            $newHandle = ($customId !== '') ? $customId : pc_norm_handle($t['want']);
            if (!pc_valid_handle($newHandle)) pc_fail('Invalid premium ID.');
            $peer = pc_find_user((int) $t['uid']);
            if (!$peer) pc_fail('User not found.');
            /* check the new handle is not taken by another user or alias */
            $existingUser = pc_find_user_by_handle_or_alias($newHandle);
            if ($existingUser && (int) $existingUser['id'] !== (int) $peer['id']) {
                pc_fail('@' . $newHandle . ' is already taken.');
            }
            /* add the alias instead of replacing the primary handle */
            $ok = pc_add_alias((int) $peer['id'], $newHandle, 1);
            if (!$ok) pc_fail('Could not add alias. It may already exist.');
            pc_update_ticket($tid, 'resolved', 'Premium ID approved: @' . $newHandle);
            try {
                $db = pc_db();
                $db->prepare('UPDATE tickets SET want = ? WHERE id = ?')->execute(array($newHandle, $tid));
            } catch (Exception $e) { /* ignore */ }
            /* notify the user via @premium_bot (not super_bot — they're merged) */
            $msg = "Premium ID activated.\n\nYour new ID: @" . $newHandle . "\n\nYou can now sign in with @" . $newHandle . " or your original ID @" . $peer['handle'] . ".";
            if ($price !== '') $msg .= "\n\nPrice paid: " . $price;
            pc_queue_bot_message('premium_bot', (int) $peer['id'], $msg);
            pc_json(array('ok' => true, 'ticket' => pc_find_ticket($tid), 'users' => pc_all_users(), 'requests' => pc_list_tickets('all')));
            break;

        case 'admin.declineProId':
            /* Admin declines a premium ID request and notifies the user. */
            $me = pc_current_user($body);
            pc_require_admin($me);
            $tid = isset($body['id']) ? (int) $body['id'] : 0;
            $reason = pc_str($body, 'reason', 500);
            $t = pc_find_ticket($tid);
            if (!$t) pc_fail('Request not found.');
            $peer = pc_find_user((int) $t['uid']);
            if ($peer) {
                $msg = "ID request declined.\n\n@" . $t['want'] . " was declined.";
                if ($reason !== '') $msg .= "\nReason: " . $reason;
                $msg .= "\n\nYou can request a different ID.";
                pc_queue_bot_message('super_bot', (int) $peer['id'], $msg);
            }
            pc_update_ticket($tid, 'declined', 'Premium ID declined. ' . $reason);
            pc_json(array('ok' => true, 'ticket' => pc_find_ticket($tid), 'requests' => pc_list_tickets('all')));
            break;

        case 'admin.messagePro':
            /* Admin sends a custom message to a user via @premium_bot.
               Useful for asking the user to provide a clearer receipt. */
            $me = pc_current_user($body);
            pc_require_admin($me);
            $uid = isset($body['uid']) ? (int) $body['uid'] : 0;
            $text = pc_str($body, 'text', 1000);
            if ($uid <= 0 || !pc_find_user($uid)) pc_fail('User not found.');
            if ($text === '') pc_fail('Message text is empty.');
            pc_queue_bot_message('premium_bot', $uid, $text);
            pc_json(array('ok' => true));
            break;

        /* ---------- bot configuration ---------- */
        case 'botconfig.get':
            /* Public endpoint: any member can fetch the bot config so the
               welcome text and admin IDs are visible. */
            pc_json(array('ok' => true, 'config' => pc_load_botconfig()));
            break;

        case 'admin.setBotConfig':
            /* Admin saves the bot configuration (admin IDs, welcome texts). */
            $me = pc_current_user($body);
            pc_require_admin($me);
            $cfg = isset($body['config']) && is_array($body['config']) ? $body['config'] : array();
            /* merge with defaults so missing keys are filled in */
            $merged = pc_default_botconfig();
            foreach ($cfg as $k => $v) {
                if ($k === 'adminIds') {
                    if (is_array($v)) {
                        $clean = array();
                        foreach ($v as $h) {
                            $h = pc_norm_handle((string) $h);
                            if ($h !== '') $clean[] = $h;
                        }
                        $merged['adminIds'] = $clean ? $clean : array('admin');
                    }
                } elseif (is_string($v)) {
                    $merged[$k] = $v;
                }
            }
            pc_save_botconfig($merged);
            pc_json(array('ok' => true, 'config' => $merged));
            break;

        case 'admin.getBotConfig':
            /* Admin reads the current bot config to populate the form. */
            $me = pc_current_user($body);
            pc_require_admin($me);
            pc_json(array('ok' => true, 'config' => pc_load_botconfig()));
            break;

        case 'admin.reset':
            $me = pc_current_user($body);
            pc_require_admin($me);
            $db = pc_db();
            $db->exec('DELETE FROM messages');
            $db->exec('DELETE FROM login_fails');
            $ids = array();
            foreach ($db->query('SELECT id FROM users') as $r) {
                $ids[] = (int) $r['id'];
            }
            foreach ($ids as $id) {
                pc_reset_state($id);
            }
            pc_json(array('ok' => true, 'accounts' => count($ids)));
            break;

        case 'admin.wipeAll':
            /* Wipe ALL user data — deletes all member accounts, messages,
               chats, tickets, groups, channels, sessions. Bot accounts
               and the admin account are PRESERVED along with their data.
               The site stays fully functional after wipe. */
            $me = pc_current_user($body);
            pc_require_admin($me);
            $db = pc_db();
            /* collect IDs to preserve (admin + bots) */
            $preserveIds = array();
            foreach ($db->query("SELECT id FROM users WHERE role = 'admin' OR is_bot = 1") as $r) {
                $preserveIds[] = (int) $r['id'];
            }
            $preserveList = implode(',', $preserveIds);
            /* delete all data from tables that reference user IDs.
               We delete everything EXCEPT rows belonging to preserved IDs. */
            /* messages: delete all (messages are between users; bot messages
               to deleted users are orphaned and should go too) */
            $db->exec('DELETE FROM messages');
            /* tickets: delete all (only members create tickets) */
            $db->exec('DELETE FROM tickets');
            /* chat_index: delete all (groups/channels owned by members) */
            $db->exec('DELETE FROM chat_index');
            /* chat_messages: delete all (groups/channels owned by members) */
            $db->exec('DELETE FROM chat_messages');
            /* chat_members: delete all (member rows reference deleted users) */
            $db->exec('DELETE FROM chat_members');
            /* join_requests: delete all */
            $db->exec('DELETE FROM join_requests');
            /* ai_usage: delete all (member usage counters) */
            $db->exec('DELETE FROM ai_usage');
            /* user_aliases: delete all (premium IDs purchased by members) */
            $db->exec('DELETE FROM user_aliases');
            /* sessions: delete all except preserved users' sessions */
            if (count($preserveIds) > 0) {
                $db->exec('DELETE FROM sessions WHERE user_id NOT IN (' . $preserveList . ')');
            }
            /* app_state: delete all except preserved users' state */
            if (count($preserveIds) > 0) {
                $db->exec('DELETE FROM app_state WHERE user_id NOT IN (' . $preserveList . ')');
            }
            /* login_fails: clear all */
            $db->exec('DELETE FROM login_fails');
            /* users: delete all except admin and bots */
            if (count($preserveIds) > 0) {
                $db->exec('DELETE FROM users WHERE id NOT IN (' . $preserveList . ')');
            }
            /* count how many were deleted */
            $deletedCount = 0;
            $r = $db->query('SELECT changes()')->fetch();
            $deletedCount = (int) $r['changes()'];
            pc_json(array('ok' => true, 'deleted' => $deletedCount, 'users' => pc_all_users()));
            break;

        case 'admin.restart':
            /* "Restart" — clears ONLY the PHP opcode cache and the
               server-side state cache. Does NOT delete any user data,
               sessions, or messages. Users stay signed in. This is
               equivalent to a "clear cache" button. */
            $me = pc_current_user($body);
            pc_require_admin($me);
            /* clear PHP opcode cache if available */
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            /* clear any PHP user cache if available */
            if (function_exists('apcu_clear_cache')) {
                @apcu_clear_cache();
            }
            if (function_exists('xcache_clear_cache')) {
                @xcache_clear_cache(XC_TYPE_VAR, 0);
            }
            pc_json(array('ok' => true, 'message' => 'Cache cleared. No user data was affected.'));
            break;

        /* ---------- profile (avatar + bio) ---------- */
        case 'profile.update':
            $me = pc_current_user($body);
            $name = pc_str($body, 'name', 64);
            $bio  = pc_str($body, 'bio', 255);
            $avatar = isset($body['avatar']) ? max(0, min(20, (int) $body['avatar'])) : 0;
            if (pc_len($name) < 2) {
                pc_fail('Display name must be at least 2 characters.');
            }
            $db = pc_db();
            $st = $db->prepare('UPDATE users SET name = ?, bio = ?, avatar = ? WHERE id = ?');
            $st->execute(array($name, $bio, $avatar, (int) $me['id']));
            $fresh = pc_find_user((int) $me['id']);
            pc_json(array('ok' => true, 'user' => pc_public_user($fresh), 'users' => pc_all_users()));
            break;

        case 'profile.uploadAvatar':
            /* Accepts a base64-encoded image (data URL) and stores it as the
               user's avatar photo. The client is expected to resize to
               max 256x256 and compress to JPEG before sending. */
            $me = pc_current_user($body);
            $data = isset($body['avatar']) ? (string) $body['avatar'] : '';
            if (strlen($data) < 50 || strpos($data, 'data:image/') !== 0) {
                pc_fail('Please send a valid image (data URL starting with data:image/).');
            }
            if (strlen($data) > 200000) {
                pc_fail('Image is too large. Maximum 200 KB after resizing.');
            }
            $db = pc_db();
            $db->prepare('UPDATE users SET avatar_url = ? WHERE id = ?')
                ->execute(array($data, (int) $me['id']));
            $fresh = pc_find_user((int) $me['id']);
            pc_json(array('ok' => true, 'user' => pc_public_user($fresh)));
            break;

        /* ---------- Animate Maker Bot ---------- */
        case 'bot.animate':
            /* The client sends a photo (base64) and an animation style.
               The server stores the sticker as a message from the bot to
               the requesting user so it appears in their bot chat and can
               be forwarded. Returns the sticker payload. */
            $me = pc_current_user($body);
            $photo = isset($body['photo']) ? (string) $body['photo'] : '';
            $style = pc_str($body, 'style', 20);
            $allowed = array('spin','bounce','pulse','shake','glow','rainbow','swing','heartbeat');
            if (!in_array($style, $allowed, true)) {
                pc_fail('Pick a valid animation style.');
            }
            if (strlen($photo) < 50 || strpos($photo, 'data:image/') !== 0) {
                pc_fail('Please send a valid photo.');
            }
            if (strlen($photo) > 262144) {
                pc_fail('Photo is too large. Maximum 256 KB.');
            }
            /* find the bot */
            $db = pc_db();
            $bst = $db->prepare('SELECT * FROM users WHERE handle = ?');
            $bst->execute(array('animate_maker_bot'));
            $bot = $bst->fetch();
            if (!$bot) {
                pc_fail('The Animate Maker Bot is not available.');
            }
            /* build the sticker payload — stored as JSON in the txt field */
            $payload = json_encode(array(
                'anim' => $style,
                'img'  => $photo,
                'label'=> ucfirst($style) . ' animation'
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            /* deliver it as a sticker-kind message from the bot to the user */
            pc_queue_message((int) $bot['id'], (int) $me['id'], array(
                'kind' => 'sticker',
                'mid' => isset($body['mid']) ? (int) $body['mid'] : (int) (microtime(true) * 1000),
                'txt' => $payload,
                'tm' => date('H:i'),
            ));
            pc_json(array('ok' => true, 'sticker' => $payload));
            break;

        /* ---------- tickets (server-side, reachable without a session) ---------- */
        case 'ticket.create':
            /* Used by the "Forgot password" dialog on the sign-in page so a
               request can reach the admin even though the requester is not
               signed in. The user must exist, and the requested new password
               must meet the minimum length. */
            $handle = pc_norm_handle(pc_str($body, 'id', 32));
            $pass   = (string) (isset($body['pass']) ? $body['pass'] : '');
            $topic  = pc_str($body, 'topic', 32) ?: 'Password reset';
            $subject = pc_str($body, 'subject', 190) ?: 'Password reset request';
            $note   = pc_str($body, 'note', 4000);
            $db = pc_db();
            $st = $db->prepare('SELECT * FROM users WHERE handle = ?');
            $st->execute(array($handle));
            $u = $st->fetch();
            if (!$u) {
                pc_fail('No account found with the ID @' . $handle . '.');
            }
            if (strlen($pass) < 6) {
                pc_fail('The new password must be at least 6 characters.');
            }
            $id = pc_create_ticket(
                (int) $u['id'],
                '@' . $u['handle'],
                $u['name'],
                $topic,
                $subject,
                $note !== '' ? $note : 'Please change my password to the one requested.',
                $pass
            );
            pc_json(array('ok' => true, 'ticket' => pc_find_ticket($id)));
            break;

        case 'ticket.createAuthed':
            /* Authenticated variant for a signed-in member opening a normal
               support ticket. The user id comes from the token. */
            $me = pc_current_user($body);
            $topic = pc_str($body, 'topic', 32) ?: 'Support';
            $subject = pc_str($body, 'subject', 190);
            $bodyTxt = pc_str($body, 'body', 4000);
            $want = pc_str($body, 'want', 190);
            if (pc_len($subject) < 3) {
                pc_fail('Please add a short subject (3 characters or more).');
            }
            if (pc_len($bodyTxt) < 5) {
                pc_fail('Please describe your request (5 characters or more).');
            }
            if ($topic === 'Password reset' && strlen($want) < 6) {
                pc_fail('The new password must be at least 6 characters.');
            }
            $id = pc_create_ticket(
                (int) $me['id'],
                '@' . $me['handle'],
                $me['name'],
                $topic,
                $subject,
                $bodyTxt,
                $want
            );
            pc_json(array('ok' => true, 'ticket' => pc_find_ticket($id)));
            break;

        case 'ticket.list':
            $me = pc_current_user($body);
            pc_require_admin($me);
            $filter = pc_str($body, 'filter', 12) ?: 'all';
            pc_json(array('ok' => true, 'tickets' => pc_list_tickets($filter)));
            break;

        case 'ticket.my':
            /* A signed-in member's own tickets (so they can track status). */
            $me = pc_current_user($body);
            $all = pc_list_tickets('all');
            $mine = array();
            foreach ($all as $t) {
                if ((int) $t['uid'] === (int) $me['id']) $mine[] = $t;
            }
            pc_json(array('ok' => true, 'tickets' => $mine));
            break;

        case 'ticket.track':
            /* Public lookup by user ID or ticket number — used by the
               "Check a request" dialog on the sign-in page. Returns only
               the requester's own tickets to avoid leaking other people's. */
            $raw = isset($body['q']) ? trim((string) $body['q']) : '';
            $num = (int) fixnum($raw);
            $handle = pc_norm_handle(pc_str($body, 'q', 32));
            $all = pc_list_tickets('all');
            $out = array();
            foreach ($all as $t) {
                if ($num > 0 && (int) $t['id'] === $num) { $out[] = $t; continue; }
                if ($handle !== '' && $handle === pc_norm_handle($t['handle'])) { $out[] = $t; }
            }
            pc_json(array('ok' => true, 'tickets' => $out));
            break;

        case 'ticket.update':
            $me = pc_current_user($body);
            pc_require_admin($me);
            $tid = isset($body['id']) ? (int) $body['id'] : 0;
            $status = pc_str($body, 'status', 12) ?: 'open';
            if (!in_array($status, array('open', 'progress', 'resolved', 'declined'), true)) {
                pc_fail('Invalid ticket status.');
            }
            $note = pc_str($body, 'note', 1000);
            $t = pc_update_ticket($tid, $status, $note);
            if (!$t) {
                pc_fail('Ticket not found.');
            }
            /* If a new password was requested and the admin marks it resolved
               with the requested password, optionally apply it. */
            if (isset($body['applyPassword']) && $body['applyPassword'] && $t['want'] !== '') {
                $peer = pc_find_user((int) $t['uid']);
                if ($peer && $peer['role'] !== 'admin') {
                    $db = pc_db();
                    $db->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')
                        ->execute(array(password_hash($t['want'], PASSWORD_DEFAULT), (int) $peer['id']));
                    $db->prepare('DELETE FROM sessions WHERE user_id = ?')->execute(array((int) $peer['id']));
                }
            }
            pc_json(array('ok' => true, 'ticket' => $t, 'tickets' => pc_list_tickets('all')));
            break;

        /* ---------- chat_index (public groups & channels) ---------- */
        case 'chat.register':
            $me = pc_current_user($body);
            $chatId = pc_norm_handle(pc_str($body, 'chatId', 32));
            $kind = pc_str($body, 'kind', 8) === 'channel' ? 'channel' : 'group';
            $name = pc_str($body, 'name', 64);
            if (!preg_match('/^[a-z0-9._]{3,20}$/', $chatId)) {
                pc_fail('Public chat IDs are 3-20 characters: letters, numbers, dots or underscores.');
            }
            if (pc_len($name) < 2) {
                pc_fail('Give the chat a name (2+ characters).');
            }
            $ok = pc_register_chat($chatId, $kind, $name, (int) $me['id'], '@' . $me['handle']);
            if (!$ok) {
                pc_fail('That ID is already taken. Please pick another one.');
            }
            pc_json(array('ok' => true, 'chat' => pc_find_chat($chatId)));
            break;

        case 'chat.find':
            $me = pc_current_user($body);
            $chatId = pc_norm_handle(pc_str($body, 'chatId', 32));
            if ($chatId === '') {
                pc_fail('Enter a chat ID.');
            }
            $c = pc_find_chat($chatId);
            if (!$c) {
                pc_json(array('ok' => false, 'found' => false, 'error' => 'No chat found with the ID ' . $chatId . '.'));
            }
            /* include the user's role in this chat (if they're a member) */
            $c['myRole'] = pc_get_member_role($chatId, (int) $me['id']);
            $c['isMember'] = $c['myRole'] !== null;
            /* include the member's joinedAt timestamp so the client can
               fetch group history only for messages sent after they joined */
            $c['joinedAt'] = 0;
            if ($c['isMember']) {
                $db = pc_db();
                $jm = $db->prepare('SELECT joined_at FROM chat_members WHERE chat_id = ? AND user_id = ?');
                $jm->execute(array($chatId, (int) $me['id']));
                $jr = $jm->fetch();
                if ($jr) $c['joinedAt'] = (int) $jr['joined_at'];
            }
            /* include a member count so the UI can show "N members" */
            $c['memberCount'] = count(pc_get_members($chatId));
            pc_json(array('ok' => true, 'found' => true, 'chat' => $c));
            break;

        case 'chat.history':
            /* Returns the message history for a chat.
               - For CHANNELS: any authenticated user can read all messages
                 (channels are public — like Telegram).
               - For GROUPS: only members can read; messages are limited to
                 those sent after the user joined (so they don't see
                 history from before they were a member). */
            $me = pc_current_user($body);
            $chatId = pc_norm_handle(pc_str($body, 'chatId', 32));
            $since = isset($body['since']) ? (int) $body['since'] : 0;
            $c = pc_find_chat($chatId);
            if (!$c) pc_fail('Chat not found.');
            if ($c['kind'] === 'group') {
                /* groups: only members can read history */
                $role = pc_get_member_role($chatId, (int) $me['id']);
                if ($role === null) {
                    pc_fail('Join this group to see its messages.', 403);
                }
                /* enforce the joined-at cutoff */
                if ($since <= 0) {
                    $db = pc_db();
                    $jm = $db->prepare('SELECT joined_at FROM chat_members WHERE chat_id = ? AND user_id = ?');
                    $jm->execute(array($chatId, (int) $me['id']));
                    $jr = $jm->fetch();
                    if ($jr && (int) $jr['joined_at'] > 0) {
                        $since = (int) $jr['joined_at'];
                    }
                }
            }
            /* channels: anyone can read — no membership required */
            $messages = pc_get_chat_history($chatId, $since, 200);
            pc_json(array('ok' => true, 'messages' => $messages, 'chat' => $c));
            break;

        /* ---------- chat membership (Telegram-style groups & channels) ---------- */
        case 'chat.join':
            /* For CHANNELS: join directly (no request needed — like Telegram).
               For GROUPS: send a join request that the owner must approve. */
            $me = pc_current_user($body);
            $chatId = pc_norm_handle(pc_str($body, 'chatId', 32));
            $c = pc_find_chat($chatId);
            if (!$c) pc_fail('Chat not found.');
            if ($c['kind'] === 'channel') {
                /* Channel: direct join */
                if (pc_is_member($chatId, (int) $me['id'])) {
                    pc_fail('You are already a member.');
                }
                pc_add_member($chatId, (int) $me['id'], 'member');
                pc_json(array('ok' => true, 'message' => 'You joined the channel.'));
            } else {
                /* Group: join by request */
                $result = pc_create_join_request($chatId, (int) $me['id']);
                if (!$result['ok']) pc_fail($result['error']);
                /* notify the owner via bot message */
                $msg = "New join request\n\n" . $me['name'] . " (@" . $me['handle'] . ") wants to join group \"" . $c['name'] . "\".\n\nReview it in the group settings.";
                pc_queue_bot_message('premium_bot', (int) $c['ownerId'], $msg);
                pc_json(array('ok' => true, 'message' => 'Join request sent. The owner will review it.'));
            }
            break;

        case 'chat.approve':
            /* Approve a join request. Only owner or admin can do this. */
            $me = pc_current_user($body);
            $chatId = pc_norm_handle(pc_str($body, 'chatId', 32));
            $targetUid = isset($body['userId']) ? (int) $body['userId'] : 0;
            $c = pc_find_chat($chatId);
            if (!$c) pc_fail('Chat not found.');
            $myRole = pc_get_member_role($chatId, (int) $me['id']);
            if ($myRole !== 'owner' && $myRole !== 'admin') {
                pc_fail('Only the owner or admins can approve join requests.', 403);
            }
            pc_approve_join_request($chatId, $targetUid, 'member');
            /* notify the user */
            $msg = "Join request approved\n\nYou are now a member of \"" . $c['name'] . "\".";
            pc_queue_bot_message('premium_bot', $targetUid, $msg);
            pc_json(array('ok' => true, 'members' => pc_get_members($chatId), 'requests' => pc_get_join_requests($chatId)));
            break;

        case 'chat.decline':
            /* Decline a join request. Only owner or admin can do this. */
            $me = pc_current_user($body);
            $chatId = pc_norm_handle(pc_str($body, 'chatId', 32));
            $targetUid = isset($body['userId']) ? (int) $body['userId'] : 0;
            $c = pc_find_chat($chatId);
            if (!$c) pc_fail('Chat not found.');
            $myRole = pc_get_member_role($chatId, (int) $me['id']);
            if ($myRole !== 'owner' && $myRole !== 'admin') {
                pc_fail('Only the owner or admins can decline join requests.', 403);
            }
            pc_decline_join_request($chatId, $targetUid);
            pc_json(array('ok' => true, 'requests' => pc_get_join_requests($chatId)));
            break;

        case 'chat.members':
            /* List all members of a chat. */
            $me = pc_current_user($body);
            $chatId = pc_norm_handle(pc_str($body, 'chatId', 32));
            pc_json(array('ok' => true, 'members' => pc_get_members($chatId)));
            break;

        case 'chat.requests':
            /* List pending join requests. Only owner or admin. */
            $me = pc_current_user($body);
            $chatId = pc_norm_handle(pc_str($body, 'chatId', 32));
            $myRole = pc_get_member_role($chatId, (int) $me['id']);
            if ($myRole !== 'owner' && $myRole !== 'admin') {
                pc_fail('Only the owner or admins can view join requests.', 403);
            }
            pc_json(array('ok' => true, 'requests' => pc_get_join_requests($chatId)));
            break;

        case 'chat.promote':
            /* Promote a member to admin. Only owner can do this. */
            $me = pc_current_user($body);
            $chatId = pc_norm_handle(pc_str($body, 'chatId', 32));
            $targetUid = isset($body['userId']) ? (int) $body['userId'] : 0;
            $myRole = pc_get_member_role($chatId, (int) $me['id']);
            if ($myRole !== 'owner') {
                pc_fail('Only the owner can promote members.', 403);
            }
            pc_add_member($chatId, $targetUid, 'admin');
            $c = pc_find_chat($chatId);
            $msg = "You are now an admin\n\nYou have been promoted to admin in \"" . $c['name'] . "\".";
            pc_queue_bot_message('premium_bot', $targetUid, $msg);
            pc_json(array('ok' => true, 'members' => pc_get_members($chatId)));
            break;

        case 'chat.demote':
            /* Demote an admin back to member. Only owner can do this. */
            $me = pc_current_user($body);
            $chatId = pc_norm_handle(pc_str($body, 'chatId', 32));
            $targetUid = isset($body['userId']) ? (int) $body['userId'] : 0;
            $myRole = pc_get_member_role($chatId, (int) $me['id']);
            if ($myRole !== 'owner') {
                pc_fail('Only the owner can demote admins.', 403);
            }
            pc_add_member($chatId, $targetUid, 'member');
            pc_json(array('ok' => true, 'members' => pc_get_members($chatId)));
            break;

        case 'chat.kick':
            /* Remove a member from the chat. Only owner or admin. */
            $me = pc_current_user($body);
            $chatId = pc_norm_handle(pc_str($body, 'chatId', 32));
            $targetUid = isset($body['userId']) ? (int) $body['userId'] : 0;
            $myRole = pc_get_member_role($chatId, (int) $me['id']);
            if ($myRole !== 'owner' && $myRole !== 'admin') {
                pc_fail('Only the owner or admins can remove members.', 403);
            }
            $targetRole = pc_get_member_role($chatId, $targetUid);
            if ($targetRole === 'owner') {
                pc_fail('The owner cannot be removed.', 403);
            }
            pc_remove_member($chatId, $targetUid);
            pc_json(array('ok' => true, 'members' => pc_get_members($chatId)));
            break;

        case 'chat.canPost':
            /* Check if the current user can post in a chat. */
            $me = pc_current_user($body);
            $chatId = pc_norm_handle(pc_str($body, 'chatId', 32));
            $canPost = pc_can_post($chatId, (int) $me['id']);
            pc_json(array('ok' => true, 'canPost' => $canPost));
            break;

        case 'chat.post':
            /* Post a message to a group or channel. The message is
               delivered to ALL members via the messages table AND stored
               in chat_messages for history. CRITICAL: the delivered message
               includes a %%CHAT:chatId%% prefix so the recipient's client
               knows to route it to the group/channel chat — NOT create a
               DM with the sender. */
            $me = pc_current_user($body);
            $chatId = pc_norm_handle(pc_str($body, 'chatId', 32));
            $txt = pc_str($body, 'txt', 262144);
            $tm = pc_str($body, 'tm', 24);
            $mid = isset($body['mid']) ? (int) $body['mid'] : (int) (microtime(true) * 1000);
            $c = pc_find_chat($chatId);
            if (!$c) pc_fail('Chat not found.');
            /* Check posting permission */
            if (!pc_can_post($chatId, (int) $me['id'])) {
                pc_fail('You do not have permission to post in this ' . $c['kind'] . '.', 403);
            }
            /* Persist to chat_messages so the history is available */
            pc_store_chat_message($chatId, (int) $me['id'], $me['name'], $me['handle'], $mid, $txt, $tm, 'msg');
            /* Build the delivery payload with the chat routing prefix.
               This tells the recipient's applyIncoming() to put the message
               in the group/channel thread, NOT in a DM with the sender. */
            $routedTxt = '%%CHAT:' . $chatId . '%%' . $txt;
            /* Get all members and deliver the message to each one */
            $members = pc_get_members($chatId);
            $delivered = 0;
            foreach ($members as $member) {
                /* Don't deliver to the sender themselves — they already
                   have the message locally. */
                if ((int) $member['userId'] === (int) $me['id']) continue;
                pc_queue_message((int) $me['id'], (int) $member['userId'], array(
                    'kind' => 'msg',
                    'mid' => $mid,
                    'txt' => $routedTxt,
                    'tm' => $tm
                ));
                $delivered++;
            }
            pc_json(array('ok' => true, 'delivered' => $delivered));
            break;

        /* ---------- AI (PrivateAI bot) ---------- */
        case 'ai.chat':
            /* Send a message to PrivateAI. Free users get 3/day, Pro
               users get unlimited. Requires the admin to have set an
               OpenAI API key in the bot config. */
            $me = pc_current_user($body);
            $message = pc_str($body, 'message', 2000);
            if ($message === '') pc_fail('Message is empty.');
            if (!pc_ai_can_send((int) $me['id'])) {
                $used = pc_ai_usage_today((int) $me['id']);
                pc_fail('Daily limit reached (' . $used . '/3). Upgrade to Pro for unlimited AI messages.', 429);
            }
            $cfg = pc_load_botconfig();
            $apiKey = isset($cfg['openaiApiKey']) ? $cfg['openaiApiKey'] : '';
            $model = isset($cfg['openaiModel']) && $cfg['openaiModel'] ? $cfg['openaiModel'] : 'gpt-4o-mini';
            $baseUrl = isset($cfg['openaiBaseUrl']) && $cfg['openaiBaseUrl'] ? trim($cfg['openaiBaseUrl']) : 'https://api.openai.com/v1';
            if ($apiKey === '') {
                pc_fail('AI is not configured. The administrator needs to set an OpenAI API key.');
            }
            /* normalize the base URL: strip trailing slashes, ensure it ends
               with /v1 (append if the user provided just the host). This
               lets the admin point to any OpenAI-compatible endpoint —
               Azure OpenAI, OpenRouter, Together AI, a local LLM, etc. */
            $baseUrl = rtrim($baseUrl, '/');
            if (substr($baseUrl, -3) !== '/v1') {
                /* if it already ends with /v1/, the rtrim above removed the
                   trailing slash, so we just append /v1 if not present. */
                if (substr($baseUrl, -2) !== 'v1') {
                    $baseUrl .= '/v1';
                }
            }
            $endpoint = $baseUrl . '/chat/completions';
            /* increment usage */
            pc_ai_increment_usage((int) $me['id']);
            /* call OpenAI-compatible API */
            $payload = json_encode(array(
                'model' => $model,
                'messages' => array(
                    array('role' => 'system', 'content' => 'You are PrivateAI, a helpful AI assistant in a messaging app called Private Chat. Keep responses concise and friendly (max 3-4 paragraphs). Respond in the same language the user uses.'),
                    array('role' => 'user', 'content' => $message)
                ),
                'max_tokens' => 1500,
                'temperature' => 0.7
            ));
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey
                ),
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10
            ));
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);
            if ($resp === false) {
                pc_fail('AI request failed: ' . $curlErr);
            }
            /* Some AI providers append "data: [DONE]" after the JSON
               response. Strip anything after the last closing brace so
               json_decode can parse it. */
            $resp = trim($resp);
            if (substr($resp, 0, 1) === '{') {
                $lastBrace = strrpos($resp, '}');
                if ($lastBrace !== false) {
                    $resp = substr($resp, 0, $lastBrace + 1);
                }
            }
            $decoded = json_decode($resp, true);
            if ($httpCode !== 200 || !is_array($decoded) || !isset($decoded['choices'][0]['message'])) {
                $errMsg = isset($decoded['error']['message']) ? $decoded['error']['message'] : 'Unknown error (HTTP ' . $httpCode . ')';
                pc_fail('AI error: ' . $errMsg);
            }
            /* Some reasoning models (e.g. mimo-hermes, o1-style) return
               content=null and put the actual response in "reasoning".
               Fall back to reasoning if content is null/empty. */
            $aiReply = $decoded['choices'][0]['message']['content'];
            if ($aiReply === null || $aiReply === '') {
                $aiReply = isset($decoded['choices'][0]['message']['reasoning'])
                    ? $decoded['choices'][0]['message']['reasoning'] : '';
            }
            /* If still empty, check for text field (some providers) */
            if ($aiReply === '' && isset($decoded['choices'][0]['text'])) {
                $aiReply = $decoded['choices'][0]['text'];
            }
            if ($aiReply === '') {
                pc_fail('AI returned an empty response. Try a different model or increase max_tokens.');
            }
            /* Truncate very long responses to keep the chat readable */
            if (function_exists('mb_strlen') && mb_strlen($aiReply, 'UTF-8') > 2000) {
                $aiReply = mb_substr($aiReply, 0, 2000, 'UTF-8') . '…';
            } else if (strlen($aiReply) > 2000) {
                $aiReply = substr($aiReply, 0, 2000) . '…';
            }
            $remaining = pc_ai_can_send((int) $me['id']) ? (3 - pc_ai_usage_today((int) $me['id'])) : 0;
            if ((int) $me['pro_days'] > 0) $remaining = -1; /* unlimited */
            pc_json(array(
                'ok' => true,
                'reply' => $aiReply,
                'remaining' => $remaining,
                'used' => pc_ai_usage_today((int) $me['id'])
            ));
            break;

        case 'ai.status':
            /* Check AI usage and limits for the current user. */
            $me = pc_current_user($body);
            $used = pc_ai_usage_today((int) $me['id']);
            $isPro = (int) $me['pro_days'] > 0;
            $limit = $isPro ? -1 : 3;
            $remaining = $isPro ? -1 : max(0, 3 - $used);
            $cfg = pc_load_botconfig();
            pc_json(array(
                'ok' => true,
                'used' => $used,
                'limit' => $limit,
                'remaining' => $remaining,
                'isPro' => $isPro,
                'configured' => !empty($cfg['openaiApiKey'])
            ));
            break;

        case 'user.aliases':
            /* Get all aliases for the current user (primary + premium). */
            $me = pc_current_user($body);
            $aliases = pc_get_aliases((int) $me['id']);
            pc_json(array('ok' => true, 'aliases' => $aliases));
            break;

        case '':
            pc_fail('No action given.', 400);
            break;

        default:
            pc_fail('Unknown action: ' . $action, 404);
    }
} catch (Exception $e) {
    pc_fail('Server error' . (DEBUG_ERRORS ? ': ' . $e->getMessage() : '. Enable DEBUG_ERRORS in config.php for details.'), 500);
}
