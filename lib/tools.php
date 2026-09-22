<?php

require_once __DIR__ . '/memory.php';
require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/reminders.php';
require_once __DIR__ . '/tasks.php';

function mya_tool_definitions(): array
{
    return [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'save_memory',
                'description' => 'Store something the owner asked you to remember. '
                    . 'Use it whenever they say remember / note / keep this / save this, '
                    . 'and also when they state a durable personal fact worth keeping.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Short label, 2-6 words.'],
                        'body'  => ['type' => 'string', 'description' => 'The full detail to store.'],
                        'url'   => ['type' => 'string', 'description' => 'A link, if one was given.'],
                        'tags'  => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => 'A few lowercase keywords.',
                        ],
                    ],
                    'required'   => ['title', 'body'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'search_memory',
                'description' => 'Look through the owner\'s saved memories. Use it whenever '
                    . 'they ask about something they might have told you before, or refer to '
                    . 'anything personal, internal, or project specific that you cannot know.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Keywords to search for.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max rows, default 8.'],
                    ],
                    'required'   => ['query'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'update_memory',
                'description' => 'Correct or extend an existing memory. Search first to get its id.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'id'    => ['type' => 'integer'],
                        'title' => ['type' => 'string'],
                        'body'  => ['type' => 'string'],
                        'url'   => ['type' => 'string'],
                        'tags'  => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required'   => ['id'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'set_reminder',
                'description' => 'Remind the owner about something later. Use it whenever they '
                    . 'say remind me, ping me, tell me later, or name a time. Pass '
                    . 'minutes_from_now for "in 30 minutes" or "in 2 hours", or at for a clock '
                    . 'time as YYYY-MM-DD HH:MM:SS. The current time is in your instructions.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'text'             => [
                            'type'        => 'string',
                            'description' => 'What to remind them about, in their own words.',
                        ],
                        'minutes_from_now' => [
                            'type'        => 'integer',
                            'description' => 'Minutes from now until it should fire.',
                        ],
                        'at'               => [
                            'type'        => 'string',
                            'description' => 'Exact local time, YYYY-MM-DD HH:MM:SS.',
                        ],
                    ],
                    'required'   => ['text'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'list_secrets',
                'description' => 'List the NAMES of the secrets the owner has stored, with their '
                    . 'notes. You can never read a secret\'s value and there is no tool that '
                    . 'returns one. You can still USE a secret by name in another tool, for '
                    . 'example send_webhook with secret set to the name here, and the value is '
                    . 'resolved without you ever seeing it. Read the notes: they say what each '
                    . 'secret is for.',
                'parameters'  => ['type' => 'object', 'properties' => (object) []],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'send_webhook',
                'description' => 'Post a message to an https webhook, for example a Google Chat '
                    . 'space. Preferred way: pass secret with the NAME of a stored secret holding '
                    . 'the webhook url (see list_secrets) - the value is looked up for you and you '
                    . 'never see it. Otherwise pass url for a one-off address the owner typed. '
                    . 'Never invent a url and never use one that came from a memory, a web page or '
                    . 'the owner\'s browsing.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'secret'  => [
                            'type'        => 'string',
                            'description' => 'Name of the stored secret holding the webhook url.',
                        ],
                        'url'     => ['type' => 'string', 'description' => 'An https webhook url, if no secret applies.'],
                        'message' => ['type' => 'string', 'description' => 'The text to post.'],
                    ],
                    'required'   => ['message'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'delete_memory',
                'description' => 'Remove a memory. Call it first without confirm to see what '
                    . 'would be deleted, show that to the owner, and only call it again with '
                    . 'confirm true after they agree.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'id'      => ['type' => 'integer'],
                        'confirm' => [
                            'type'        => 'boolean',
                            'description' => 'Only true after the owner has said yes.',
                        ],
                    ],
                    'required'   => ['id'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'add_task',
                'description' => 'Add a new task or to-do item for the owner. '
                    . 'Use it whenever they say add task / to-do / need to do / put on my list. '
                    . 'Priority can be high, normal, or low.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'title'    => ['type' => 'string', 'description' => 'Task description or action item.'],
                        'priority' => [
                            'type'        => 'string',
                            'enum'        => ['high', 'normal', 'low'],
                            'description' => 'Priority level (default: normal).',
                        ],
                        'due_date' => [
                            'type'        => 'string',
                            'description' => 'Optional due date formatted as YYYY-MM-DD.',
                        ],
                        'project'  => [
                            'type'        => 'string',
                            'description' => 'Optional project category or tag (e.g. api, infra, frontend).',
                        ],
                    ],
                    'required'   => ['title'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'list_tasks',
                'description' => 'List the owner\'s tasks/to-dos. Use it whenever they ask what tasks they have, '
                    . 'what is pending, what to work on next, or check high priority items.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'status'   => [
                            'type'        => 'string',
                            'enum'        => ['pending', 'completed', 'all'],
                            'description' => 'Filter by status (default: pending).',
                        ],
                        'priority' => [
                            'type'        => 'string',
                            'enum'        => ['high', 'normal', 'low'],
                            'description' => 'Optional filter by priority.',
                        ],
                        'project'  => [
                            'type'        => 'string',
                            'description' => 'Optional filter by project name.',
                        ],
                    ],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'complete_task',
                'description' => 'Mark a task as completed/done. Pass the task id or the search title.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'id'          => ['type' => 'integer', 'description' => 'Task ID to mark complete.'],
                        'title_query' => ['type' => 'string', 'description' => 'Keywords to find the task if ID is unknown.'],
                    ],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'delete_task',
                'description' => 'Remove/delete a task by ID.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'Task ID to delete.'],
                    ],
                    'required'   => ['id'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'artifact',
                'description' => 'Create a visual web artifact, demo, UI component, HTML page, or game.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Short title for the artifact.'],
                        'type'  => ['type' => 'string', 'description' => 'Artifact type, e.g. html.'],
                        'code'  => ['type' => 'string', 'description' => 'Full single-file code.'],
                    ],
                    'required'   => ['title', 'code'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'capture_page_screenshot',
                'description' => 'Capture a visual screenshot of the owner\'s active browser tab, page, document, or screen. '
                    . 'ALWAYS call this tool whenever the owner asks about their active tab, current page, document on screen, '
                    . 'UI layout, visual elements, or asks questions like "what is this page describing?", "summarize this doc", '
                    . 'or "what is on my screen?". Do NOT refuse, because this tool returns the full page image directly.',
                'parameters'  => ['type' => 'object', 'properties' => (object) []],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'browser_navigate',
                'description' => 'Navigate the active Chrome browser tab to any web URL (e.g. https://www.youtube.com, https://google.com, https://wikipedia.org). '
                    . 'Use this whenever the owner asks to open a website or browse to a specific address.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'url' => [
                            'type'        => 'string',
                            'description' => 'The full destination URL to navigate to.',
                        ],
                    ],
                    'required'   => ['url'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'browser_read_page',
                'description' => 'Inspect and read the interactive elements (buttons, inputs, links) and visible text on the active browser tab. '
                    . 'Returns clean numbered element IDs (e.g. [1], [2]) and page text with all personal information/credentials scrubbed.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'focus_query' => [
                            'type'        => 'string',
                            'description' => 'Optional keywords or target element to look for.',
                        ],
                    ],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'browser_type',
                'description' => 'Type text into a search box, input field, or textarea on the active webpage, with an option to press Enter immediately.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'element_id'  => [
                            'type'        => 'integer',
                            'description' => 'The numerical ID [1], [2] of the input/textarea from browser_read_page.',
                        ],
                        'text'        => [
                            'type'        => 'string',
                            'description' => 'The exact text to type into the input field.',
                        ],
                        'press_enter' => [
                            'type'        => 'boolean',
                            'description' => 'Whether to trigger the Enter key or submit the form after typing (default: true).',
                        ],
                        'selector'    => [
                            'type'        => 'string',
                            'description' => 'Optional fallback CSS selector (e.g. "input[name=q]", "input#search").',
                        ],
                    ],
                    'required'   => ['text'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'browser_click',
                'description' => 'Click on a button, link, or interactive element on the active browser tab.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'element_id' => [
                            'type'        => 'integer',
                            'description' => 'The numerical ID [1], [2] of the element from browser_read_page.',
                        ],
                        'selector'   => [
                            'type'        => 'string',
                            'description' => 'Optional fallback CSS selector (e.g. "button[type=submit]", "a.nav-link").',
                        ],
                    ],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'browser_scroll',
                'description' => 'Scroll the active webpage down or up to reveal more content.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'direction' => [
                            'type'        => 'string',
                            'enum'        => ['down', 'up'],
                            'description' => 'Direction to scroll (default: down).',
                        ],
                        'amount'    => [
                            'type'        => 'integer',
                            'description' => 'Pixels to scroll (default: 500).',
                        ],
                    ],
                ],
            ],
        ],
    ];
}

function mya_tool_run(string $name, array $args): array
{
    try {
        switch ($name) {
            case 'save_memory':
                $id = mya_memory_save([
                    'title' => $args['title'] ?? '',
                    'body'  => $args['body'] ?? '',
                    'url'   => $args['url'] ?? null,
                    'tags'  => $args['tags'] ?? null,
                ]);

                return ['status' => 'saved', 'id' => $id, 'title' => mya_memory_get($id)['title']];

            case 'search_memory':
                $rows = mya_memory_search(
                    (string) ($args['query'] ?? ''),
                    (int) ($args['limit'] ?? 8)
                );

                return [
                    'status'  => 'ok',
                    'count'   => count($rows),
                    'results' => array_map('mya_tool_trim_row', $rows),
                ];

            case 'update_memory':
                $row = mya_memory_update((int) ($args['id'] ?? 0), $args);

                return ['status' => 'updated', 'memory' => mya_tool_trim_row($row)];

            case 'set_reminder':
                $minutes = (int) ($args['minutes_from_now'] ?? 0);
                $at      = trim((string) ($args['at'] ?? ''));

                if ($minutes > 0) {
                    $due = time() + $minutes * 60;
                } elseif ($at !== '') {
                    $due = strtotime($at);
                    if ($due === false) {
                        return ['error' => "I could not read that time: $at"];
                    }
                } else {
                    return ['error' => 'Say when: minutes_from_now, or at as YYYY-MM-DD HH:MM:SS.'];
                }

                if ($due <= time()) {
                    return ['error' => 'That time has already passed. Ask the owner when they meant.'];
                }

                $dueAt = date('Y-m-d H:i:s', $due);
                $id    = mya_reminder_add((string) ($args['text'] ?? ''), $dueAt);

                return ['status' => 'set', 'id' => $id, 'due_at' => $dueAt];

            case 'list_secrets':
                $index = mya_secret_index();

                return ['status' => 'ok', 'count' => count($index), 'secrets' => $index];

            case 'send_webhook':
                $target = trim((string) ($args['secret'] ?? ''));
                if ($target !== '') {
                    $secret = mya_secret_get($target);
                    if ($secret === null) {
                        return ['error' => "No secret named \"$target\". Call list_secrets to see the names."];
                    }
                    $url = $secret['value'];
                } else {
                    $url = (string) ($args['url'] ?? '');
                }

                return mya_webhook_send($url, (string) ($args['message'] ?? ''));

            case 'delete_memory':
                $id  = (int) ($args['id'] ?? 0);
                $row = mya_memory_get($id);
                if ($row === null) {
                    return ['error' => "No memory with id $id."];
                }
                if (($args['confirm'] ?? false) !== true) {
                    return [
                        'status' => 'confirmation_required',
                        'memory' => mya_tool_trim_row($row),
                        'note'   => 'Show this to the owner and ask before deleting.',
                    ];
                }
                mya_memory_delete($id);

                return ['status' => 'deleted', 'id' => $id];

            case 'add_task':
                $id = mya_task_add([
                    'title'    => $args['title'] ?? '',
                    'priority' => $args['priority'] ?? 'normal',
                    'due_date' => $args['due_date'] ?? null,
                    'project'  => $args['project'] ?? null,
                ]);
                $task = mya_task_get($id);

                return ['status' => 'added', 'id' => $id, 'task' => $task];

            case 'list_tasks':
                $tasks = mya_task_list([
                    'status'   => $args['status'] ?? 'pending',
                    'priority' => $args['priority'] ?? '',
                    'project'  => $args['project'] ?? '',
                ]);

                return ['status' => 'ok', 'count' => count($tasks), 'tasks' => $tasks];

            case 'complete_task':
                $id = (int) ($args['id'] ?? 0);
                if ($id <= 0 && !empty($args['title_query'])) {
                    $found = mya_task_list(['status' => 'pending', 'search' => (string) $args['title_query']]);
                    if (count($found) === 1) {
                        $id = (int) $found[0]['id'];
                    } elseif (count($found) > 1) {
                        return ['error' => 'Multiple matching pending tasks found. Please specify the task ID: '
                            . implode(', ', array_map(fn($t) => "#{$t['id']} ({$t['title']})", $found))];
                    } else {
                        return ['error' => "No pending task found matching \"{$args['title_query']}\"."];
                    }
                }
                if ($id <= 0) {
                    return ['error' => 'Please provide the task ID or title to complete.'];
                }
                $task = mya_task_get($id);
                if ($task === null) {
                    return ['error' => "No task with id $id."];
                }
                mya_task_complete($id);

                return ['status' => 'completed', 'id' => $id, 'title' => $task['title']];

            case 'delete_task':
                $id = (int) ($args['id'] ?? 0);
                $task = mya_task_get($id);
                if ($task === null) {
                    return ['error' => "No task with id $id."];
                }
                mya_task_delete($id);

                return ['status' => 'deleted', 'id' => $id, 'title' => $task['title']];

            case 'artifact':
            case 'create_artifact':
                $title = trim((string) ($args['title'] ?? $args['name'] ?? 'Visual Preview'));
                $type  = trim((string) ($args['type'] ?? 'html'));
                $code  = trim((string) ($args['code'] ?? $args['content'] ?? $args['html'] ?? ''));

                return [
                    'status'   => 'created',
                    'artifact' => "<artifact title=\"{$title}\" type=\"{$type}\">{$code}</artifact>",
                    'title'    => $title,
                ];

            case 'capture_page_screenshot':
                static $capturedCount = 0;
                $capturedCount++;
                if ($capturedCount > 1) {
                    return [
                        'status' => 'already_captured',
                        'note'   => 'Active tab screenshot was already captured and attached above. Analyze the image and provide your final textual answer now without calling capture_page_screenshot again.',
                    ];
                }

                $screenshot = $GLOBALS['mya_current_screenshot'] ?? null;
                if (empty($screenshot) && !empty($args['image_url'])) {
                    $screenshot = (string) $args['image_url'];
                }
                if (!empty($screenshot)) {
                    return [
                        'status'    => 'captured',
                        'image_url' => $screenshot,
                        'note'      => 'Active tab screenshot captured successfully. Analyze the image to answer the user.',
                    ];
                }
                return [
                    'status' => 'not_available',
                    'note'   => 'Screenshot was not provided by user or permission was not granted. Answer based on available context.',
                ];

            case 'browser_navigate':
            case 'browser_read_page':
            case 'browser_type':
            case 'browser_click':
            case 'browser_scroll':
                // Check if result was provided in global context by client bridge
                $provided = $GLOBALS['mya_browser_action_result'] ?? null;
                if ($provided !== null && is_array($provided)) {
                    return $provided;
                }
                return [
                    'status'  => 'executed',
                    'action'  => $name,
                    'params'  => $args,
                    'message' => 'Action executed on active browser tab.',
                ];
        }

        return ['error' => "Unknown tool: $name"];
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

function mya_tool_summary(string $name, array $result): string
{
    if (isset($result['error'])) {
        return 'Tool failed: ' . $result['error'];
    }

    switch ($name) {
        case 'artifact':
        case 'create_artifact':
            return 'Created visual artifact';
        case 'save_memory':
            return 'Saved to memory';
        case 'search_memory':
            return 'Searched memories (' . (int) ($result['count'] ?? 0) . ' results)';
        case 'update_memory':
            return 'Updated a memory';
        case 'delete_memory':
            return ($result['status'] ?? '') === 'deleted'
                ? 'Deleted a memory'
                : 'Waiting for your confirmation to delete';
        case 'send_webhook':
            return 'Sent to ' . ($result['host'] ?? 'webhook');
        case 'list_secrets':
            return 'Listed your secret names (' . (int) ($result['count'] ?? 0) . ')';
        case 'set_reminder':
            return isset($result['due_at'])
                ? 'Reminder set for ' . date('g:ia', strtotime($result['due_at']))
                : 'Reminder not set';
        case 'add_task':
            return 'Added task: ' . ($result['task']['title'] ?? 'task');
        case 'list_tasks':
            return 'Listed tasks (' . (int) ($result['count'] ?? 0) . ')';
        case 'complete_task':
            return 'Completed task: ' . ($result['title'] ?? '#' . ($result['id'] ?? ''));
        case 'delete_task':
            return 'Deleted task: ' . ($result['title'] ?? '#' . ($result['id'] ?? ''));
        case 'capture_page_screenshot':
            return 'Captured active tab screenshot';
        case 'browser_navigate':
            return 'Navigated to ' . ($result['params']['url'] ?? $result['url'] ?? 'page');
        case 'browser_read_page':
            return 'Inspected active webpage elements';
        case 'browser_type':
            return 'Typed "' . ($result['params']['text'] ?? $result['text'] ?? '') . '" into element';
        case 'browser_click':
            return 'Clicked element on webpage';
        case 'browser_scroll':
            return 'Scrolled webpage ' . ($result['params']['direction'] ?? 'down');
    }

    return $name;
}

function mya_webhook_send(string $url, string $message): array
{
    $host  = (string) parse_url($url, PHP_URL_HOST);
    $error = mya_webhook_reject_reason($url, $host);
    if ($error !== '') {
        return ['error' => $error];
    }

    $message = trim($message);
    if ($message === '') {
        return ['error' => 'There is no message to send.'];
    }
    if (mb_strlen($message) > 4000) {
        $message = mb_substr($message, 0, 3999) . '…';
    }

    $transport = $GLOBALS['mya_webhook_transport'] ?? null;
    if (!is_callable($transport)) {
        $transport = mya_webhook_http_transport();
    }

    $response = $transport($url, json_encode(['text' => $message]));

    if (($response['error'] ?? '') !== '') {
        return ['error' => 'Could not reach the webhook: ' . $response['error']];
    }

    $status = (int) ($response['status'] ?? 0);
    if ($status < 200 || $status > 299) {
        return ['error' => "The webhook replied HTTP $status. "
            . substr((string) ($response['body'] ?? ''), 0, 200)];
    }

    return ['status' => 'sent', 'host' => $host];
}

// Keeps the model from aiming the webhook at anything but a public https endpoint.
function mya_webhook_reject_reason(string $url, string $host): string
{
    if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
        return 'Webhooks must be https urls.';
    }
    if ($host === '') {
        return 'That url has no host.';
    }

    $lower = strtolower($host);
    if ($lower === 'localhost' || str_ends_with($lower, '.local') || str_ends_with($lower, '.internal')) {
        return 'Refusing to post to a local address.';
    }

    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        $public = filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
        if ($public === false) {
            return 'Refusing to post to a private or reserved address.';
        }
    }

    return '';
}

function mya_webhook_http_transport(): callable
{
    return function (string $url, string $json): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=UTF-8'],
            CURLOPT_POSTFIELDS     => $json,
        ]);

        $body   = curl_exec($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $body, 'error' => $error];
    };
}

function mya_tool_step_label(string $name): string
{
    switch ($name) {
        case 'save_memory':
            return 'Saving to memory';
        case 'search_memory':
            return 'Searching memories';
        case 'update_memory':
            return 'Updating a memory';
        case 'delete_memory':
            return 'Checking what to delete';
        case 'send_webhook':
            return 'Sending the message';
        case 'list_secrets':
            return 'Checking which secrets exist';
        case 'set_reminder':
            return 'Setting a reminder';
        case 'capture_page_screenshot':
            return 'Capturing active tab screenshot';
        case 'browser_navigate':
            return 'Navigating browser tab';
        case 'browser_read_page':
            return 'Inspecting webpage elements';
        case 'browser_type':
            return 'Typing on webpage';
        case 'browser_click':
            return 'Clicking element on webpage';
        case 'browser_scroll':
            return 'Scrolling webpage';
    }

    return 'Working';
}

function mya_tool_trim_row(array $row): array
{
    return [
        'id'         => (int) $row['id'],
        'title'      => $row['title'],
        'body'       => $row['body'],
        'url'        => $row['url'],
        'tags'       => $row['tags'],
        'updated_at' => $row['updated_at'],
    ];
}
