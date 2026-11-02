<?php
/** Connect with verified TLS and reject credentials with broader privileges. */
function deployment_database(array $settings): mysqli {
    if (!extension_loaded('mysqlnd') || !defined('MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT')) {
        throw new RuntimeException('The verified mysqlnd TLS driver is required');
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $connection = mysqli_init();
    if (!$connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5)
        || !$connection->options(MYSQLI_OPT_READ_TIMEOUT, 10)
        || !$connection->ssl_set(null, null, '/etc/ssl/certs/ca-certificates.crt', null, null)) {
        throw new RuntimeException('Database driver cannot enforce the required TLS policy');
    }
    if (!@$connection->real_connect($settings['host'], $settings['user'], $settings['password'],
        $settings['database'], (int)$settings['port'], null,
        MYSQLI_CLIENT_SSL | MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT)) {
        throw new RuntimeException('Verified TLS database connection failed');
    }
    $cipher = $connection->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
    $cipher_row = $cipher ? $cipher->fetch_row() : null;
    if (!$cipher_row || empty($cipher_row[1])) {
        $connection->close();
        throw new RuntimeException('Database connection must use TLS');
    }
    $grants = $connection->query('SHOW GRANTS FOR CURRENT_USER()');
    if (!$grants) {
        $connection->close();
        throw new RuntimeException('Unable to verify database privileges');
    }
    while ($row = $grants->fetch_row()) {
        $grant = $row[0];
        // Role grants, global privileges, GRANT OPTION and other databases fail closed.
        $usage = preg_match('/\AGRANT USAGE ON \*\.\* TO /i', $grant);
        // TiDB may omit identifier quotes while MySQL emits backticks. Compare
        // schema names exactly, even when privilege names use mixed case.
        $scoped = preg_match('/\AGRANT (?:ALL PRIVILEGES|(?:SELECT|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|INDEX|REFERENCES)(?:, (?:SELECT|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|INDEX|REFERENCES))*) ON (`[a-zA-Z][a-zA-Z0-9]*`|[a-zA-Z][a-zA-Z0-9]*)\.\* TO /i', $grant, $match)
            && trim($match[1], '`') === $settings['database'];
        if ((!$usage && !$scoped) || stripos($grant, 'WITH GRANT OPTION') !== false) {
            $connection->close();
            throw new RuntimeException('Database privileges exceed the dedicated application schema');
        }
    }
    return $connection;
}
