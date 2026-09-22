<?php

// nginx proxies this app to Apache, so REMOTE_ADDR is always 127.0.0.1 and says
// nothing about who is calling. nginx sets X-Real-IP itself and overwrites any
// value the client sent, so that header is the one worth trusting here.
function mya_client_ip(): string
{
    $real = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));

    return $real !== '' ? $real : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

function mya_is_local_request(): bool
{
    return in_array(mya_client_ip(), ['127.0.0.1', '::1'], true);
}

function mya_require_local(): void
{
    if (PHP_SAPI === 'cli' || mya_is_local_request()) {
        return;
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Notebook runs on this computer only.\n";
    exit;
}
