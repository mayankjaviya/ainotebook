<?php

require_once __DIR__ . '/tools.php';
require_once __DIR__ . '/settings.php';

function mya_ai_loop(array $messages, callable $transport, int $maxRounds = 8, ?callable $onStep = null, bool $pauseForBrowserAction = false): array
{
    $step = function (string $label) use ($onStep) {
        if ($onStep !== null) {
            $onStep($label);
        }
    };

    $formattedMessages = mya_ai_format_messages($messages);

    $history = array_merge(
        [['role' => 'system', 'content' => mya_system_prompt()]],
        $formattedMessages
    );

    $model    = mya_setting_get('model', mya_default_model());
    $toolLog  = [];
    $reply    = '';
    $rounds   = 0;
    $fallback = false;

    while ($rounds < $maxRounds) {
        $rounds++;
        $step($toolLog === [] ? 'Thinking' : 'Writing the reply');

        $payload = ['model' => $model, 'messages' => $history];
        if ($fallback) {
            $payload['messages'][0]['content'] .= "\n\n" . mya_ai_fallback_prompt();
        } else {
            $payload['tools']       = mya_tool_definitions();
            $payload['tool_choice'] = 'auto';
        }

        mya_ai_apply_capabilities($payload);

        $message = mya_ai_message($transport($payload));
        $calls   = $message['tool_calls'] ?? [];
        $text    = trim((string) ($message['content'] ?? ''));

        if ($calls === [] && $text !== '' && $fallback) {
            $parsed = mya_ai_parse_json_tool($text);
            if ($parsed !== null && isset($parsed['tool'])) {
                $calls = [[
                    'id'       => 'fallback_' . $rounds,
                    'type'     => 'function',
                    'function' => [
                        'name'      => $parsed['tool'],
                        'arguments' => json_encode($parsed['arguments'] ?? []),
                    ],
                ]];
                $text = '';
            } elseif ($parsed !== null && isset($parsed['reply'])) {
                $text = $parsed['reply'];
            }
        }

        if ($calls !== []) {
            $history[] = $fallback
                ? ['role' => 'assistant', 'content' => $text]
                : $message;

            foreach ($calls as $call) {
                $name = $call['function']['name'] ?? '';
                $args = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
                if (!is_array($args)) {
                    $args = [];
                }

                $step(mya_tool_step_label($name));

                // If this is a browser_* tool and client action delegation is enabled, pause and dispatch to client
                if ($pauseForBrowserAction && str_starts_with($name, 'browser_')) {
                    $actionName = str_replace('browser_', '', $name);
                    return [
                        'requires_browser_action' => true,
                        'action'                  => $actionName,
                        'params'                  => $args,
                        'tool_name'               => $name,
                        'tool_call_id'            => $call['id'] ?? ('call_' . count($toolLog)),
                        'tool_summary'            => mya_tool_summary($name, ['status' => 'pending', 'params' => $args]),
                        'history'                 => $history,
                        'tools'                   => $toolLog,
                        'stopped_early'           => false,
                    ];
                }

                $result    = mya_tool_run($name, $args);
                $toolLog[] = [
                    'name'    => $name,
                    'summary' => mya_tool_summary($name, $result),
                    'result'  => $result,
                ];

                $toolContent = $result;
                $imageUrl    = null;
                if (isset($toolContent['image_url'])) {
                    $imageUrl = $toolContent['image_url'];
                    unset($toolContent['image_url']);
                }

                $history[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $call['id'] ?? ('call_' . count($toolLog)),
                    'name'         => $name,
                    'content'      => json_encode($toolContent),
                ];

                if (!empty($imageUrl)) {
                    $history[] = [
                        'role'    => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => '[Tool image captured for ' . $name . ']'],
                            ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]],
                        ],
                    ];
                }
            }

            $fallback = false;
            continue;
        }

        if ($text !== '') {
            return [
                'reply'         => $text,
                'tools'         => $toolLog,
                'stopped_early' => false,
                'rounds'        => $rounds,
            ];
        }

        if (!$fallback) {
            $fallback = true;
            $rounds--;
            continue;
        }

        $reply = 'The model returned an empty response. Try sending that again.';
        break;
    }

    if ($reply === '') {
        $reply = $toolLog === []
            ? 'I stopped after ' . $maxRounds . ' steps without reaching an answer.'
            : 'I stopped after ' . $maxRounds . ' steps. Here is what I did: '
              . implode('; ', array_column($toolLog, 'summary')) . '.';
    }

    return [
        'reply'         => $reply,
        'tools'         => $toolLog,
        'stopped_early' => true,
        'rounds'        => $rounds,
    ];
}

function mya_ai_message(array $response): array
{
    if (isset($response['error'])) {
        $msg = $response['error']['message'] ?? 'Unknown provider error';
        throw new RuntimeException('OpenRouter: ' . $msg);
    }

    return $response['choices'][0]['message'] ?? [];
}

function mya_ai_http_transport(): callable
{
    return function (array $payload): array {
        $key = trim((string) mya_setting_get('openrouter_key', ''));
        if ($key === '') {
            throw new RuntimeException('No OpenRouter API key is set. Add one in Settings.');
        }

        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
                'HTTP-Referer: http://localhost/tools/notebook/',
                'X-Title: Notebook',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
        ]);

        $body   = curl_exec($ch);
        $errNo  = curl_errno($ch);
        $errMsg = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0) {
            throw new RuntimeException('Could not reach OpenRouter: ' . $errMsg);
        }
        if ($status !== 200) {
            $short = substr((string) $body, 0, 300);
            throw new RuntimeException("OpenRouter returned HTTP $status. $short");
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenRouter returned a response that was not JSON.');
        }

        return $decoded;
    };
}

function mya_ai_fallback_prompt(): string
{
    $lines = [];
    foreach (mya_tool_definitions() as $tool) {
        $fn      = $tool['function'];
        $params  = implode(', ', array_keys((array) ($fn['parameters']['properties'] ?? [])));
        $lines[] = "- {$fn['name']}($params): {$fn['description']}";
    }

    return "TOOLS. You may use these by replying with ONE json object and nothing else:\n"
        . implode("\n", $lines) . "\n\n"
        . 'To use a tool reply exactly {"tool":"<name>","arguments":{...}}. '
        . 'To answer normally reply exactly {"reply":"<your answer>"}. '
        . 'No prose outside the json, no markdown fences.';
}

function mya_ai_parse_json_tool(string $text): ?array
{
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);

    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }

    $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
    if (!is_array($decoded)) {
        return null;
    }

    if (isset($decoded['tool']) && is_string($decoded['tool'])) {
        return [
            'tool'      => $decoded['tool'],
            'arguments' => is_array($decoded['arguments'] ?? null) ? $decoded['arguments'] : [],
        ];
    }
    if (isset($decoded['reply']) && is_string($decoded['reply'])) {
        return ['reply' => $decoded['reply']];
    }

    return null;
}

function mya_ai_apply_capabilities(array &$payload): void
{
    if (mya_setting_get('enable_web_search', '0') === '1') {
        $payload['plugins'] = [['id' => 'web']];
    }

    $reasoning = (string) mya_setting_get('reasoning_effort', 'default');
    if ($reasoning !== 'default' && in_array($reasoning, ['low', 'medium', 'high'], true)) {
        $payload['reasoning'] = ['effort' => $reasoning];
    }

    if (mya_setting_get('allow_fallbacks', '1') === '0') {
        $payload['provider'] = ['allow_fallbacks' => false];
    }
}

function mya_ai_format_messages(array $messages): array
{
    $formatted = [];
    foreach ($messages as $msg) {
        if (!is_array($msg)) {
            continue;
        }

        $role = $msg['role'] ?? 'user';
        $item = $msg;

        if ($role === 'user') {
            $images = [];
            if (!empty($msg['images']) && is_array($msg['images'])) {
                $images = $msg['images'];
            }

            $rawContent = is_string($msg['content'] ?? null) ? $msg['content'] : '';

            if ($rawContent !== '' && str_contains($rawContent, 'data:image/')) {
                preg_match_all('/!\[[^\]]*\]\((data:image\/[a-zA-Z]+;base64,[^\)]+)\)/i', $rawContent, $m);
                if (!empty($m[1])) {
                    foreach ($m[1] as $imgUrl) {
                        $images[] = $imgUrl;
                    }
                    $rawContent = preg_replace('/!\[[^\]]*\]\(data:image\/[a-zA-Z]+;base64,[^\)]+\)/i', '', $rawContent);
                    $rawContent = trim($rawContent);
                }
            }

            if (!empty($images)) {
                $contentArray = [];
                if ($rawContent !== '') {
                    $contentArray[] = ['type' => 'text', 'text' => $rawContent];
                }
                foreach ($images as $img) {
                    if (is_string($img) && $img !== '') {
                        $contentArray[] = ['type' => 'image_url', 'image_url' => ['url' => $img]];
                    }
                }
                $item['content'] = $contentArray;
                unset($item['images']);
            }
        }

        $formatted[] = $item;
    }

    return $formatted;
}
