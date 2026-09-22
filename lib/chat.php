<?php

require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/progress.php';

function mya_chat_log(string $conversationId, string $role, string $content, ?string $toolName = null): int
{
    $stmt = mya_db()->prepare("
        INSERT INTO messages (conversation_id, role, content, tool_name, created_at)
        VALUES (:c, :r, :m, :t, :now)
    ");
    $stmt->execute([
        ':c'   => $conversationId,
        ':r'   => $role,
        ':m'   => $content,
        ':t'   => $toolName,
        ':now' => mya_now(),
    ]);

    return (int) mya_db()->lastInsertId();
}

function mya_chat_history(string $conversationId, int $limit = 20): array
{
    $stmt = mya_db()->prepare("
        SELECT role, content FROM (
            SELECT id, role, content FROM messages
            WHERE conversation_id = :c AND role IN ('user','assistant')
            ORDER BY id DESC LIMIT :limit
        ) ORDER BY id ASC
    ");
    $stmt->bindValue(':c', $conversationId, PDO::PARAM_STR);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function mya_default_conversation(): string
{
    return 'main';
}

function mya_current_conversation(): string
{
    $current = trim((string) mya_setting_get('current_conversation', ''));

    return $current === '' ? mya_default_conversation() : $current;
}

function mya_start_new_conversation(): string
{
    $fresh = 'c-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
    mya_setting_set('current_conversation', $fresh);

    return $fresh;
}

function mya_chat_recent(string $conversationId, int $limit = 40): array
{
    $stmt = mya_db()->prepare("
        SELECT role, content, tool_name FROM (
            SELECT id, role, content, tool_name FROM messages
            WHERE conversation_id = :c
            ORDER BY id DESC LIMIT :limit
        ) ORDER BY id ASC
    ");
    $stmt->bindValue(':c', $conversationId, PDO::PARAM_STR);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function mya_chat_turn(string $conversationId, string $message, ?callable $transport = null, ?array $pageContext = null, ?string $turnId = null, array $images = [], ?string $pageScreenshot = null, bool $allowBrowserControl = false): array
{
    $message = trim($message);
    if ($message === '' && empty($images)) {
        throw new InvalidArgumentException('Message or image is required.');
    }

    $allImages = $images;
    if (!empty($pageScreenshot) && is_string($pageScreenshot)) {
        $GLOBALS['mya_current_screenshot'] = $pageScreenshot;
        $allImages[] = $pageScreenshot;
    }

    $history = mya_chat_history($conversationId);

    $logContent = $message;
    if (!empty($images)) {
        foreach ($images as $img) {
            if (is_string($img) && $img !== '') {
                $logContent .= "\n\n![Uploaded image]($img)";
            }
        }
    }
    if (!empty($pageScreenshot)) {
        $logContent .= "\n\n*(📸 Screenshot captured)*";
    }

    mya_chat_log($conversationId, 'user', trim($logContent));

    $userMsg = [
        'role'    => 'user',
        'content' => $message . mya_chat_context_line($pageContext),
    ];
    if (!empty($allImages)) {
        $userMsg['images'] = $allImages;
    }

    $history[] = $userMsg;
    $transport = $transport ?? mya_ai_http_transport();

    $onStep = $turnId === null
        ? null
        : function (string $label) use ($turnId) { mya_progress_set($turnId, $label); };

    try {
        $out = mya_ai_loop($history, $transport, 8, $onStep, $allowBrowserControl);
    } catch (Throwable $e) {
        $reply = mya_chat_degraded_reply($message, $e->getMessage());
        mya_chat_log($conversationId, 'assistant', $reply);

        if ($turnId !== null) {
            mya_progress_clear($turnId);
        }

        return [
            'reply'         => $reply,
            'tools'         => [],
            'degraded'      => true,
            'stopped_early' => false,
        ];
    }

    if (!empty($out['requires_browser_action'])) {
        mya_chat_log($conversationId, 'tool', $out['tool_summary'], $out['tool_name']);
        return $out;
    }

    foreach ($out['tools'] ?? [] as $tool) {
        if (!empty($tool['result']['artifact']) && !str_contains($out['reply'], '<artifact')) {
            $out['reply'] .= "\n\n" . $tool['result']['artifact'];
        }
        mya_chat_log($conversationId, 'tool', $tool['summary'], $tool['name']);
    }
    mya_chat_log($conversationId, 'assistant', $out['reply']);

    if ($turnId !== null) {
        mya_progress_clear($turnId);
    }

    return [
        'reply'         => $out['reply'],
        'tools'         => $out['tools'] ?? [],
        'degraded'      => false,
        'stopped_early' => $out['stopped_early'] ?? false,
    ];
}

function mya_chat_resume_turn(string $conversationId, string $toolCallId, string $toolName, array $toolResult, array $history, ?callable $transport = null, ?string $turnId = null, bool $allowBrowserControl = true): array
{
    $history[] = [
        'role'         => 'tool',
        'tool_call_id' => $toolCallId,
        'name'         => $toolName,
        'content'      => json_encode($toolResult),
    ];

    $transport = $transport ?? mya_ai_http_transport();
    $onStep = $turnId === null
        ? null
        : function (string $label) use ($turnId) { mya_progress_set($turnId, $label); };

    try {
        $out = mya_ai_loop($history, $transport, 8, $onStep, $allowBrowserControl);
    } catch (Throwable $e) {
        $reply = "An error occurred during browser automation: " . $e->getMessage();
        mya_chat_log($conversationId, 'assistant', $reply);

        if ($turnId !== null) {
            mya_progress_clear($turnId);
        }

        return [
            'reply'         => $reply,
            'tools'         => [],
            'degraded'      => true,
            'stopped_early' => false,
        ];
    }

    if (!empty($out['requires_browser_action'])) {
        mya_chat_log($conversationId, 'tool', $out['tool_summary'], $out['tool_name']);
        return $out;
    }

    foreach ($out['tools'] ?? [] as $tool) {
        if (!empty($tool['result']['artifact']) && !str_contains($out['reply'], '<artifact')) {
            $out['reply'] .= "\n\n" . $tool['result']['artifact'];
        }
        mya_chat_log($conversationId, 'tool', $tool['summary'], $tool['name']);
    }
    mya_chat_log($conversationId, 'assistant', $out['reply']);

    if ($turnId !== null) {
        mya_progress_clear($turnId);
    }

    return [
        'reply'         => $out['reply'],
        'tools'         => $out['tools'] ?? [],
        'degraded'      => false,
        'stopped_early' => $out['stopped_early'] ?? false,
    ];
}

function mya_chat_context_line(?array $pageContext): string
{
    if ($pageContext === null) {
        return '';
    }

    $url   = trim((string) ($pageContext['url'] ?? ''));
    $title = trim((string) ($pageContext['title'] ?? ''));

    if ($url === '' && $title === '') {
        return '';
    }

    $label = $title === '' ? $url : ($url === '' ? $title : "$title — $url");

    return "\n\n[Active browser tab: $label (Use tool capture_page_screenshot to visually inspect and read this active tab screen)]";
}

function mya_chat_degraded_reply(string $message, string $error): string
{
    $hits  = mya_memory_search($message, 5);
    $lines = ["The AI is unavailable right now ($error)."];

    if ($hits === []) {
        $lines[] = 'I searched your memories directly and found nothing matching that.';

        return implode("\n\n", $lines);
    }

    $lines[] = 'Here is what I found in your memories by keyword:';
    foreach ($hits as $row) {
        $line = '- ' . $row['title'] . ': ' . $row['body'];
        if ($row['url'] !== null) {
            $line .= ' (' . $row['url'] . ')';
        }
        $lines[] = $line;
    }

    return implode("\n", $lines);
}
