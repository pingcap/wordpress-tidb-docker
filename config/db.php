<?php
// WordPress database drop-in. Applies the same TLS policy to initial connects
// and wpdb reconnects, before any WordPress query can run.
require_once '/opt/security/settings.php';
require_once '/opt/security/db-connection.php';
require_once '/opt/security/audit.php';
class TiDB_Verified_WPDB extends wpdb {
    public function print_error($str = '') {
        // wpdb's default logger includes complete SQL and database error strings,
        // which can contain reset tokens, user content and credential material.
        if (!$this->suppress_errors) {
            deployment_audit('database_query_failed');
        }
        return false;
    }

    public function db_connect($allow_bail = true) {
        $this->is_mysql = true;
        $this->ready = false;
        try {
            $this->dbh = deployment_database(deployment_settings());
        } catch (Throwable $error) {
            $this->dbh = null;
            deployment_audit('database_connection_validation_failed');
            if ($allow_bail) {
                $this->bail('A verified, schema-scoped database connection is required.', 'db_connect_fail');
            }
            return false;
        }
        $this->init_charset();
        $this->set_charset($this->dbh);
        $this->ready = true;
        $this->set_sql_mode();
        $this->select($this->dbname, $this->dbh);
        return $this->ready;
    }
}
$wpdb = new TiDB_Verified_WPDB(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
