<?php
/* Plugin Name: Deployment Security Audit */
require_once '/opt/security/audit.php';
require_once '/opt/security/login-limits.php';
// Runs before wp-login.php dispatches authentication or recovery POST actions.
add_action('login_init', 'deployment_guard_login', 1);
add_action('wp_install', function ($user) { deployment_audit('admin_bootstrap', (int)$user->ID); });
add_action('wp_login', function ($name, $user) { deployment_audit('login_success', (int)$user->ID, deployment_login_context($name)); }, 10, 2);
add_action('wp_login_failed', function ($name) { deployment_audit('login_failure', 0, deployment_login_context($name)); });
add_action('wp_logout', function ($id) { deployment_audit('logout', (int)$id); });
add_action('user_register', function ($id) { deployment_audit('user_created', (int)$id); });
add_action('deleted_user', function ($id) { deployment_audit('user_deleted', (int)$id); });
add_action('set_user_role', function ($id) { deployment_audit('role_changed', (int)$id); });
add_action('add_user_role', function ($id) { deployment_audit('role_added', (int)$id); });
add_action('remove_user_role', function ($id) { deployment_audit('role_removed', (int)$id); });
add_action('after_password_reset', function ($user) { deployment_audit('password_reset', (int)$user->ID); });
foreach (['added_option' => 'created', 'updated_option' => 'changed', 'deleted_option' => 'deleted'] as $hook => $change) {
    add_action($hook, function ($name) use ($change) {
        global $wpdb;
        if ($name === $wpdb->get_blog_prefix() . 'user_roles') {
            deployment_audit('role_definitions_' . $change);
        } elseif (in_array($name, ['siteurl', 'home', 'users_can_register', 'default_role', 'admin_email', 'active_plugins'], true)) {
            deployment_audit('security_option_' . $change);
        }
    });
}
add_filter('xmlrpc_enabled', '__return_false');
