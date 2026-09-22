<?php

require_once __DIR__ . '/db.php';

function mya_progress_set(string $turnId, string $step): void
{
    $path = mya_progress_path($turnId);
    if ($path === '') {
        return;
    }

    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    @file_put_contents($path, json_encode(['step' => $step, 'at' => time()]), LOCK_EX);
}

function mya_progress_get(string $turnId): ?array
{
    $path = mya_progress_path($turnId);
    if ($path === '' || !is_file($path)) {
        return null;
    }

    $data = json_decode((string) @file_get_contents($path), true);

    return is_array($data) ? $data : null;
}

function mya_progress_clear(string $turnId): void
{
    $path = mya_progress_path($turnId);
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
}

// Empty string means the id is not usable, which keeps a crafted id from
// reaching any path outside the progress folder.
function mya_progress_path(string $turnId): string
{
    if ($turnId === '' || strlen($turnId) > 64 || preg_match('/[^A-Za-z0-9._-]/', $turnId)) {
        return '';
    }

    $base = defined('MYA_DB_PATH') ? dirname(MYA_DB_PATH) : mya_root() . '/data';

    return $base . '/progress/' . $turnId . '.json';
}
