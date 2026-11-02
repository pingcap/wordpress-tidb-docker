<?php
$context = stream_context_create(['http' => ['timeout' => 3]]);
exit(@file_get_contents('http://127.0.0.1:8080/healthz.txt', false, $context) === "ok\n" ? 0 : 1);
