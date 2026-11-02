<?php
require_once '/opt/security/settings.php';
try {
    $settings = deployment_settings();
} catch (Throwable $error) {
    http_response_code(503);
    exit('Deployment configuration is incomplete.');
}
define('DB_NAME', $settings['database']);
define('DB_USER', $settings['user']);
define('DB_PASSWORD', $settings['password']);
define('DB_HOST', $settings['host'] . ':' . $settings['port']);
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', 'utf8mb4_bin');
$table_prefix = $settings['prefix'];
foreach ($settings['salts'] as $name => $value) {
    define($name, $value);
}
unset($settings, $name, $value);

define('FORCE_SSL_ADMIN', true);
define('DISALLOW_FILE_EDIT', true);
define('DISALLOW_FILE_MODS', true);
define('DISABLE_WP_CRON', true);
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', false);
// Only the HTTPS proxy is published; its upstream header overwrites client input.
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
