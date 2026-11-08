<?php
// Runs only in the disposable integration image, with fake identifiers.
require '/opt/security/login-limits.php';
function check($condition, $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function refused(callable $call): void {
    try { $call(); } catch (Throwable $error) { return; }
    throw new RuntimeException('Unsafe state was accepted');
}
if (($argv[1] ?? '') === 'admit') {
    echo json_encode(deployment_admit_login(md5('source'), md5('account'), 1700000000, $argv[2]));
    exit;
}
if (($argv[1] ?? '') === 'hold') {
    $handle = fopen($argv[2], 'c+b');
    flock($handle, LOCK_EX);
    echo "locked\n"; fflush(STDOUT); usleep(750000); fclose($handle); exit;
}
if (($argv[1] ?? '') === 'audit') {
    deployment_retain_privileged(json_encode(['sequence' => (int)$argv[3]]) . "\n", $argv[2]);
    exit;
}
$root = '/tmp/security-state-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$path = $root . '/login.json';
function reset_state($path): void { file_put_contents($path, ''); chmod($path, 0600); }
function child($arguments): array {
    $process = proc_open(array_merge([PHP_BINARY, __FILE__], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($process), 'Cannot start isolated PHP worker');
    fclose($pipes[0]);
    return [$process, $pipes];
}
function finish($child): string {
    [$process, $pipes] = $child;
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === 0 && $error === '', 'Isolated PHP worker failed');
    return $output;
}
try {
    for ($i = 0; $i < 5; ++$i) {
        check(deployment_admit_login(md5('s'), md5('a'), 1000, $path)['allowed'], 'Early account refusal');
    }
    $denial = deployment_admit_login(md5('s'), md5('a'), 1001, $path);
    check(!$denial['allowed'] && $denial['first_denial'] && $denial['retry_after'] === 59, 'Account budget not enforced');
    check(!deployment_admit_login(md5('other-source'), md5('a'), 1001, $path)['first_denial'], 'Repeated noise audit');
    $retry = deployment_admit_login(md5('s'), md5('a'), 1060, $path);
    check($retry['allowed'] && $retry['summary'] === 2, 'Window did not recover or summarize');

    reset_state($path);
    for ($i = 0; $i < 20; ++$i) {
        check(deployment_admit_login(md5('s'), md5('a' . $i), 2000, $path)['allowed'], 'Early source refusal');
    }
    check(!deployment_admit_login(md5('s'), md5('new'), 2000, $path)['allowed'], 'Source budget bypassed by account rotation');
    check(deployment_admit_login(md5('new-source'), md5('new'), 2000, $path)['allowed'], 'Unrelated source blocked early');

    reset_state($path);
    for ($i = 0; $i < 60; ++$i) {
        check(deployment_admit_login(md5('s' . $i), md5('a' . $i), 3000, $path)['allowed'], 'Early global refusal');
    }
    for ($i = 0; $i < 1000; ++$i) {
        check(!deployment_admit_login(md5('rotated-s' . $i), md5('rotated-a' . $i), 3001, $path)['allowed'], 'Global budget bypassed');
    }
    $state = json_decode(file_get_contents($path), true);
    check(count($state['sources']) === 60 && count($state['accounts']) === 60 && filesize($path) < 16384,
        'Blocked identifiers expanded storage');
    $good = file_get_contents($path);
    foreach (['null', '[]', '{}', '{broken', str_repeat('x', 65537)] as $bad) {
        file_put_contents($path, $bad);
        refused(fn() => deployment_admit_login(md5('s'), md5('a'), 3001, $path));
        check(file_get_contents($path) === $bad, 'Corrupt state was silently reset');
    }
    file_put_contents($path, $good);
    refused(fn() => deployment_admit_login(md5('s'), md5('a'), 2999, $path));
    chmod($path, 0644);
    refused(fn() => deployment_admit_login(md5('s'), md5('a'), 3001, $path));
    chmod($path, 0600);

    reset_state($path);
    $workers = [];
    for ($i = 0; $i < 12; ++$i) $workers[] = child(['admit', $path]);
    $allowed = 0;
    foreach ($workers as $worker) $allowed += (int)json_decode(finish($worker), true)['allowed'];
    check($allowed === 5, 'Concurrent PHP processes exceeded the shared account budget');

    $holder = child(['hold', $path]);
    check(trim(fgets($holder[1][1])) === 'locked', 'Lock holder not ready');
    $started = hrtime(true);
    refused(fn() => deployment_admit_login(md5('s'), md5('a'), 1700000000, $path));
    check((hrtime(true) - $started) < 500000000, 'Lock contention did not fail promptly');
    finish($holder);

    $outside = $root . '/untouched'; file_put_contents($outside, 'fixture'); chmod($outside, 0600);
    unlink($path); symlink($outside, $path);
    refused(fn() => deployment_admit_login(md5('s'), md5('a'), 1000, $path));
    check(file_get_contents($outside) === 'fixture', 'Symlink target changed'); unlink($path);

    $audit = $root . '/privileged.jsonl';
    for ($i = 0; $i < 7; ++$i) {
        file_put_contents($audit, str_repeat('x', 1048576)); chmod($audit, 0600);
        deployment_retain_privileged("{\"event\":\"fixture\"}\n", $root);
    }
    $files = glob($audit . '*');
    check(count($files) === 5 && !file_exists($audit . '.5'), 'Audit retention exceeded its bound');
    foreach ($files as $file) {
        check(filesize($file) <= 1048576 && (fileperms($file) & 0777) === 0600, 'Audit file size/mode invalid');
        unlink($file);
    }
    $workers = [];
    for ($i = 0; $i < 12; ++$i) $workers[] = child(['audit', $root, (string)$i]);
    foreach ($workers as $worker) finish($worker);
    $records = array_map(fn($line) => json_decode($line, true, 8, JSON_THROW_ON_ERROR), file($audit));
    check(count($records) === 12 && count(array_unique(array_column($records, 'sequence'))) === 12,
        'Concurrent audit records lost or interleaved');
    unlink($audit); symlink($outside, $audit);
    refused(fn() => deployment_retain_privileged("{}\n", $root));
    check(file_get_contents($outside) === 'fixture', 'Audit symlink target changed');
    echo "Shared admission, bounded state, contention, private audit rotation and concurrency passed.\n";
} finally {
    foreach (glob($root . '/*') as $entry) unlink($entry);
    rmdir($root);
}
