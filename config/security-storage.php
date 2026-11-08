<?php
// Private, bounded local state shared by all PHP processes in this deployment.
function deployment_private_open(string $path) {
    $directory = dirname($path);
    clearstatcache(true, $directory);
    clearstatcache(true, $path);
    $parent = @lstat($directory);
    if (!$parent || ($parent['mode'] & 0170000) !== 0040000 || ($parent['mode'] & 0077)) {
        throw new RuntimeException('Security storage directory is not private');
    }
    $before = @lstat($path);
    if ($before && (($before['mode'] & 0170000) !== 0100000 || ($before['mode'] & 0077) || $before['nlink'] !== 1)) {
        throw new RuntimeException('Invalid security storage file');
    }
    $mask = umask(0077);
    try {
        $handle = @fopen($path, 'c+b');
    } finally {
        umask($mask);
    }
    if (!$handle) {
        throw new RuntimeException('Security storage unavailable');
    }
    $opened = fstat($handle);
    clearstatcache(true, $path);
    $current = @lstat($path);
    if (!$current || $opened['ino'] !== $current['ino'] || $opened['dev'] !== $current['dev']
        || ($opened['mode'] & 0170000) !== 0100000 || ($opened['mode'] & 0077) || $opened['nlink'] !== 1) {
        fclose($handle);
        throw new RuntimeException('Security storage changed unexpectedly');
    }
    return $handle;
}

function deployment_locked(string $path, callable $operation) {
    $handle = deployment_private_open($path);
    $deadline = hrtime(true) + 200000000;
    try {
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('Security storage busy');
            }
            usleep(1000);
        }
        return $operation($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function deployment_retain_privileged(string $line, string $directory = '/var/lib/wordpress-security'): void {
    if (strlen($line) > 2048) {
        throw new RuntimeException('Audit record too large');
    }
    deployment_locked($directory . '/audit.lock', function () use ($directory, $line) {
        $path = $directory . '/privileged.jsonl';
        // Never replace or follow links, even when rotating old log files.
        foreach (['', '.1', '.2', '.3', '.4'] as $suffix) {
            clearstatcache(true, $path . $suffix);
            $entry = @lstat($path . $suffix);
            if ($entry && (($entry['mode'] & 0170000) !== 0100000 || ($entry['mode'] & 0077) || $entry['nlink'] !== 1)) {
                throw new RuntimeException('Invalid audit file');
            }
        }
        clearstatcache(true, $path);
        if (is_file($path) && filesize($path) + strlen($line) > 1048576) {
            if (is_file($path . '.4') && !@unlink($path . '.4')) {
                throw new RuntimeException('Audit rotation failed');
            }
            for ($index = 3; $index >= 0; --$index) {
                $source = $path . ($index ? '.' . $index : '');
                if (is_file($source) && !@rename($source, $path . '.' . ($index + 1))) {
                    throw new RuntimeException('Audit rotation failed');
                }
            }
        }
        $output = deployment_private_open($path);
        try {
            if (fseek($output, 0, SEEK_END) !== 0 || fwrite($output, $line) !== strlen($line) || !fflush($output)) {
                throw new RuntimeException('Audit write failed');
            }
        } finally {
            fclose($output);
        }
    });
}

function deployment_check_security_storage(): void {
    deployment_locked('/var/lib/wordpress-security/login.json', function () {});
    deployment_retain_privileged('');
}
