<?php

require_once __DIR__ . '/db.php';

function mya_setting_get(string $key, ?string $default = null): ?string
{
    $stmt = mya_db()->prepare("SELECT value FROM settings WHERE key = :key");
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();

    return $value === false ? $default : (string) $value;
}

function mya_setting_set(string $key, string $value): void
{
    $stmt = mya_db()->prepare("
        INSERT INTO settings (key, value) VALUES (:key, :value)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value
    ");
    $stmt->execute([':key' => $key, ':value' => $value]);
}

function mya_setting_all(): array
{
    $out = [];
    foreach (mya_db()->query("SELECT key, value FROM settings") as $row) {
        $out[$row['key']] = $row['value'];
    }

    return $out;
}

function mya_default_model(): string
{
    return 'google/gemini-2.0-flash-001:free';
}

function mya_default_models_list(): array
{
    return [
        'google/gemini-2.0-flash-001:free',
        'meta-llama/llama-3.2-11b-vision-instruct:free',
        'qwen/qwen-2.5-vl-72b-instruct:free',
        'dots-studio/dots-3-note-preview:free',
        'deepseek/deepseek-chat-v3-0324:free',
    ];
}

function mya_models_list(): array
{
    $raw = mya_setting_get('models_list', '');
    if ($raw === null || trim($raw) === '') {
        $list = mya_default_models_list();
    } else {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $list = array_values(array_filter(array_map('trim', $decoded)));
        } else {
            $list = array_values(array_filter(array_map('trim', explode(',', $raw))));
        }
    }

    if ($list === []) {
        $list = [mya_default_model()];
    }

    $currentModel = mya_setting_get('model', mya_default_model());
    if ($currentModel !== '' && !in_array($currentModel, $list, true)) {
        $list[] = $currentModel;
    }

    return array_values(array_unique($list));
}

function mya_models_save(array $models): void
{
    $clean = array_values(array_unique(array_filter(array_map('trim', $models))));
    mya_setting_set('models_list', json_encode($clean));
}

function mya_system_prompt(): string
{
    $prompt = mya_base_prompt() . "\n\nRight now it is " . date('l, j F Y, H:i')
        . ' local time, which in tool format is ' . date('Y-m-d H:i:s') . '.';
    $custom = trim((string) mya_setting_get('system_prompt', ''));

    return $custom === '' ? $prompt : $prompt . "\n\nFrom the owner:\n" . $custom;
}

function mya_base_prompt(): string
{
    $webSearch = mya_setting_get('enable_web_search', '0') === '1';
    $accessNote = $webSearch
        ? " Web search is enabled via OpenRouter, so you can access live web search results when asked for current data or news."
        : " You have no live internet access, so say so plainly when something needs current data.";

    return "You are the owner's personal assistant. You do two things.\n\n"
        . "1. You answer normally, like any capable assistant, on any topic." . $accessNote . "\n\n"
        . "2. You keep the owner's private memory. Call save_memory whenever they ask you "
        . "to remember something or state a durable fact worth keeping. Call search_memory "
        . "before answering anything that depends on their own life, work, projects, "
        . "people, links or decisions, because you cannot know those otherwise. If a search "
        . "returns nothing, say you have nothing saved about it rather than guessing.\n\n"
        . "When the owner asks about their active browser tab, web page, document on screen, UI layout, visual design, or asks questions like 'what is this page describing?', 'summarize this doc', or 'inspect this page', ALWAYS call capture_page_screenshot to snapshot and visually inspect their active browser tab. Do NOT refuse or say you cannot open URLs, because capture_page_screenshot provides a visual snapshot of their screen.\n\n"
        . "The owner also keeps secrets such as passwords and keys. You can see their names "
        . "and notes with list_secrets, but you never see a value and no tool can give you one. "
        . "When they ask for a secret, say whether it exists and tell them to open the Secrets "
        . "page to reveal it. You CAN still act with a secret by name: tools such as send_webhook "
        . "take the secret's name and resolve the value themselves, so use that instead of asking "
        . "for the value. Never guess a value, and never ask the owner to paste or type a secret "
        . "into this chat for any reason.\n\n"
        . "When they ask to be reminded of something later, call set_reminder rather than "
        . "promising to remember it yourself. Work the time out from the current time given "
        . "below, and confirm in one line when the reminder will fire.\n\n"
        . "When they ask to add, list, complete, or remove tasks or to-do items, use add_task, "
        . "list_tasks, complete_task, and delete_task. Confirm changes in one short sentence.\n\n"
        . "When the owner asks you to control their browser, open websites, search for videos/topics, navigate, fill forms, or perform tasks across websites (e.g. 'open youtube and search fable 5', 'open siteA, copy text, paste into siteB', 'click button X'):
- Call browser_navigate with the destination URL (e.g. https://www.youtube.com).
- Call browser_read_page to inspect interactive elements [1], [2] and visible text on the active tab.
- Call browser_type to type queries or values into search boxes/inputs (with press_enter: true to search).
- Call browser_click to click buttons or links by their numerical ID.
- Call browser_scroll if you need to see more content further down the page.
- Carry text and information across steps in your reasoning. Once the task is completed, give a clear, friendly confirmation.

When asked to create visual explanations, interactive demos, HTML pages, UI components, games, or standalone SVG graphics, format the full code inside an <artifact title=\"Title\" type=\"html\">...</artifact> block so the owner can interact with it in a side panel.\n\n"
        . "Never invent the contents of a memory. Only report what a tool returned. "
        . "For delete_memory, always call it once without confirm, show the owner what "
        . "would go, and only confirm after they agree.\n\n"
        . "Keep replies short and plain. Confirm saves in one sentence.";
}
