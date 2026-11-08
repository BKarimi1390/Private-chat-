-- Private Chat - manual schema (MySQL / MariaDB)
-- Only needed if you prefer creating the tables by hand in phpMyAdmin.
-- With DB_DRIVER = 'sqlite' (the default) install.php creates everything
-- inside data/privatechat.sqlite and this file is not used at all.

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    handle VARCHAR(32) NOT NULL,
    name VARCHAR(64) NOT NULL,
    pass_hash VARCHAR(255) NOT NULL,
    role VARCHAR(10) NOT NULL DEFAULT 'member',
    status VARCHAR(12) NOT NULL DEFAULT 'active',
    pro_days INT NOT NULL DEFAULT 0,
    reports INT NOT NULL DEFAULT 0,
    avatar INT NOT NULL DEFAULT 0,
    bio VARCHAR(255) NOT NULL DEFAULT '',
    created_at BIGINT NOT NULL DEFAULT 0,
    last_seen BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_handle (handle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
    token CHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    agent VARCHAR(190) NULL,
    ip VARCHAR(45) NULL,
    created_at BIGINT NOT NULL DEFAULT 0,
    seen_at BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (token),
    KEY idx_sessions_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- one row per account: that account's own chats, threads and tickets
CREATE TABLE IF NOT EXISTS app_state (
    user_id INT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 0,
    data LONGTEXT NOT NULL,
    updated_at BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- real delivery between two accounts (kind: 'msg' or 'del')
CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sender_id INT UNSIGNED NOT NULL,
    recipient_id INT UNSIGNED NOT NULL,
    kind VARCHAR(8) NOT NULL DEFAULT 'msg',
    mid BIGINT NOT NULL DEFAULT 0,
    ref BIGINT NOT NULL DEFAULT 0,
    txt MEDIUMTEXT NULL,
    tm VARCHAR(24) NULL,
    created_at BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_msg_to (recipient_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_fails (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip VARCHAR(45) NULL,
    handle VARCHAR(32) NULL,
    at BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_fails_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Server-side tickets (so password-reset requests reach the admin even
-- when the requester is not signed in).
CREATE TABLE IF NOT EXISTS tickets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    uid INT UNSIGNED NOT NULL DEFAULT 0,
    handle VARCHAR(32) NOT NULL DEFAULT '',
    name VARCHAR(64) NOT NULL DEFAULT '',
    topic VARCHAR(32) NOT NULL DEFAULT '',
    subject VARCHAR(190) NOT NULL DEFAULT '',
    body TEXT NULL,
    want VARCHAR(190) NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'open',
    notes TEXT NULL,
    created_at BIGINT NOT NULL DEFAULT 0,
    updated_at BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_tickets_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Public groups (gaps) and channels registered with a public ID.
CREATE TABLE IF NOT EXISTS chat_index (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    chat_id VARCHAR(32) NOT NULL,
    kind VARCHAR(8) NOT NULL DEFAULT 'group',
    name VARCHAR(64) NOT NULL DEFAULT '',
    owner_id INT UNSIGNED NOT NULL DEFAULT 0,
    owner_handle VARCHAR(32) NOT NULL DEFAULT '',
    created_at BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_chat_id (chat_id),
    KEY idx_chat_index_kind (kind, chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
