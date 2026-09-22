<?php

require_once __DIR__ . '/lib/access.php';
require_once __DIR__ . '/lib/chat.php';

mya_require_local();

header('Content-Type: application/json');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new InvalidArgumentException('POST only.');
    }

    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Body must be JSON.');
    }

    $action = trim((string) ($input['action'] ?? ''));
    if ($action === 'toggle_web_search') {
        $current = mya_setting_get('enable_web_search', '0') === '1';
        $newState = isset($input['enabled']) ? ($input['enabled'] ? '1' : '0') : ($current ? '0' : '1');
        mya_setting_set('enable_web_search', $newState);
        echo json_encode(['ok' => true, 'enabled' => $newState === '1']);
        exit;
    }

    $conversation = trim((string) ($input['conversation_id'] ?? ''));
    if ($conversation === '') {
        $conversation = mya_current_conversation();
    }

    $turnId = trim((string) ($input['turn_id'] ?? ''));

    if ($action === 'resume_browser_action') {
        $toolCallId = (string) ($input['tool_call_id'] ?? '');
        $toolName   = (string) ($input['tool_name'] ?? '');
        $toolResult = is_array($input['tool_result'] ?? null) ? $input['tool_result'] : ['status' => 'completed'];
        $history    = is_array($input['history'] ?? null) ? $input['history'] : [];

        $out = mya_chat_resume_turn(
            $conversation,
            $toolCallId,
            $toolName,
            $toolResult,
            $history,
            null,
            $turnId === '' ? null : $turnId,
            true
        );
        $out['ok'] = true;
        echo json_encode($out);
        exit;
    }

    $context = null;
    if (isset($input['page_context']) && is_array($input['page_context'])) {
        $context = [
            'url'   => (string) ($input['page_context']['url'] ?? ''),
            'title' => (string) ($input['page_context']['title'] ?? ''),
        ];
    }

    $allowBrowserControl = !empty($input['allow_browser_control']);
    $images = is_array($input['images'] ?? null) ? $input['images'] : [];
    $screenshot = is_string($input['page_screenshot'] ?? null) ? trim($input['page_screenshot']) : null;

    $out       = mya_chat_turn(
        $conversation,
        (string) ($input['message'] ?? ''),
        null,
        $context,
        $turnId === '' ? null : $turnId,
        $images,
        $screenshot,
        $allowBrowserControl
    );
    $out['ok'] = true;

    echo json_encode($out);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
