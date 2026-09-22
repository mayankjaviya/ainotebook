<?php
// Fires macOS notifications for reminders that are due.
// Run from a LaunchAgent or cron, once a minute:
//   php /Applications/XAMPP/xamppfiles/htdocs/tools/notebook/notify.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/lib/reminders.php';

$fired = 0;

foreach (mya_reminder_due('macos') as $row) {
    $script = sprintf(
        'display notification %s with title %s sound name "Ping"',
        mya_osascript_string($row['text']),
        mya_osascript_string('Notebook reminder')
    );

    exec('osascript -e ' . escapeshellarg($script), $out, $status);

    if ($status === 0) {
        mya_reminder_mark((int) $row['id'], 'macos');
        $fired++;
    } else {
        fwrite(STDERR, "osascript failed for reminder {$row['id']}\n");
    }
}

echo $fired === 0 ? "nothing due\n" : "fired $fired reminder(s)\n";

// AppleScript strings need their own escaping; a reminder is free text the
// owner dictated, so it can contain quotes and backslashes.
function mya_osascript_string(string $text): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $text) . '"';
}
