<?php
/* Plugin Name: Deployment Security Audit */
// Record event types and numeric IDs only; never passwords, tokens, content,
// option values, supplied usernames, cookies or request URLs.
function deployment_audit(string $event, int $target = 0): void {
    // Write the redacted audit stream directly so Apache's general error-log
    // severity filter cannot suppress security events from web requests.
    error_log(json_encode([
        'type' => 'wordpress_security',
        'event' => $event,
        'actor_id' => function_exists('get_current_user_id') ? get_current_user_id() : 0,
        'target_id' => $target,
        'time' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL, 3, 'php://stderr');
}
add_action('wp_install', function ($user) { deployment_audit('admin_bootstrap', (int)$user->ID); });
add_action('wp_login', function ($name, $user) { deployment_audit('login_success', (int)$user->ID); }, 10, 2);
add_action('wp_login_failed', function () { deployment_audit('login_failure'); });
add_action('wp_logout', function ($id) { deployment_audit('logout', (int)$id); });
add_action('user_register', function ($id) { deployment_audit('user_created', (int)$id); });
add_action('deleted_user', function ($id) { deployment_audit('user_deleted', (int)$id); });
add_action('set_user_role', function ($id) { deployment_audit('role_changed', (int)$id); });
add_action('add_user_role', function ($id) { deployment_audit('role_added', (int)$id); });
add_action('remove_user_role', function ($id) { deployment_audit('role_removed', (int)$id); });
add_action('after_password_reset', function ($user) { deployment_audit('password_reset', (int)$user->ID); });
add_action('updated_option', function ($name) {
    if (in_array($name, ['siteurl', 'home', 'users_can_register', 'default_role', 'admin_email', 'active_plugins'], true)) {
        deployment_audit('security_option_changed');
    }
});
add_filter('xmlrpc_enabled', '__return_false');
