<?php

require_once __DIR__ . '/lib/access.php';
require_once __DIR__ . '/lib/progress.php';

mya_require_local();

header('Content-Type: application/json');
header('Cache-Control: no-store');

$state = mya_progress_get((string) ($_GET['turn'] ?? ''));

echo json_encode([
    'step'    => $state['step'] ?? null,
    'elapsed' => isset($state['at']) ? max(0, time() - (int) $state['at']) : 0,
]);
