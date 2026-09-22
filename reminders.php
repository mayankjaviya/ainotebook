<?php

require_once __DIR__ . '/lib/access.php';
require_once __DIR__ . '/lib/reminders.php';

mya_require_local();

header('Content-Type: application/json');
header('Cache-Control: no-store');

// The extension polls this once a minute. Anything handed out is marked as
// delivered on the chrome channel so it is never shown twice.
$due = [];
foreach (mya_reminder_due('chrome') as $row) {
    $due[] = ['id' => (int) $row['id'], 'text' => $row['text'], 'due_at' => $row['due_at']];
    mya_reminder_mark((int) $row['id'], 'chrome');
}

echo json_encode(['reminders' => $due]);
