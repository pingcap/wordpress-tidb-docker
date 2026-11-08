<?php
require_once '/opt/security/security-storage.php';

function deployment_admit_login(string $source, string $account, int $now,
    string $path = '/var/lib/wordpress-security/login.json'): array {
    if (!preg_match('/\A[0-9a-f]{32}\z/', $source) || !preg_match('/\A[0-9a-f]{32}\z/', $account) || $now < 0) {
        throw new RuntimeException('Invalid login admission context');
    }
    return deployment_locked($path, function ($handle) use ($source, $account, $now) {
        $raw = stream_get_contents($handle, 65537);
        if ($raw === false || strlen($raw) > 65536) {
            throw new RuntimeException('Invalid login admission state');
        }
        $state = $raw === '' ? null : json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if ($raw !== '' && !is_array($state)) {
            throw new RuntimeException('Invalid login admission state');
        }
        if ($state !== null) {
            if (!is_array($state) || array_keys($state) !== ['window', 'total', 'sources', 'accounts', 'denied']
                || !is_int($state['window']) || $state['window'] < 0 || $state['window'] > $now
                || !is_int($state['total']) || $state['total'] < 0 || $state['total'] > 60
                || !is_int($state['denied']) || $state['denied'] < 0 || $state['denied'] > 1000000000) {
                throw new RuntimeException('Invalid login admission state');
            }
            foreach (['sources' => 20, 'accounts' => 5] as $field => $limit) {
                if (!is_array($state[$field]) || count($state[$field]) > 60 || array_sum($state[$field]) !== $state['total']) {
                    throw new RuntimeException('Invalid login admission state');
                }
                foreach ($state[$field] as $key => $count) {
                    if (!preg_match('/\A[0-9a-f]{32}\z/', $key) || !is_int($count) || $count < 1 || $count > $limit) {
                        throw new RuntimeException('Invalid login admission state');
                    }
                }
            }
        }
        $summary = 0;
        if ($state === null || $now - $state['window'] >= 60) {
            $summary = $state['denied'] ?? 0;
            $state = ['window' => $now, 'total' => 0, 'sources' => [], 'accounts' => [], 'denied' => 0];
        }
        $allowed = $state['total'] < 60 && ($state['sources'][$source] ?? 0) < 20
            && ($state['accounts'][$account] ?? 0) < 5;
        $first_denial = !$allowed && $state['denied'] === 0;
        if ($allowed) {
            ++$state['total'];
            $state['sources'][$source] = ($state['sources'][$source] ?? 0) + 1;
            $state['accounts'][$account] = ($state['accounts'][$account] ?? 0) + 1;
        } else {
            $state['denied'] = min(1000000000, $state['denied'] + 1);
        }
        $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        rewind($handle);
        if (fwrite($handle, $encoded) !== strlen($encoded) || !ftruncate($handle, strlen($encoded)) || !fflush($handle)) {
            throw new RuntimeException('Login admission write failed');
        }
        return ['allowed' => $allowed, 'retry_after' => max(1, 60 - ($now - $state['window'])),
            'first_denial' => $first_denial, 'summary' => $summary];
    });
}

function deployment_guard_login(): void {
    if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    try {
        $name = $_POST['log'] ?? $_POST['user_login'] ?? '';
        if (!is_string($name) || strlen($name) > 256) {
            nocache_headers();
            status_header(400);
            exit('Invalid login request.');
        }
        $context = deployment_login_context(sanitize_user(wp_unslash($name)));
        $result = deployment_admit_login($context['source_tag'] ?? '', $context['account_tag'], time());
        if ($result['summary']) {
            deployment_audit('login_throttle_summary', 0, ['attempt_count' => $result['summary']]);
        }
        if ($result['allowed']) {
            return;
        }
        if ($result['first_denial']) {
            deployment_audit('login_rate_limited', 0, $context);
        }
        nocache_headers();
        header('Retry-After: ' . $result['retry_after']);
        status_header(429);
        exit('Too many login requests. Please try again shortly.');
    } catch (Throwable $error) {
        deployment_audit('login_admission_unavailable');
        nocache_headers();
        header('Retry-After: 60');
        status_header(503);
        exit('Login is temporarily unavailable. Please try again shortly.');
    }
}
