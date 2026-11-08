<?php
/* ============================================================
   Private Chat - shared library
   Real database: SQLite by default (MySQL optional).
   Every account owns its own data row, and message delivery
   between accounts goes through the messages table.
   Works on PHP 7.1 ... 8.3
   ============================================================ */

require_once __DIR__ . '/config.php';

if (DEBUG_ERRORS) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function pc_now_ms()
{
    return (int) round(microtime(true) * 1000);
}

function pc_json($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pc_fail($message, $code = 400, $extra = array())
{
    $out = array('ok' => false, 'error' => $message);
    foreach ($extra as $k => $v) {
        $out[$k] = $v;
    }
    pc_json($out, $code);
}

function pc_db()
{
    static $db = null;
    if ($db !== null) {
        return $db;
    }
    try {
        if (DB_DRIVER === 'sqlite') {
            $dir = dirname(SQLITE_PATH);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $db = new PDO('sqlite:' . SQLITE_PATH);
            $db->exec('PRAGMA journal_mode=WAL');
            $db->exec('PRAGMA busy_timeout=15000');
            $db->exec('PRAGMA foreign_keys=ON');
            $db->exec('PRAGMA synchronous=NORMAL');
        } else {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $db = new PDO($dsn, DB_USER, DB_PASS);
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    } catch (Exception $e) {
        pc_fail('Database connection failed. Check config.php.' . (DEBUG_ERRORS ? ' ' . $e->getMessage() : ''), 500);
    }
    pc_bootstrap($db);
    return $db;
}

/**
 * Self-installing database.
 * The very first request creates the SQLite file, all tables and the
 * administrator account. install.php is optional, never required.
 */
function pc_bootstrap($db)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $needTables = false;
    try {
        $db->query('SELECT 1 FROM users LIMIT 1')->fetch();
    } catch (Exception $e) {
        $needTables = true;
    }
    if ($needTables) {
        try {
            foreach (pc_install_sql() as $sql) {
                $db->exec($sql);
            }
        } catch (Exception $e) {
            pc_fail('The database could not be created. Make sure the data/ folder is writable (chmod 755 or 777).'
                . (DEBUG_ERRORS ? ' ' . $e->getMessage() : ''), 500);
        }
    }
    /* make sure the administrator always exists */
    try {
        $handle = pc_norm_handle(ADMIN_ID);
        $st = $db->prepare('SELECT id FROM users WHERE handle = ?');
        $st->execute(array($handle));
        if (!$st->fetch()) {
            pc_create_user(ADMIN_NAME, $handle, ADMIN_PASS, 'admin');
        }
    } catch (Exception $e) {
        pc_fail('The administrator account could not be created.'
            . (DEBUG_ERRORS ? ' ' . $e->getMessage() : ''), 500);
    }
}

/* The "health" endpoint and its helper pc_db_report() were removed:
   they exposed the SQLite file path, the number of accounts and the
   number of stored messages to anyone hitting api.php without a token,
   which is not something a sign-in page should leak. Use install.php
   (server-side only) or the in-app Diagnostics screen instead. */

function pc_install_sql()
{
    if (DB_DRIVER === 'sqlite') {
        return array(
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                handle TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                pass_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT \'member\',
                status TEXT NOT NULL DEFAULT \'active\',
                pro_days INTEGER NOT NULL DEFAULT 0,
                reports INTEGER NOT NULL DEFAULT 0,
                avatar INTEGER NOT NULL DEFAULT 0,
                bio TEXT NOT NULL DEFAULT \'\',
                created_at INTEGER NOT NULL DEFAULT 0,
                last_seen INTEGER NOT NULL DEFAULT 0
            )',
            'CREATE TABLE IF NOT EXISTS sessions (
                token TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                agent TEXT,
                ip TEXT,
                created_at INTEGER NOT NULL DEFAULT 0,
                seen_at INTEGER NOT NULL DEFAULT 0
            )',
            'CREATE TABLE IF NOT EXISTS app_state (
                user_id INTEGER PRIMARY KEY,
                version INTEGER NOT NULL DEFAULT 0,
                data TEXT NOT NULL,
                updated_at INTEGER NOT NULL DEFAULT 0
            )',
            'CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sender_id INTEGER NOT NULL,
                recipient_id INTEGER NOT NULL,
                kind TEXT NOT NULL DEFAULT \'msg\',
                mid INTEGER NOT NULL DEFAULT 0,
                ref INTEGER NOT NULL DEFAULT 0,
                txt TEXT,
                tm TEXT,
                created_at INTEGER NOT NULL DEFAULT 0
            )',
            'CREATE TABLE IF NOT EXISTS login_fails (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip TEXT,
                handle TEXT,
                at INTEGER NOT NULL DEFAULT 0
            )',
            'CREATE TABLE IF NOT EXISTS tickets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uid INTEGER NOT NULL DEFAULT 0,
                handle TEXT NOT NULL DEFAULT \'\',
                name TEXT NOT NULL DEFAULT \'\',
                topic TEXT NOT NULL DEFAULT \'\',
                subject TEXT NOT NULL DEFAULT \'\',
                body TEXT NOT NULL DEFAULT \'\',
                want TEXT NOT NULL DEFAULT \'\',
                status TEXT NOT NULL DEFAULT \'open\',
                notes TEXT NOT NULL DEFAULT \'[]\',
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )',
            'CREATE TABLE IF NOT EXISTS chat_index (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id TEXT NOT NULL UNIQUE,
                kind TEXT NOT NULL DEFAULT \'group\',
                name TEXT NOT NULL DEFAULT \'\',
                owner_id INTEGER NOT NULL DEFAULT 0,
                owner_handle TEXT NOT NULL DEFAULT \'\',
                created_at INTEGER NOT NULL DEFAULT 0
            )',
            'CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions (user_id)',
            'CREATE INDEX IF NOT EXISTS idx_msg_to ON messages (recipient_id, id)',
            'CREATE INDEX IF NOT EXISTS idx_fails_at ON login_fails (at)',
            'CREATE INDEX IF NOT EXISTS idx_tickets_status ON tickets (status, updated_at)',
            'CREATE INDEX IF NOT EXISTS idx_chat_index_kind ON chat_index (kind, chat_id)'
        );
    }
    return array(
        'CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            handle VARCHAR(32) NOT NULL,
            name VARCHAR(64) NOT NULL,
            pass_hash VARCHAR(255) NOT NULL,
            role VARCHAR(10) NOT NULL DEFAULT \'member\',
            status VARCHAR(12) NOT NULL DEFAULT \'active\',
            pro_days INT NOT NULL DEFAULT 0,
            reports INT NOT NULL DEFAULT 0,
            avatar INT NOT NULL DEFAULT 0,
            bio VARCHAR(255) NOT NULL DEFAULT \'\',
            created_at BIGINT NOT NULL DEFAULT 0,
            last_seen BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_handle (handle)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS sessions (
            token CHAR(64) NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            agent VARCHAR(190) NULL,
            ip VARCHAR(45) NULL,
            created_at BIGINT NOT NULL DEFAULT 0,
            seen_at BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (token),
            KEY idx_sessions_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS app_state (
            user_id INT UNSIGNED NOT NULL,
            version INT UNSIGNED NOT NULL DEFAULT 0,
            data LONGTEXT NOT NULL,
            updated_at BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sender_id INT UNSIGNED NOT NULL,
            recipient_id INT UNSIGNED NOT NULL,
            kind VARCHAR(8) NOT NULL DEFAULT \'msg\',
            mid BIGINT NOT NULL DEFAULT 0,
            ref BIGINT NOT NULL DEFAULT 0,
            txt MEDIUMTEXT NULL,
            tm VARCHAR(24) NULL,
            created_at BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_msg_to (recipient_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS login_fails (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip VARCHAR(45) NULL,
            handle VARCHAR(32) NULL,
            at BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_fails_at (at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS tickets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            uid INT UNSIGNED NOT NULL DEFAULT 0,
            handle VARCHAR(32) NOT NULL DEFAULT \'\',
            name VARCHAR(64) NOT NULL DEFAULT \'\',
            topic VARCHAR(32) NOT NULL DEFAULT \'\',
            subject VARCHAR(190) NOT NULL DEFAULT \'\',
            body TEXT NULL,
            want VARCHAR(190) NULL,
            status VARCHAR(12) NOT NULL DEFAULT \'open\',
            notes TEXT NULL,
            created_at BIGINT NOT NULL DEFAULT 0,
            updated_at BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_tickets_status (status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS chat_index (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            chat_id VARCHAR(32) NOT NULL,
            kind VARCHAR(8) NOT NULL DEFAULT \'group\',
            name VARCHAR(64) NOT NULL DEFAULT \'\',
            owner_id INT UNSIGNED NOT NULL DEFAULT 0,
            owner_handle VARCHAR(32) NOT NULL DEFAULT \'\',
            created_at BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_chat_id (chat_id),
            KEY idx_chat_index_kind (kind, chat_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function pc_install()
{
    $db = pc_db();
    foreach (pc_install_sql() as $sql) {
        $db->exec($sql);
    }
    $handle = pc_norm_handle(ADMIN_ID);
    $st = $db->prepare('SELECT id FROM users WHERE handle = ?');
    $st->execute(array($handle));
    $admin = $st->fetch();
    if (!$admin) {
        pc_create_user(ADMIN_NAME, $handle, ADMIN_PASS, 'admin');
        return 'created';
    }
    return 'exists';
}

/**
 * A brand new account starts completely empty:
 * no contacts, no chats, no tickets - only its own Saved Messages.
 */
function pc_empty_state()
{
    $saved = array(
        'id' => 'c0',
        'name' => 'Saved Messages',
        'g' => 0,
        'on' => false,
        't' => '',
        'unread' => 0,
        'kind' => 'saved',
        'pv' => 'Your personal cloud notes',
        'sub' => 'Only visible to you',
        'pin' => true
    );
    return array(
        'CHATS' => array($saved),
        'THREADS' => array('c0' => array()),
        'MID' => 0,
        'TICKETS' => array(),
        'TKID' => 1001,
        'AUDIT' => array(),
        'DEVICES' => array()
    );
}

function pc_norm_handle($raw)
{
    $h = strtolower(trim((string) $raw));
    $h = preg_replace('/^@+/', '', $h);
    $map = array('۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9');
    return strtr($h, $map);
}

function pc_valid_handle($h)
{
    return (bool) preg_match('/^[a-z0-9._]{3,20}$/', $h);
}

function pc_client_ip()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    return substr((string) $ip, 0, 45);
}

function pc_agent()
{
    $a = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    return substr((string) $a, 0, 180);
}

function pc_public_user($row)
{
    $lastSeen = isset($row['last_seen']) ? (int) $row['last_seen'] : 0;
    return array(
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'handle' => '@' . $row['handle'],
        'role' => $row['role'],
        'status' => $row['status'],
        'proDays' => (int) $row['pro_days'],
        'reports' => (int) $row['reports'],
        'avatar' => isset($row['avatar']) ? (int) $row['avatar'] : 0,
        'bio' => isset($row['bio']) ? (string) $row['bio'] : '',
        'avatarUrl' => isset($row['avatar_url']) ? (string) $row['avatar_url'] : '',
        'isBot' => isset($row['is_bot']) ? (int) $row['is_bot'] : 0,
        'verified' => isset($row['verified']) ? (int) $row['verified'] : 0,
        'goldVerified' => isset($row['gold_verified']) ? (int) $row['gold_verified'] : 0,
        'proTier' => isset($row['pro_tier']) ? (string) $row['pro_tier'] : '',
        'last' => pc_last_seen_text($lastSeen),
        'lastSeenMs' => $lastSeen
    );
}

/**
 * pc_ensure_column: best-effort migration for existing installs that were
 * created before avatar/bio columns were added. Silent on failure.
 */
function pc_ensure_column($table, $column, $ddl)
{
    $db = pc_db();
    try {
        $db->exec($ddl);
    } catch (Exception $e) {
        /* column probably already exists */
    }
}

/**
 * Run once per request to make sure the schema is current. Idempotent.
 */
function pc_ensure_schema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db = pc_db();
    /* add avatar/bio columns to legacy users tables (silent if already there) */
    pc_ensure_column('users', 'avatar', 'ALTER TABLE users ADD COLUMN avatar INTEGER NOT NULL DEFAULT 0');
    pc_ensure_column('users', 'bio', "ALTER TABLE users ADD COLUMN bio TEXT NOT NULL DEFAULT ''");
    pc_ensure_column('users', 'avatar_url', 'ALTER TABLE users ADD COLUMN avatar_url TEXT NOT NULL DEFAULT \'\'');
    pc_ensure_column('users', 'is_bot', 'ALTER TABLE users ADD COLUMN is_bot INTEGER NOT NULL DEFAULT 0');
    pc_ensure_column('users', 'verified', 'ALTER TABLE users ADD COLUMN verified INTEGER NOT NULL DEFAULT 0');
    pc_ensure_column('users', 'gold_verified', 'ALTER TABLE users ADD COLUMN gold_verified INTEGER NOT NULL DEFAULT 0');
    pc_ensure_column('users', 'pro_tier', 'ALTER TABLE users ADD COLUMN pro_tier TEXT NOT NULL DEFAULT \'\'');
    /* create the tickets table if missing (legacy installs) */
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS tickets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uid INTEGER NOT NULL DEFAULT 0,
            handle TEXT NOT NULL DEFAULT '',
            name TEXT NOT NULL DEFAULT '',
            topic TEXT NOT NULL DEFAULT '',
            subject TEXT NOT NULL DEFAULT '',
            body TEXT NOT NULL DEFAULT '',
            want TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'open',
            notes TEXT NOT NULL DEFAULT '[]',
            created_at INTEGER NOT NULL DEFAULT 0,
            updated_at INTEGER NOT NULL DEFAULT 0
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_tickets_status ON tickets (status, updated_at)");
    } catch (Exception $e) { /* ignore */ }
    /* create the chat_index table if missing */
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS chat_index (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chat_id TEXT NOT NULL UNIQUE,
            kind TEXT NOT NULL DEFAULT 'group',
            name TEXT NOT NULL DEFAULT '',
            owner_id INTEGER NOT NULL DEFAULT 0,
            owner_handle TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL DEFAULT 0
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_chat_index_kind ON chat_index (kind, chat_id)");
    } catch (Exception $e) { /* ignore */ }

    /* make sure the animate_maker_bot account always exists */
    pc_ensure_bot($db, 'animate_maker_bot', 'Animate Maker Bot',
        "🎨 Welcome to Animate Maker Bot! Turn any photo into a live, animated sticker in seconds. Tap Start to begin.");

    /* make sure the premium_bot account always exists */
    pc_ensure_bot($db, 'premium_bot', 'Premium Bot',
        "👋 Welcome to Super Private! I'm your personal premium assistant — tap a button to begin.");

    /* make sure the super_bot account always exists (separate ID) */
    pc_ensure_bot($db, 'super_bot', 'Super Bot',
        "⚡ Hey, I'm Super Bot — your gateway to premium features and exclusive IDs.");

    /* make sure the PrivateAI bot account always exists.
       This bot is accessible via multiple handles: privateai, aibot, ai, superai.
       All handles route to the same bot user (handle=privateai). */
    pc_ensure_bot($db, 'privateai', 'PrivateAI',
        "🤖 Hi, I'm PrivateAI — your AI assistant. Ask me anything!");

    /* create the user_aliases table if it doesn't exist.
       This lets a user have multiple IDs — e.g. their primary handle
       plus premium IDs they purchased. All aliases map to the same
       user account, so the user can sign in with any of them. */
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS user_aliases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            handle TEXT NOT NULL UNIQUE,
            is_premium INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL DEFAULT 0
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_aliases_user ON user_aliases (user_id)");
    } catch (Exception $e) { /* ignore */ }

    /* create the ai_usage table for tracking daily AI message limits.
       Free users get 3 messages/day; Pro users get unlimited. */
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS ai_usage (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            day TEXT NOT NULL,
            count INTEGER NOT NULL DEFAULT 0,
            UNIQUE(user_id, day)
        )");
    } catch (Exception $e) { /* ignore */ }

    /* create the chat_members table for group/channel membership.
       role: 'owner', 'admin', 'member' */
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS chat_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chat_id TEXT NOT NULL,
            user_id INTEGER NOT NULL,
            role TEXT NOT NULL DEFAULT 'member',
            joined_at INTEGER NOT NULL DEFAULT 0,
            UNIQUE(chat_id, user_id)
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_members_chat ON chat_members (chat_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_members_user ON chat_members (user_id)");
    } catch (Exception $e) { /* ignore */ }

    /* create the join_requests table for group/channel join requests. */
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS join_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chat_id TEXT NOT NULL,
            user_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            created_at INTEGER NOT NULL DEFAULT 0,
            resolved_at INTEGER NOT NULL DEFAULT 0,
            UNIQUE(chat_id, user_id)
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_joinreq_chat ON join_requests (chat_id, status)");
    } catch (Exception $e) { /* ignore */ }

    /* create the chat_messages table — stores ALL messages posted to a
       group or channel, regardless of who is a member at the time.
       This enables channel history (non-members can read past messages)
       and group history-after-join (members see only messages sent after
       they joined). Each row is one broadcast message. */
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            chat_id TEXT NOT NULL,
            sender_id INTEGER NOT NULL,
            sender_name TEXT NOT NULL DEFAULT '',
            sender_handle TEXT NOT NULL DEFAULT '',
            mid INTEGER NOT NULL DEFAULT 0,
            txt TEXT NOT NULL DEFAULT '',
            tm TEXT NOT NULL DEFAULT '',
            kind TEXT NOT NULL DEFAULT 'msg',
            created_at INTEGER NOT NULL DEFAULT 0
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_chatmsg_chat ON chat_messages (chat_id, id)");
    } catch (Exception $e) { /* ignore */ }
}

/* Helper: ensure a bot account exists. If the bot already exists, update
   its name and bio so new welcome messages are applied on upgrade. */
function pc_ensure_bot($db, $handle, $name, $bio)
{
    try {
        $st = $db->prepare('SELECT id FROM users WHERE handle = ?');
        $st->execute(array($handle));
        $existing = $st->fetch();
        if (!$existing) {
            $now = pc_now_ms();
            $ins = $db->prepare('INSERT INTO users (handle, name, pass_hash, role, status, pro_days, reports, avatar, bio, avatar_url, is_bot, verified, created_at, last_seen)
                VALUES (?, ?, ?, ?, ?, 0, 0, 0, ?, ?, 1, 1, ?, ?)');
            $ins->execute(array(
                $handle, $name,
                password_hash(pc_new_token(), PASSWORD_DEFAULT),
                'member', 'active', $bio, '', $now, $now
            ));
        } else {
            /* Update the name and bio so new welcome messages take effect
               for existing installs. Don't touch pass_hash, role, etc. */
            $upd = $db->prepare('UPDATE users SET name = ?, bio = ? WHERE handle = ? AND is_bot = 1');
            $upd->execute(array($name, $bio, $handle));
        }
    } catch (Exception $e) { /* ignore */ }
}

/* ---------------- user aliases (multiple IDs per user) ---------------- */

/* Add an alias handle for a user. The alias becomes an alternate ID
   they can sign in with. Premium aliases show a gold badge. */
function pc_add_alias($userId, $handle, $isPremium = 0)
{
    $db = pc_db();
    $handle = pc_norm_handle($handle);
    if (!pc_valid_handle($handle)) return false;
    /* check it's not already taken by another user or as an alias */
    $st = $db->prepare('SELECT id FROM users WHERE handle = ? AND id <> ?');
    $st->execute(array($handle, (int) $userId));
    if ($st->fetch()) return false;
    $st2 = $db->prepare('SELECT user_id FROM user_aliases WHERE handle = ? AND user_id <> ?');
    $st2->execute(array($handle, (int) $userId));
    if ($st2->fetch()) return false;
    /* insert or update */
    try {
        $ins = $db->prepare('INSERT OR REPLACE INTO user_aliases (user_id, handle, is_premium, created_at) VALUES (?, ?, ?, ?)');
        $ins->execute(array((int) $userId, $handle, $isPremium ? 1 : 0, pc_now_ms()));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/* Get all aliases for a user (including their primary handle). */
function pc_get_aliases($userId)
{
    $db = pc_db();
    $out = array();
    /* primary handle */
    $st = $db->prepare('SELECT handle FROM users WHERE id = ?');
    $st->execute(array((int) $userId));
    $row = $st->fetch();
    if ($row) {
        $out[] = array('handle' => $row['handle'], 'is_premium' => 0, 'is_primary' => 1);
    }
    /* aliases */
    $st2 = $db->prepare('SELECT handle, is_premium FROM user_aliases WHERE user_id = ? ORDER BY created_at ASC');
    $st2->execute(array((int) $userId));
    foreach ($st2 as $r) {
        $out[] = array('handle' => $r['handle'], 'is_premium' => (int) $r['is_premium'], 'is_primary' => 0);
    }
    return $out;
}

/* Resolve a handle to a user ID. Checks the primary handle in users
   table AND the user_aliases table. Returns the user row or null. */
function pc_find_user_by_handle_or_alias($handle)
{
    $handle = pc_norm_handle($handle);
    $db = pc_db();
    /* check primary handle */
    $st = $db->prepare('SELECT * FROM users WHERE handle = ?');
    $st->execute(array($handle));
    $row = $st->fetch();
    if ($row) return $row;
    /* check aliases */
    $st2 = $db->prepare('SELECT u.* FROM user_aliases a JOIN users u ON u.id = a.user_id WHERE a.handle = ?');
    $st2->execute(array($handle));
    return $st2->fetch() ?: null;
}

/* Check if a handle is an AI bot alias. The PrivateAI bot is accessible
   via: privateai, aibot, ai, superai. */
function pc_is_ai_handle($handle)
{
    $handle = pc_norm_handle($handle);
    return in_array($handle, array('privateai', 'aibot', 'ai', 'superai'), true);
}

/* ---------------- AI usage tracking ---------------- */

/* Get the number of AI messages a user has sent today. */
function pc_ai_usage_today($userId)
{
    $db = pc_db();
    $day = date('Y-m-d');
    $st = $db->prepare('SELECT count FROM ai_usage WHERE user_id = ? AND day = ?');
    $st->execute(array((int) $userId, $day));
    $row = $st->fetch();
    return $row ? (int) $row['count'] : 0;
}

/* Increment the AI message counter for today. Returns the new count. */
function pc_ai_increment_usage($userId)
{
    $db = pc_db();
    $day = date('Y-m-d');
    try {
        $db->prepare('INSERT INTO ai_usage (user_id, day, count) VALUES (?, ?, 1)
            ON CONFLICT(user_id, day) DO UPDATE SET count = count + 1')
            ->execute(array((int) $userId, $day));
    } catch (Exception $e) {
        /* SQLite < 3.24 doesn't support ON CONFLICT — fall back */
        $st = $db->prepare('SELECT count FROM ai_usage WHERE user_id = ? AND day = ?');
        $st->execute(array((int) $userId, $day));
        $row = $st->fetch();
        if ($row) {
            $db->prepare('UPDATE ai_usage SET count = count + 1 WHERE user_id = ? AND day = ?')
                ->execute(array((int) $userId, $day));
        } else {
            $db->prepare('INSERT INTO ai_usage (user_id, day, count) VALUES (?, ?, 1)')
                ->execute(array((int) $userId, $day));
        }
    }
    return pc_ai_usage_today($userId);
}

/* Check if a user can send an AI message. Free users get 3/day.
   Pro users (proDays > 0) get unlimited. */
function pc_ai_can_send($userId)
{
    $user = pc_find_user($userId);
    if (!$user) return false;
    if ((int) $user['pro_days'] > 0) return true;
    return pc_ai_usage_today($userId) < 3;
}

/* ---------------- tickets ---------------- */

function pc_create_ticket($uid, $handle, $name, $topic, $subject, $body, $want)
{
    $db = pc_db();
    $now = pc_now_ms();
    $st = $db->prepare('INSERT INTO tickets (uid, handle, name, topic, subject, body, want, status, notes, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, \'open\', \'[]\', ?, ?)');
    $st->execute(array(
        (int) $uid,
        substr((string) $handle, 0, 32),
        substr((string) $name, 0, 64),
        substr((string) $topic, 0, 32),
        substr((string) $subject, 0, 190),
        substr((string) $body, 0, 4000),
        substr((string) $want, 0, 190),
        $now, $now
    ));
    return (int) $db->lastInsertId();
}

function pc_public_ticket($row)
{
    $notes = json_decode(isset($row['notes']) ? $row['notes'] : '[]', true);
    if (!is_array($notes)) $notes = array();
    return array(
        'id' => (int) $row['id'],
        'uid' => (int) $row['uid'],
        'handle' => (string) $row['handle'],
        'name' => (string) $row['name'],
        'topic' => (string) $row['topic'],
        'subject' => (string) $row['subject'],
        'body' => (string) $row['body'],
        'want' => (string) $row['want'],
        'status' => (string) $row['status'],
        'notes' => $notes,
        'created' => (int) $row['created_at'],
        'updated' => (int) $row['updated_at']
    );
}

function pc_list_tickets($filter = 'all')
{
    $db = pc_db();
    $sql = 'SELECT * FROM tickets';
    switch ($filter) {
        case 'open':
            $sql .= ' WHERE status IN (\'open\',\'progress\')';
            break;
        case 'pass':
            $sql .= ' WHERE topic = \'Password reset\' OR want <> \'\'';
            break;
        case 'done':
            $sql .= ' WHERE status IN (\'resolved\',\'declined\')';
            break;
    }
    $sql .= ' ORDER BY updated_at DESC LIMIT 500';
    $out = array();
    foreach ($db->query($sql) as $row) {
        $out[] = pc_public_ticket($row);
    }
    return $out;
}

function pc_find_ticket($id)
{
    $db = pc_db();
    $st = $db->prepare('SELECT * FROM tickets WHERE id = ?');
    $st->execute(array((int) $id));
    $row = $st->fetch();
    return $row ? pc_public_ticket($row) : null;
}

function pc_update_ticket($id, $status, $noteText)
{
    $db = pc_db();
    $t = pc_find_ticket($id);
    if (!$t) return null;
    $notes = $t['notes'];
    if ($noteText !== null && $noteText !== '') {
        $notes[] = array('at' => pc_now_ms(), 'txt' => substr((string) $noteText, 0, 1000));
    }
    $st = $db->prepare('UPDATE tickets SET status = ?, notes = ?, updated_at = ? WHERE id = ?');
    $st->execute(array($status, json_encode($notes, JSON_UNESCAPED_UNICODE), pc_now_ms(), (int) $id));
    return pc_find_ticket($id);
}

/* ---------------- chat_index (groups & channels with public IDs) ---------------- */

function pc_register_chat($chatId, $kind, $name, $ownerId, $ownerHandle)
{
    $db = pc_db();
    $st = $db->prepare('SELECT * FROM chat_index WHERE chat_id = ?');
    $st->execute(array($chatId));
    $existing = $st->fetch();
    if ($existing) {
        /* If the same owner is re-registering (e.g. after deleting and
           recreating the group), allow it — just update the name/kind. */
        if ((int) $existing['owner_id'] === (int) $ownerId) {
            $upd = $db->prepare('UPDATE chat_index SET name = ?, kind = ? WHERE chat_id = ?');
            $upd->execute(array(substr((string) $name, 0, 64), $kind === 'channel' ? 'channel' : 'group', $chatId));
            return true;
        }
        /* If the previous owner no longer exists (account was deleted),
           allow the new owner to take over the ID. */
        $oldOwner = pc_find_user((int) $existing['owner_id']);
        if (!$oldOwner) {
            $upd = $db->prepare('UPDATE chat_index SET name = ?, kind = ?, owner_id = ?, owner_handle = ? WHERE chat_id = ?');
            $upd->execute(array(
                substr((string) $name, 0, 64),
                $kind === 'channel' ? 'channel' : 'group',
                (int) $ownerId,
                substr((string) $ownerHandle, 0, 32),
                $chatId
            ));
            return true;
        }
        return false; /* taken by someone else who still exists */
    }
    $ins = $db->prepare('INSERT INTO chat_index (chat_id, kind, name, owner_id, owner_handle, created_at)
        VALUES (?, ?, ?, ?, ?, ?)');
    $ins->execute(array(
        $chatId,
        $kind === 'channel' ? 'channel' : 'group',
        substr((string) $name, 0, 64),
        (int) $ownerId,
        substr((string) $ownerHandle, 0, 32),
        pc_now_ms()
    ));
    /* Add the owner as a member with role 'owner' */
    pc_add_member($chatId, $ownerId, 'owner');
    return true;
}

function pc_find_chat($chatId)
{
    $db = pc_db();
    $st = $db->prepare('SELECT * FROM chat_index WHERE chat_id = ?');
    $st->execute(array($chatId));
    $row = $st->fetch();
    if (!$row) return null;
    return array(
        'chatId' => (string) $row['chat_id'],
        'kind' => (string) $row['kind'],
        'name' => (string) $row['name'],
        'ownerId' => (int) $row['owner_id'],
        'ownerHandle' => (string) $row['owner_handle'],
        'created' => (int) $row['created_at']
    );
}

/* ---------------- chat membership ---------------- */

/* Add a user as a member of a chat. Returns true on success. */
function pc_add_member($chatId, $userId, $role = 'member')
{
    $db = pc_db();
    try {
        $now = pc_now_ms();
        $db->prepare('INSERT OR REPLACE INTO chat_members (chat_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)')
            ->execute(array($chatId, (int) $userId, $role, $now));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/* Remove a user from a chat's membership. */
function pc_remove_member($chatId, $userId)
{
    $db = pc_db();
    try {
        $db->prepare('DELETE FROM chat_members WHERE chat_id = ? AND user_id = ?')
            ->execute(array($chatId, (int) $userId));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/* Get all members of a chat. Returns array of {userId, role, joinedAt, name, handle}. */
function pc_get_members($chatId)
{
    $db = pc_db();
    $st = $db->prepare('SELECT m.*, u.name, u.handle, u.avatar, u.avatar_url, u.is_bot, u.verified
        FROM chat_members m JOIN users u ON u.id = m.user_id
        WHERE m.chat_id = ? ORDER BY
        CASE m.role WHEN \'owner\' THEN 0 WHEN \'admin\' THEN 1 ELSE 2 END,
        m.joined_at ASC');
    $st->execute(array($chatId));
    $out = array();
    foreach ($st as $r) {
        $out[] = array(
            'userId' => (int) $r['user_id'],
            'role' => (string) $r['role'],
            'joinedAt' => (int) $r['joined_at'],
            'name' => (string) $r['name'],
            'handle' => '@' . $r['handle'],
            'avatar' => (int) $r['avatar'],
            'avatarUrl' => (string) $r['avatar_url'],
            'isBot' => (int) $r['is_bot'],
            'verified' => (int) $r['verified']
        );
    }
    return $out;
}

/* Get the role of a user in a chat. Returns 'owner', 'admin', 'member', or null. */
function pc_get_member_role($chatId, $userId)
{
    $db = pc_db();
    $st = $db->prepare('SELECT role FROM chat_members WHERE chat_id = ? AND user_id = ?');
    $st->execute(array($chatId, (int) $userId));
    $row = $st->fetch();
    return $row ? (string) $row['role'] : null;
}

/* Check if a user is a member of a chat (any role). */
function pc_is_member($chatId, $userId)
{
    return pc_get_member_role($chatId, $userId) !== null;
}

/* ---------------- chat message history ---------------- */

/* Store a broadcast message in the chat_messages table. Called by
   chat.post so the message is persisted for channel history and
   group history-after-join. */
function pc_store_chat_message($chatId, $senderId, $senderName, $senderHandle, $mid, $txt, $tm, $kind)
{
    $db = pc_db();
    try {
        $st = $db->prepare('INSERT INTO chat_messages (chat_id, sender_id, sender_name, sender_handle, mid, txt, tm, kind, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute(array(
            $chatId,
            (int) $senderId,
            substr((string) $senderName, 0, 64),
            substr((string) $senderHandle, 0, 32),
            (int) $mid,
            $txt,
            substr((string) $tm, 0, 24),
            $kind === 'sticker' ? 'sticker' : 'msg',
            pc_now_ms()
        ));
        return (int) $db->lastInsertId();
    } catch (Exception $e) {
        return 0;
    }
}

/* Fetch the message history for a chat. Optionally limit to messages
   after a given timestamp (for groups: only show messages sent after
   the user joined). Returns an array of message objects. */
function pc_get_chat_history($chatId, $sinceTs = 0, $limit = 200)
{
    $db = pc_db();
    $limit = max(1, min(500, (int) $limit));
    if ($sinceTs > 0) {
        $st = $db->prepare('SELECT * FROM chat_messages WHERE chat_id = ? AND created_at >= ? ORDER BY id ASC LIMIT ' . $limit);
        $st->execute(array($chatId, (int) $sinceTs));
    } else {
        $st = $db->prepare('SELECT * FROM chat_messages WHERE chat_id = ? ORDER BY id ASC LIMIT ' . $limit);
        $st->execute(array($chatId));
    }
    $out = array();
    foreach ($st as $r) {
        $out[] = array(
            'id' => (int) $r['id'],
            'chatId' => (string) $r['chat_id'],
            'senderId' => (int) $r['sender_id'],
            'senderName' => (string) $r['sender_name'],
            'senderHandle' => '@' . $r['sender_handle'],
            'mid' => (int) $r['mid'],
            'txt' => (string) $r['txt'],
            'tm' => (string) $r['tm'],
            'kind' => (string) $r['kind'],
            'createdAt' => (int) $r['created_at']
        );
    }
    return $out;
}

/* Check if a user can post in a chat. For channels, only owner and admins
   can post. For groups, all members can post. */
function pc_can_post($chatId, $userId)
{
    $chat = pc_find_chat($chatId);
    if (!$chat) return false;
    $role = pc_get_member_role($chatId, $userId);
    if ($role === 'owner' || $role === 'admin') return true;
    if ($chat['kind'] === 'channel') return false; /* only owner/admin for channels */
    return $role === 'member'; /* group members can post */
}

/* Create a join request for a chat. Returns true on success. */
function pc_create_join_request($chatId, $userId)
{
    $db = pc_db();
    /* check if already a member */
    if (pc_is_member($chatId, $userId)) return array('ok' => false, 'error' => 'You are already a member.');
    /* check if there's already a pending request */
    $st = $db->prepare('SELECT id FROM join_requests WHERE chat_id = ? AND user_id = ? AND status = \'pending\'');
    $st->execute(array($chatId, (int) $userId));
    if ($st->fetch()) return array('ok' => false, 'error' => 'You already have a pending join request.');
    try {
        $db->prepare('INSERT OR REPLACE INTO join_requests (chat_id, user_id, status, created_at) VALUES (?, ?, \'pending\', ?)')
            ->execute(array($chatId, (int) $userId, pc_now_ms()));
        return array('ok' => true);
    } catch (Exception $e) {
        return array('ok' => false, 'error' => 'Could not create request.');
    }
}

/* Get pending join requests for a chat. */
function pc_get_join_requests($chatId)
{
    $db = pc_db();
    $st = $db->prepare('SELECT j.*, u.name, u.handle, u.avatar, u.avatar_url
        FROM join_requests j JOIN users u ON u.id = j.user_id
        WHERE j.chat_id = ? AND j.status = \'pending\' ORDER BY j.created_at DESC');
    $st->execute(array($chatId));
    $out = array();
    foreach ($st as $r) {
        $out[] = array(
            'id' => (int) $r['id'],
            'userId' => (int) $r['user_id'],
            'name' => (string) $r['name'],
            'handle' => '@' . $r['handle'],
            'avatar' => (int) $r['avatar'],
            'avatarUrl' => (string) $r['avatar_url'],
            'createdAt' => (int) $r['created_at']
        );
    }
    return $out;
}

/* Approve a join request — adds the user as a member. */
function pc_approve_join_request($chatId, $userId, $role = 'member')
{
    $db = pc_db();
    try {
        $db->prepare('UPDATE join_requests SET status = \'approved\', resolved_at = ? WHERE chat_id = ? AND user_id = ?')
            ->execute(array(pc_now_ms(), $chatId, (int) $userId));
        pc_add_member($chatId, $userId, $role);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/* Decline a join request. */
function pc_decline_join_request($chatId, $userId)
{
    $db = pc_db();
    try {
        $db->prepare('UPDATE join_requests SET status = \'declined\', resolved_at = ? WHERE chat_id = ? AND user_id = ?')
            ->execute(array(pc_now_ms(), $chatId, (int) $userId));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function pc_last_seen_text($ms)
{
    if ($ms <= 0) {
        return 'never';
    }
    $diff = (int) floor((pc_now_ms() - $ms) / 1000);
    if ($diff < 90) {
        return 'just now';
    }
    if ($diff < 3600) {
        return ((int) floor($diff / 60)) . ' min ago';
    }
    if ($diff < 86400) {
        $h = (int) floor($diff / 3600);
        return $h . ' hour' . ($h > 1 ? 's' : '') . ' ago';
    }
    $d = (int) floor($diff / 86400);
    return $d === 1 ? 'yesterday' : $d . ' days ago';
}

function pc_all_users()
{
    $db = pc_db();
    $out = array();
    foreach ($db->query('SELECT * FROM users ORDER BY id ASC') as $row) {
        $out[] = pc_public_user($row);
    }
    return $out;
}

function pc_find_user($id)
{
    $db = pc_db();
    $st = $db->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute(array((int) $id));
    $row = $st->fetch();
    return $row ? $row : null;
}

function pc_create_user($name, $handle, $pass, $role = 'member')
{
    $db = pc_db();
    $st = $db->prepare('INSERT INTO users (handle, name, pass_hash, role, status, pro_days, reports, created_at, last_seen)
        VALUES (?, ?, ?, ?, \'active\', 0, 0, ?, ?)');
    $now = pc_now_ms();
    $st->execute(array($handle, $name, password_hash($pass, PASSWORD_DEFAULT), $role, $now, $now));
    $id = (int) $db->lastInsertId();
    /* give the fresh account its own empty state (Saved Messages only) */
    $ins = $db->prepare('INSERT INTO app_state (user_id, version, data, updated_at) VALUES (?, 1, ?, ?)');
    $ins->execute(array($id, json_encode(pc_empty_state(), JSON_UNESCAPED_UNICODE), $now));
    return pc_find_user($id);
}

/**
 * Hard delete: the account row disappears, so its @id becomes
 * available for anybody else to register again.
 */
function pc_delete_user($id)
{
    $db = pc_db();
    $id = (int) $id;
    $row = pc_find_user($id);
    if (!$row) {
        return false;
    }
    if ($row['role'] === 'admin') {
        return false;
    }
    $db->prepare('DELETE FROM sessions WHERE user_id = ?')->execute(array($id));
    $db->prepare('DELETE FROM app_state WHERE user_id = ?')->execute(array($id));
    $db->prepare('DELETE FROM messages WHERE sender_id = ? OR recipient_id = ?')->execute(array($id, $id));
    $db->prepare('DELETE FROM users WHERE id = ?')->execute(array($id));
    return true;
}

function pc_new_token()
{
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes(32));
    }
    return hash('sha256', uniqid('pc', true) . mt_rand());
}

function pc_start_session($userId)
{
    $db = pc_db();
    $token = pc_new_token();
    $now = pc_now_ms();
    $st = $db->prepare('INSERT INTO sessions (token, user_id, agent, ip, created_at, seen_at) VALUES (?, ?, ?, ?, ?, ?)');
    $st->execute(array($token, (int) $userId, pc_agent(), pc_client_ip(), $now, $now));
    $cut = $now - (SESSION_DAYS * 86400000);
    $db->prepare('DELETE FROM sessions WHERE seen_at < ?')->execute(array($cut));
    return $token;
}

/* List all active sessions for a user. The current token is marked with
   is_current=1. Each session includes a created_at timestamp so the
   client can enforce a 24-hour cooldown before allowing the user to
   terminate other sessions. */
function pc_list_sessions($userId, $currentToken)
{
    $db = pc_db();
    $st = $db->prepare('SELECT token, agent, ip, created_at, seen_at FROM sessions WHERE user_id = ? ORDER BY seen_at DESC');
    $st->execute(array((int) $userId));
    $out = array();
    foreach ($st as $row) {
        $out[] = array(
            'token'      => $row['token'],
            'isCurrent'  => ($row['token'] === $currentToken) ? 1 : 0,
            'agent'      => (string) $row['agent'],
            'ip'         => (string) $row['ip'],
            'created'    => (int) $row['created_at'],
            'seen'       => (int) $row['seen_at']
        );
    }
    return $out;
}

/* Terminate every session for $userId except the current one. Returns
   the number of terminated sessions. The 24-hour cooldown is enforced
   by the caller (api.php) which checks the current session's age
   before invoking this function. */
function pc_terminate_other_sessions($userId, $currentToken)
{
    $db = pc_db();
    $st = $db->prepare('DELETE FROM sessions WHERE user_id = ? AND token <> ?');
    $st->execute(array((int) $userId, $currentToken));
    return $st->rowCount();
}

/* Terminate a single session by token. Used by per-device "Remove". */
function pc_terminate_session($userId, $token)
{
    $db = pc_db();
    $st = $db->prepare('DELETE FROM sessions WHERE user_id = ? AND token = ?');
    $st->execute(array((int) $userId, $token));
    return $st->rowCount() > 0;
}

/* Bot configuration: stored as a JSON file so admin edits persist
   across requests. Returns the default config if no file exists. */
function pc_default_botconfig()
{
    return array(
        'adminIds'         => array('admin'),
        'premiumWelcome'   => "👋 Welcome to Super Private!\n\nI'm your personal premium assistant — here to help you unlock the full power of Private Chat.\n\n✨ What I can do for you:\n• Buy a Pro or Gold subscription\n• Check your current Pro status and remaining days\n• Purchase a premium ID (short, custom usernames)\n• Upload a payment receipt for quick activation\n\n💡 Tip: Pro members get unlimited AI messages, animated stickers, premium themes, and a verified blue tick.\n\nTap any button below to begin 👇",
        'superWelcome'     => "⚡ Hey, I'm Super Bot!\n\nThink of me as your gateway to everything premium in Private Chat.\n\n🎯 I can help you:\n• Discover exclusive premium features\n• Request a custom premium ID\n• Get the latest pricing for subscriptions\n• Reach the admin team fast\n\nPick an option below to get started 👇",
        'animateWelcome'   => "🎨 Welcome to Animate Maker Bot!\n\nTurn any photo into a live, animated sticker in seconds.\n\n📸 How it works:\n1. Tap the attach button (📎) and choose a photo\n2. Pick an animation style — Spin, Bounce, Pulse, Shake, Glow, Rainbow, Swing, or Heartbeat\n3. I'll create your sticker instantly\n4. Forward it to any chat and enjoy!\n\n✨ Pro members get all 8 styles. Free members can try Bounce and Pulse.\n\nSend me a photo to begin 👇",
        'idPricing'        => "Premium ID pricing is set by the administrator. Short or rare IDs (3-4 letters) cost more than longer ones. After you submit a request, the admin will reply with the exact price and payment instructions.",
        'cardNote'         => 'Transfer the exact amount to the card shown above, then tap "Upload receipt" to send proof of payment. The admin will review and activate your subscription within a few hours.',
        'openaiApiKey'     => '',
        'openaiModel'      => 'gpt-4o-mini',
        'openaiBaseUrl'    => 'https://api.openai.com/v1',
        'aiWelcome'        => "🤖 Hi, I'm PrivateAI — your AI assistant inside Private Chat.\n\nAsk me anything! I can help you with:\n• Writing and editing text\n• Translating between languages\n• Answering questions on any topic\n• Coding and math problems\n• Brainstorming ideas\n\n🆓 Free members: 3 messages per day\n⭐ Pro members: unlimited messages\n\nWhat would you like to know? 👇"
    );
}

function pc_load_botconfig()
{
    $file = __DIR__ . '/data/botconfig.json';
    $cfg = pc_default_botconfig();
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                /* merge so new default keys appear automatically */
                $defaults = pc_default_botconfig();
                foreach ($defaults as $k => $v) {
                    if (!isset($decoded[$k])) $decoded[$k] = $v;
                }
                $cfg = $decoded;
            }
        }
    }
    return $cfg;
}

function pc_save_botconfig($cfg)
{
    $file = __DIR__ . '/data/botconfig.json';
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($file, json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $cfg;
}

/* Send a message from a bot to a user. Used to notify a user that
   their Pro subscription was approved, their premium ID was activated,
   etc. The message is stored in the messages table so the user's
   long-poll picks it up. */
function pc_queue_bot_message($botHandle, $userId, $text, $buttons = null)
{
    $db = pc_db();
    $st = $db->prepare('SELECT id FROM users WHERE handle = ? AND is_bot = 1');
    $st->execute(array($botHandle));
    $bot = $st->fetch();
    if (!$bot) return false;
    $payload = array(
        'kind' => 'msg',
        'mid'  => (int) (microtime(true) * 1000),
        'txt'  => $text,
        'tm'   => date('H:i')
    );
    /* If buttons are provided, encode them as JSON inside the txt field
       using a special prefix so the client can parse and render them. */
    if ($buttons && is_array($buttons) && count($buttons)) {
        $payload['txt'] = "%%BOTMSG%%" . json_encode(array(
            'text' => $text,
            'buttons' => $buttons
        ), JSON_UNESCAPED_UNICODE);
        $payload['kind'] = 'super'; /* tag as super so client knows to look for buttons */
    }
    pc_queue_message((int) $bot['id'], (int) $userId, $payload);
    return true;
}

/* Premium ID request: stored in the tickets table with topic
   'Premium ID' and the requested ID in the want field. */
function pc_create_premium_id_request($userId, $handle, $name, $requestedId, $note)
{
    return pc_create_ticket(
        (int) $userId,
        '@' . $handle,
        $name,
        'Premium ID',
        'Premium ID request: @' . $requestedId,
        $note !== '' ? $note : 'User requested premium ID @' . $requestedId,
        $requestedId
    );
}

function pc_token_from_request($body)
{
    if (isset($body['token']) && is_string($body['token'])) {
        return $body['token'];
    }
    if (isset($_SERVER['HTTP_X_AUTH_TOKEN'])) {
        return $_SERVER['HTTP_X_AUTH_TOKEN'];
    }
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $h = $_SERVER['HTTP_AUTHORIZATION'];
        if (stripos($h, 'Bearer ') === 0) {
            return substr($h, 7);
        }
    }
    if (isset($_GET['token'])) {
        return $_GET['token'];
    }
    return '';
}

function pc_current_user($body, $required = true)
{
    $token = pc_token_from_request($body);
    if (!preg_match('/^[a-f0-9]{16,64}$/', (string) $token)) {
        if ($required) {
            pc_fail('Please sign in again.', 401, array('auth' => false));
        }
        return null;
    }
    $db = pc_db();
    $st = $db->prepare('SELECT u.*, s.seen_at AS s_seen FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token = ?');
    $st->execute(array($token));
    $row = $st->fetch();
    if (!$row) {
        if ($required) {
            pc_fail('Your session has expired. Please sign in again.', 401, array('auth' => false));
        }
        return null;
    }
    if ($row['status'] === 'blocked') {
        $db->prepare('DELETE FROM sessions WHERE token = ?')->execute(array($token));
        pc_fail('This account has been suspended by an administrator.', 403, array('auth' => false));
    }
    /* Only update seen_at / last_seen if the last update was more than
       30 seconds ago. This prevents write-lock contention on SQLite
       when the client is long-polling (msg.fetch every 15s + state.pull
       every 5s would otherwise cause a write on every request). */
    $now = pc_now_ms();
    $lastSeen = isset($row['s_seen']) ? (int) $row['s_seen'] : 0;
    if ($now - $lastSeen > 15000) {
        try {
            $db->prepare('UPDATE sessions SET seen_at = ? WHERE token = ?')->execute(array($now, $token));
            $db->prepare('UPDATE users SET last_seen = ? WHERE id = ?')->execute(array($now, (int) $row['id']));
        } catch (Exception $e) {
            /* ignore write errors — not critical for the request to succeed */
        }
    }
    return $row;
}

function pc_require_admin($user)
{
    if (!$user || $user['role'] !== 'admin') {
        pc_fail('Administrator access is required for this action.', 403);
    }
}

function pc_rate_limited($handle)
{
    $db = pc_db();
    $since = pc_now_ms() - 300000;
    $db->prepare('DELETE FROM login_fails WHERE at < ?')->execute(array(pc_now_ms() - 3600000));
    $st = $db->prepare('SELECT COUNT(*) AS c FROM login_fails WHERE ip = ? AND at > ?');
    $st->execute(array(pc_client_ip(), $since));
    $row = $st->fetch();
    return $row && (int) $row['c'] >= LOGIN_MAX_FAILS;
}

function pc_log_fail($handle)
{
    $db = pc_db();
    $st = $db->prepare('INSERT INTO login_fails (ip, handle, at) VALUES (?, ?, ?)');
    $st->execute(array(pc_client_ip(), substr((string) $handle, 0, 32), pc_now_ms()));
}

/* ---------------- per-account state ---------------- */

function pc_load_state($userId)
{
    $db = pc_db();
    $userId = (int) $userId;
    $st = $db->prepare('SELECT version, data FROM app_state WHERE user_id = ?');
    $st->execute(array($userId));
    $row = $st->fetch();
    if (!$row) {
        $fresh = pc_empty_state();
        $ins = $db->prepare('INSERT INTO app_state (user_id, version, data, updated_at) VALUES (?, 1, ?, ?)');
        $ins->execute(array($userId, json_encode($fresh, JSON_UNESCAPED_UNICODE), pc_now_ms()));
        return array('version' => 1, 'data' => $fresh);
    }
    $data = json_decode($row['data'], true);
    if (!is_array($data)) {
        $data = pc_empty_state();
    }
    return array('version' => (int) $row['version'], 'data' => $data);
}

function pc_save_state($userId, $data)
{
    $db = pc_db();
    $userId = (int) $userId;
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        pc_fail('The data could not be encoded.', 500);
    }
    if (strlen($json) > MAX_STATE_BYTES) {
        pc_fail('Your stored chats are too large. Delete some old conversations first.', 413);
    }
    $st = $db->prepare('UPDATE app_state SET version = version + 1, data = ?, updated_at = ? WHERE user_id = ?');
    $st->execute(array($json, pc_now_ms(), $userId));
    if ($st->rowCount() === 0) {
        $ins = $db->prepare('INSERT INTO app_state (user_id, version, data, updated_at) VALUES (?, 1, ?, ?)');
        $ins->execute(array($userId, $json, pc_now_ms()));
        return 1;
    }
    $get = $db->prepare('SELECT version FROM app_state WHERE user_id = ?');
    $get->execute(array($userId));
    $row = $get->fetch();
    return $row ? (int) $row['version'] : 1;
}

function pc_reset_state($userId)
{
    return pc_save_state($userId, pc_empty_state());
}

/* ---------------- real message delivery ---------------- */

function pc_queue_message($senderId, $recipientId, $item)
{
    $db = pc_db();
    /* 'sticker' messages carry a base64 image payload and need a much larger
       txt field than regular text messages. 'super' messages carry inline
       animated-emoji markup (still small, but tagged for rendering).
       Plain 'msg' messages can also carry inline custom-emoji [e:base64]
       markup, so they get a bigger limit too. */
    $rawKind = isset($item['kind']) ? $item['kind'] : 'msg';
    if ($rawKind === 'del') {
        $kind = 'del';
    } elseif ($rawKind === 'sticker') {
        $kind = 'sticker';
    } elseif ($rawKind === 'super') {
        $kind = 'super';
    } else {
        $kind = 'msg';
    }
    $txt = isset($item['txt']) ? (string) $item['txt'] : '';
    /* sticker payloads can be up to 256 KB of base64.
       Plain text messages can contain [e:base64] custom emojis, so they
       need the same generous limit — otherwise the markup gets truncated
       mid-base64 and the message becomes unrenderable. */
    $limit = ($kind === 'sticker' || $kind === 'msg' || $kind === 'super') ? 262144 : 4000;
    if (function_exists('mb_substr')) {
        $txt = mb_substr($txt, 0, $limit, 'UTF-8');
    } else {
        $txt = substr($txt, 0, $limit);
    }
    $st = $db->prepare('INSERT INTO messages (sender_id, recipient_id, kind, mid, ref, txt, tm, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute(array(
        (int) $senderId,
        (int) $recipientId,
        $kind,
        isset($item['mid']) ? (int) $item['mid'] : 0,
        isset($item['ref']) ? (int) $item['ref'] : 0,
        $txt,
        isset($item['tm']) ? substr((string) $item['tm'], 0, 24) : '',
        pc_now_ms()
    ));
    return (int) $db->lastInsertId();
}

function pc_fetch_messages($userId, $since, $limit = 200)
{
    $db = pc_db();
    $st = $db->prepare('SELECT m.*, u.handle AS s_handle, u.name AS s_name
        FROM messages m JOIN users u ON u.id = m.sender_id
        WHERE m.recipient_id = ? AND m.id > ?
        ORDER BY m.id ASC LIMIT ' . ((int) $limit));
    $st->execute(array((int) $userId, (int) $since));
    $out = array();
    $cursor = (int) $since;
    foreach ($st as $row) {
        $cursor = (int) $row['id'];
        $out[] = array(
            'id' => (int) $row['id'],
            'kind' => $row['kind'],
            'mid' => (int) $row['mid'],
            'ref' => (int) $row['ref'],
            'txt' => (string) $row['txt'],
            'tm' => (string) $row['tm'],
            'from' => array(
                'id' => (int) $row['sender_id'],
                'name' => $row['s_name'],
                'handle' => '@' . $row['s_handle']
            )
        );
    }
    return array('items' => $out, 'cursor' => $cursor);
}

function pc_message_cursor($userId)
{
    $db = pc_db();
    $st = $db->prepare('SELECT MAX(id) AS m FROM messages WHERE recipient_id = ?');
    $st->execute(array((int) $userId));
    $row = $st->fetch();
    return ($row && $row['m'] !== null) ? (int) $row['m'] : 0;
}

/* ---------------- merge helpers (same account, two devices) ---------------- */

function pc_index_by_id($list)
{
    $out = array();
    if (!is_array($list)) {
        return $out;
    }
    foreach ($list as $item) {
        if (is_array($item) && isset($item['id'])) {
            $out[(string) $item['id']] = $item;
        }
    }
    return $out;
}

function pc_cmp_id($x, $y)
{
    $xi = isset($x['id']) ? $x['id'] : 0;
    $yi = isset($y['id']) ? $y['id'] : 0;
    if (is_numeric($xi) && is_numeric($yi)) {
        return ($xi < $yi) ? -1 : (($xi > $yi) ? 1 : 0);
    }
    return strcmp((string) $xi, (string) $yi);
}

function pc_merge_lists($mine, $theirs, $stampKey = null)
{
    $a = pc_index_by_id($mine);
    $b = pc_index_by_id($theirs);
    foreach ($b as $id => $item) {
        if (!isset($a[$id])) {
            $a[$id] = $item;
            continue;
        }
        if ($stampKey !== null) {
            $mineAt = isset($a[$id][$stampKey]) ? $a[$id][$stampKey] : '';
            $theirsAt = isset($item[$stampKey]) ? $item[$stampKey] : '';
            if (strcmp((string) $theirsAt, (string) $mineAt) > 0) {
                $a[$id] = $item;
            }
        } else {
            $a[$id] = $item;
        }
    }
    $out = array_values($a);
    usort($out, 'pc_cmp_id');
    return $out;
}

function pc_merge_state($server, $client)
{
    $out = $server;

    /* Tombstones: union of server-side and client-side removal markers.
       A chat or message listed here is filtered out of the merged output,
       so a client that deleted a chat never sees it resurrected by a
       server sync. */
    $removedChats = array();
    foreach (array(
        isset($server['REMOVED_CHATS']) ? $server['REMOVED_CHATS'] : array(),
        isset($client['REMOVED_CHATS']) ? $client['REMOVED_CHATS'] : array()
    ) as $list) {
        if (!is_array($list)) continue;
        foreach ($list as $id) {
            if (is_string($id) || is_int($id)) $removedChats[(string) $id] = true;
        }
    }
    $removedMsgs = array();
    foreach (array(
        isset($server['REMOVED_MSGS']) ? $server['REMOVED_MSGS'] : array(),
        isset($client['REMOVED_MSGS']) ? $client['REMOVED_MSGS'] : array()
    ) as $list) {
        if (!is_array($list)) continue;
        foreach ($list as $k) {
            if (is_string($k)) $removedMsgs[(string) $k] = true;
        }
    }

    $mergedChats = pc_merge_lists(
        isset($server['CHATS']) ? $server['CHATS'] : array(),
        isset($client['CHATS']) ? $client['CHATS'] : array()
    );
    /* If a chat exists in the merged list, remove its tombstone from
       removedChats. This handles the case where a new message arrived
       and PC.chatFor re-created the chat — the tombstone should be
       cleared so the chat doesn't get deleted again. */
    foreach ($mergedChats as $c) {
        if (is_array($c) && isset($c['id'])) {
            $cid = (string) $c['id'];
            if (isset($removedChats[$cid])) {
                unset($removedChats[$cid]);
            }
        }
    }
    /* Drop chats the user has removed (that haven't been re-created). */
    if (!empty($removedChats)) {
        $mergedChats = array_values(array_filter($mergedChats, function ($c) use ($removedChats) {
            return is_array($c) && isset($c['id']) && !isset($removedChats[(string) $c['id']]);
        }));
    }
    $out['CHATS'] = $mergedChats;

    $sThreads = (isset($server['THREADS']) && is_array($server['THREADS'])) ? $server['THREADS'] : array();
    $cThreads = (isset($client['THREADS']) && is_array($client['THREADS'])) ? $client['THREADS'] : array();
    $threads = array();
    $keys = array_unique(array_merge(array_keys($sThreads), array_keys($cThreads)));
    foreach ($keys as $k) {
        $kStr = (string) $k;
        /* skip threads whose chat was removed */
        if (isset($removedChats[$kStr])) continue;
        $mine = isset($sThreads[$k]) && is_array($sThreads[$k]) ? $sThreads[$k] : array();
        $theirs = isset($cThreads[$k]) && is_array($cThreads[$k]) ? $cThreads[$k] : array();
        $merged = pc_merge_lists($mine, $theirs);
        /* drop tombstoned messages from this thread */
        if (!empty($removedMsgs)) {
            $merged = array_values(array_filter($merged, function ($m) use ($kStr, $removedMsgs) {
                if (!is_array($m) || !isset($m['id'])) return true;
                return !isset($removedMsgs[$kStr . ':' . $m['id']]);
            }));
        }
        $threads[$kStr] = $merged;
    }
    $out['THREADS'] = $threads;

    $out['TICKETS'] = pc_merge_lists(
        isset($server['TICKETS']) ? $server['TICKETS'] : array(),
        isset($client['TICKETS']) ? $client['TICKETS'] : array(),
        'updated'
    );

    $sAudit = isset($server['AUDIT']) && is_array($server['AUDIT']) ? $server['AUDIT'] : array();
    $cAudit = isset($client['AUDIT']) && is_array($client['AUDIT']) ? $client['AUDIT'] : array();
    $seen = array();
    $audit = array();
    foreach (array_merge($cAudit, $sAudit) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $key = (isset($entry['t']) ? $entry['t'] : '') . '|' . (isset($entry['m']) ? $entry['m'] : '');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $audit[] = $entry;
        if (count($audit) >= 250) {
            break;
        }
    }
    $out['AUDIT'] = $audit;

    $out['DEVICES'] = (isset($client['DEVICES']) && is_array($client['DEVICES']))
        ? $client['DEVICES']
        : (isset($server['DEVICES']) ? $server['DEVICES'] : array());

    $out['MID'] = max(
        (int) (isset($server['MID']) ? $server['MID'] : 0),
        (int) (isset($client['MID']) ? $client['MID'] : 0)
    );
    $out['TKID'] = max(
        (int) (isset($server['TKID']) ? $server['TKID'] : 1001),
        (int) (isset($client['TKID']) ? $client['TKID'] : 1001)
    );

    /* Persist tombstones so the next client pull also sees them. */
    $out['REMOVED_CHATS'] = array_values(array_slice(array_keys($removedChats), -200));
    $out['REMOVED_MSGS'] = array_values(array_slice(array_keys($removedMsgs), -2000));

    /* Saved stickers: union of server and client lists, capped at 60.
       Each sticker is {anim, img, label, saved}. We dedupe by the img
       payload so the same sticker saved on two devices only appears once.
       Tombstones (REMOVED_STICKERS) filter out deleted stickers. */
    $removedStickersSet = array();
    foreach (array(
        isset($server['REMOVED_STICKERS']) ? $server['REMOVED_STICKERS'] : array(),
        isset($client['REMOVED_STICKERS']) ? $client['REMOVED_STICKERS'] : array()
    ) as $rlist) {
        if (!is_array($rlist)) continue;
        foreach ($rlist as $h) {
            if (is_string($h)) $removedStickersSet[(string) $h] = true;
        }
    }
    $savedStickers = array();
    $seenImgs = array();
    foreach (array(
        isset($server['SAVED_STICKERS']) ? $server['SAVED_STICKERS'] : array(),
        isset($client['SAVED_STICKERS']) ? $client['SAVED_STICKERS'] : array()
    ) as $list) {
        if (!is_array($list)) continue;
        foreach ($list as $s) {
            if (!is_array($s) || !isset($s['img'])) continue;
            /* skip tombstoned stickers */
            if (isset($removedStickersSet[(string) $s['img']])) continue;
            $key = md5((string) $s['img']);
            if (isset($seenImgs[$key])) continue;
            $seenImgs[$key] = true;
            $savedStickers[] = $s;
            if (count($savedStickers) >= 60) break 2;
        }
    }
    $out['SAVED_STICKERS'] = array_values($savedStickers);
    $out['REMOVED_STICKERS'] = array_values(array_slice(array_keys($removedStickersSet), -200));

    /* Saved emojis (custom emoji from photos, PRO feature): same merge
       logic as stickers — union, dedupe by image, cap at 30.
       Also filter by REMOVED_EMOJIS tombstones. */
    $removedEmojisSet = array();
    foreach (array(
        isset($server['REMOVED_EMOJIS']) ? $server['REMOVED_EMOJIS'] : array(),
        isset($client['REMOVED_EMOJIS']) ? $client['REMOVED_EMOJIS'] : array()
    ) as $rlist) {
        if (!is_array($rlist)) continue;
        foreach ($rlist as $h) {
            if (is_string($h)) $removedEmojisSet[(string) $h] = true;
        }
    }
    $savedEmojis = array();
    $seenEm = array();
    foreach (array(
        isset($server['SAVED_EMOJIS']) ? $server['SAVED_EMOJIS'] : array(),
        isset($client['SAVED_EMOJIS']) ? $client['SAVED_EMOJIS'] : array()
    ) as $list) {
        if (!is_array($list)) continue;
        foreach ($list as $s) {
            if (!is_array($s) || !isset($s['img'])) continue;
            if (isset($removedEmojisSet[(string) $s['img']])) continue;
            $key = md5((string) $s['img']);
            if (isset($seenEm[$key])) continue;
            $seenEm[$key] = true;
            $savedEmojis[] = $s;
            if (count($savedEmojis) >= 30) break 2;
        }
    }
    $out['SAVED_EMOJIS'] = array_values($savedEmojis);
    $out['REMOVED_EMOJIS'] = array_values(array_slice(array_keys($removedEmojisSet), -100));

    return $out;
}
