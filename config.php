<?php
/* ============================================================
   Private Chat - configuration
   Edit ONLY this file, then open install.php once.
   ============================================================ */

/* ---- database ----------------------------------------------
   'sqlite' = real file database, nothing to create in cPanel
              (default - works on every shared host)
   'mysql'  = MySQL / MariaDB, if you prefer a server database
------------------------------------------------------------- */
define('DB_DRIVER', 'sqlite');

define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'privatechat');
define('DB_USER', 'root');
define('DB_PASS', '');

/* used only when DB_DRIVER is 'sqlite' (folder must be writable) */
define('SQLITE_PATH', __DIR__ . '/data/privatechat.sqlite');

/* ---- first administrator -----------------------------------
   CHANGE THIS PASSWORD before you install!
   It is created once by install.php, stored hashed and is
   never sent back to the browser.
------------------------------------------------------------- */
define('ADMIN_ID', 'admin');
define('ADMIN_PASS', 'CHANGE-ME-before-install');
define('ADMIN_NAME', 'Private Chat Operator');

/* ---- limits ------------------------------------------------ */
define('SESSION_DAYS', 30);        /* how long a sign-in stays valid   */
define('LOGIN_MAX_FAILS', 10);     /* failed sign-ins per 5 minutes    */
define('MAX_STATE_BYTES', 4000000);/* size guard per account           */

/* Turn on only while troubleshooting, then set back to false. */
define('DEBUG_ERRORS', false);
