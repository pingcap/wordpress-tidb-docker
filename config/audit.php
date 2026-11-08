<?php
// Available before WordPress loads plugins, including failed database connects.
// Never initialize the current user here: doing so can issue another DB query.
function deployment_audit(string $event, int $target = 0, array $context = []): void {
    static $request_id;
    $request_id ??= bin2hex(random_bytes(16));
    $actor = $GLOBALS['current_user']->ID ?? 0;
    $record = [
        'type' => 'wordpress_security',
        'event' => $event,
        'actor_id' => (int)$actor,
        'target_id' => $target,
        'request_id' => $request_id,
        'time' => gmdate('c'),
    ];
    // Only fixed-size keyed pseudonyms can extend the event. No raw input.
    foreach (['account_tag', 'source_tag'] as $field) {
        if (isset($context[$field]) && preg_match('/\A[0-9a-f]{32}\z/', $context[$field])) {
            $record[$field] = $context[$field];
        }
    }
    error_log(json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL, 3, 'php://stderr');
}

function deployment_login_context(string $name): array {
    $tag = function (string $kind, string $value): string {
        return substr(hash_hmac('sha256', "wordpress-audit\0" . $kind . "\0" . $value, AUTH_SALT), 0, 32);
    };
    $context = ['account_tag' => $tag('account', strtolower(trim(substr($name, 0, 256))))];
    // Apache admits application HTTP only from the Compose proxy. Caddy
    // overwrites this header with its TCP peer; user-supplied XFF is not used.
    $source = $_SERVER['HTTP_X_DEPLOYMENT_CLIENT_IP'] ?? '';
    if (PHP_SAPI !== 'cli' && filter_var($source, FILTER_VALIDATE_IP)) {
        $context['source_tag'] = $tag('source', inet_ntop(inet_pton($source)));
    }
    return $context;
}
