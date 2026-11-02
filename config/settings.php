<?php
// This file is outside the document root and never logs configuration values.
function deployment_settings(): array {
    $required = function (string $name): string {
        $value = getenv($name);
        if ($value === false || $value === '') {
            throw new RuntimeException('Missing deployment setting: ' . $name);
        }
        return $value;
    };
    $host = $required('TIDB_HOST');
    $port = getenv('TIDB_PORT') ?: '4000';
    $user = $required('TIDB_USER');
    $database = $required('TIDB_DB_NAME');
    $prefix = $required('WORDPRESS_TABLE_PREFIX');
    if (!preg_match('/\A[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?\z/', $host)
        || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
        throw new RuntimeException('Invalid database endpoint');
    }
    if (!preg_match('/\A[a-zA-Z0-9_.-]+\z/', $user) || preg_match('/(?:\A|\.)root\z/i', $user)) {
        throw new RuntimeException('A dedicated non-root database user is required');
    }
    // Keep schema names free of SQL GRANT wildcard characters (_ and %).
    if (!preg_match('/\A[a-zA-Z][a-zA-Z0-9]{0,63}\z/', $database)
        || in_array(strtolower($database), ['test', 'mysql', 'sys', 'information_schema', 'performance_schema'], true)) {
        throw new RuntimeException('A dedicated application database is required');
    }
    if (!preg_match('/\A[a-zA-Z][a-zA-Z0-9_]{0,31}_\z/', $prefix) || $prefix === 'wp_') {
        throw new RuntimeException('A unique table prefix is required');
    }
    $password = @file_get_contents('/run/secrets/db_password');
    if ($password === false || strlen(rtrim($password, "\r\n")) < 16) {
        throw new RuntimeException('A database password secret of at least 16 characters is required');
    }
    $salts = json_decode((string)@file_get_contents('/run/secrets/wp_salts.json'), true);
    $names = ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
              'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'];
    if (!is_array($salts) || count($salts) !== count($names)) {
        throw new RuntimeException('Invalid signing key secret');
    }
    foreach ($names as $name) {
        if (!isset($salts[$name]) || !is_string($salts[$name]) || !preg_match('/\A[0-9a-f]{128}\z/', $salts[$name])) {
            throw new RuntimeException('Invalid signing key secret');
        }
    }
    if (count(array_unique($salts)) !== count($names)) {
        throw new RuntimeException('Signing keys must be independent');
    }
    return compact('host', 'port', 'user', 'database', 'prefix', 'salts')
        + ['password' => rtrim($password, "\r\n")];
}
