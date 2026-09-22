<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

define('MYA_DB_PATH', sys_get_temp_dir() . '/mya_test_' . getmypid() . '.sqlite');
@unlink(MYA_DB_PATH);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/memory.php';
require_once __DIR__ . '/../lib/tools.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/ai.php';
require_once __DIR__ . '/../lib/chat.php';
require_once __DIR__ . '/../lib/view.php';
require_once __DIR__ . '/../lib/progress.php';
require_once __DIR__ . '/../lib/access.php';
require_once __DIR__ . '/../lib/secrets.php';
require_once __DIR__ . '/../lib/reminders.php';
require_once __DIR__ . '/../lib/tasks.php';

$GLOBALS['mya_pass'] = 0;
$GLOBALS['mya_fail'] = 0;

function mya_test(string $name, callable $fn): void
{
    try {
        $fn();
        $GLOBALS['mya_pass']++;
        echo "PASS  $name\n";
    } catch (Throwable $e) {
        $GLOBALS['mya_fail']++;
        echo "FAIL  $name\n      " . $e->getMessage() . "\n";
    }
}

function mya_assert(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function mya_assert_same($expected, $actual, string $msg): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($msg . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')');
    }
}

mya_test('db: connection opens and returns PDO', function () {
    mya_assert(mya_db() instanceof PDO, 'mya_db() did not return a PDO');
});

mya_test('db: schema tables exist', function () {
    $rows = mya_db()->query(
        "SELECT name FROM sqlite_master WHERE type IN ('table','view')"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach (['memories', 'memories_fts', 'messages', 'settings'] as $t) {
        mya_assert(in_array($t, $rows, true), "missing table: $t");
    }
});

mya_test('db: fts5 index is queryable', function () {
    $n = mya_db()->query("SELECT count(*) FROM memories_fts")->fetchColumn();
    mya_assert_same(0, (int) $n, 'fresh fts table should be empty');
});

mya_test('memory: save inserts and returns an id', function () {
    $id = mya_memory_save([
        'title'    => 'Feature A doc',
        'body'     => 'The specification for feature A lives in confluence',
        'url'      => 'https://example.test/feature-a',
        'tags'     => ['feature-a', 'doc'],
        'raw_text' => 'this is feature A doc, url is https://example.test/feature-a',
    ]);
    mya_assert($id > 0, 'expected a positive id');

    $row = mya_memory_get($id);
    mya_assert_same('Feature A doc', $row['title'], 'title round-trip');
    mya_assert_same('feature-a,doc', $row['tags'], 'tags stored comma separated');
});

mya_test('memory: save rejects an empty title', function () {
    try {
        mya_memory_save(['title' => '  ', 'body' => 'x']);
    } catch (InvalidArgumentException $e) {
        return;
    }
    throw new RuntimeException('expected InvalidArgumentException');
});

mya_test('memory: fts finds a memory by a word from the body', function () {
    $hits = mya_memory_search('confluence');
    mya_assert(count($hits) >= 1, 'expected at least one hit');
    mya_assert_same('Feature A doc', $hits[0]['title'], 'wrong row matched');
});

mya_test('memory: title match ranks above body-only match', function () {
    mya_memory_save([
        'title' => 'Deploy checklist',
        'body'  => 'Remember to mention the staging password before release',
    ]);
    mya_memory_save([
        'title' => 'Staging password',
        'body'  => 'Kept in the vault',
    ]);

    $hits = mya_memory_search('staging password');
    mya_assert(count($hits) >= 2, 'expected two hits');
    mya_assert_same('Staging password', $hits[0]['title'], 'title match should rank first');
});

mya_test('memory: search survives fts punctuation', function () {
    $hits = mya_memory_search('what is the "staging" password? (urgent)');
    mya_assert(count($hits) >= 1, 'punctuation should not break the query');
});

mya_test('memory: update changes the row and refreshes the index', function () {
    $id  = mya_memory_save(['title' => 'Old title', 'body' => 'zebra content']);
    $row = mya_memory_update($id, ['title' => 'New title', 'body' => 'giraffe content']);

    mya_assert_same('New title', $row['title'], 'title not updated');
    mya_assert_same(0, count(mya_memory_search('zebra')), 'stale term still indexed');
    mya_assert_same(1, count(mya_memory_search('giraffe')), 'new term not indexed');
});

mya_test('memory: delete removes the row and its index entry', function () {
    $id = mya_memory_save(['title' => 'Temporary', 'body' => 'platypus note']);
    mya_assert_same(1, count(mya_memory_search('platypus')), 'setup failed');

    mya_assert(mya_memory_delete($id), 'delete returned false');
    mya_assert_same(null, mya_memory_get($id), 'row still present');
    mya_assert_same(0, count(mya_memory_search('platypus')), 'index entry still present');
});

mya_test('tools: definitions expose all tools', function () {
    $names = array_map(fn($t) => $t['function']['name'], mya_tool_definitions());
    sort($names);
    mya_assert_same(
        [
            'add_task',
            'artifact',
            'complete_task',
            'delete_memory',
            'delete_task',
            'list_secrets',
            'list_tasks',
            'save_memory',
            'search_memory',
            'send_webhook',
            'set_reminder',
            'update_memory',
        ],
        $names,
        'unexpected tool list'
    );
});

mya_test('tools: save_memory stores through the dispatcher', function () {
    $out = mya_tool_run('save_memory', [
        'title' => 'Standup time',
        'body'  => 'Daily standup is at 10:15 on Google Meet',
        'tags'  => ['routine'],
    ]);
    mya_assert_same('saved', $out['status'], 'unexpected status');
    mya_assert(mya_memory_get($out['id']) !== null, 'row was not written');
});

mya_test('tools: search_memory returns trimmed rows', function () {
    $out = mya_tool_run('search_memory', ['query' => 'standup meet']);
    mya_assert_same('ok', $out['status'], 'unexpected status');
    mya_assert($out['count'] >= 1, 'expected a hit');
    mya_assert_same(
        ['id', 'title', 'body', 'url', 'tags', 'updated_at'],
        array_keys($out['results'][0]),
        'result rows expose the wrong columns'
    );
});

mya_test('tools: delete without confirm deletes nothing', function () {
    $id  = mya_memory_save(['title' => 'Throwaway', 'body' => 'delete me']);
    $out = mya_tool_run('delete_memory', ['id' => $id]);

    mya_assert_same('confirmation_required', $out['status'], 'wrong status');
    mya_assert_same('Throwaway', $out['memory']['title'], 'matched row not returned');
    mya_assert(mya_memory_get($id) !== null, 'row was deleted without confirmation');
});

mya_test('tools: delete with confirm removes the row', function () {
    $id  = mya_memory_save(['title' => 'Throwaway 2', 'body' => 'delete me too']);
    $out = mya_tool_run('delete_memory', ['id' => $id, 'confirm' => true]);

    mya_assert_same('deleted', $out['status'], 'wrong status');
    mya_assert_same(null, mya_memory_get($id), 'row survived a confirmed delete');
});

mya_test('tools: unknown tool returns an error instead of throwing', function () {
    $out = mya_tool_run('send_email', ['to' => 'x']);
    mya_assert(isset($out['error']), 'expected an error key');
});

mya_test('tools: a bad argument comes back as an error the model can read', function () {
    $out = mya_tool_run('save_memory', ['title' => '', 'body' => '']);
    mya_assert(isset($out['error']), 'expected an error key');
    mya_assert(str_contains($out['error'], 'title'), 'error should name the problem');
});

mya_test('view: mya_markdown converts markdown into formatted html', function () {
    $raw = "**Bold Header**\n- **Item 1**: value\n- [Google](https://google.com)";
    $html = mya_markdown($raw);

    mya_assert(str_contains($html, '<strong>Bold Header</strong>'), 'bold text missing');
    mya_assert(str_contains($html, '<ul>') && str_contains($html, '<li><strong>Item 1</strong>: value</li>'), 'bullet list missing');
    mya_assert(str_contains($html, '<a href="https://google.com" target="_blank" rel="noopener noreferrer">Google</a>'), 'link missing');
});

mya_test('view: mya_markdown converts artifact tags into interactive artifact cards', function () {
    $raw = 'Here is the visual demo:' . "\n\n" . '<artifact title="Solar System" type="html"><h1>Solar</h1></artifact>';
    $html = mya_markdown($raw);

    mya_assert(str_contains($html, 'class="artifact-card"'), 'artifact card wrapper missing');
    mya_assert(str_contains($html, 'Solar System'), 'artifact title missing');
    mya_assert(str_contains($html, 'btn-open-artifact'), 'open preview button missing');
});

mya_test('creations: mya_creations_list queries stored artifacts from messages', function () {
    mya_chat_log('conv-creations-test', 'assistant', '<artifact title="Test App" type="html"><h1>App</h1></artifact>');
    $list = mya_creations_list();

    mya_assert(count($list) >= 1, 'expected at least one creation');
    mya_assert_same('Test App', $list[0]['title'], 'wrong creation title');
});

mya_test('settings: unknown key falls back to the default', function () {
    mya_assert_same('none', mya_setting_get('nope', 'none'), 'default not returned');
});

mya_test('settings: set then get round-trips and overwrites', function () {
    mya_setting_set('model', 'a/b:free');
    mya_assert_same('a/b:free', mya_setting_get('model'), 'first write lost');

    mya_setting_set('model', 'c/d:free');
    mya_assert_same('c/d:free', mya_setting_get('model'), 'overwrite failed');
});

mya_test('settings: default model is the agreed free model', function () {
    mya_assert_same('deepseek/deepseek-chat-v3-0324:free', mya_default_model(), 'wrong default');
});

mya_test('settings: models list can be managed and saved', function () {
    $models = ['model/a', 'model/b'];
    mya_models_save($models);
    mya_setting_set('model', 'model/b');

    $list = mya_models_list();
    mya_assert(in_array('model/a', $list, true), 'model/a missing');
    mya_assert(in_array('model/b', $list, true), 'model/b missing');

    mya_setting_set('models_list', '');
});

mya_test('settings: system prompt mentions the memory tools', function () {
    $p = mya_system_prompt();
    mya_assert(str_contains($p, 'save_memory'), 'prompt must name save_memory');
    mya_assert(str_contains($p, 'search_memory'), 'prompt must name search_memory');
});

mya_test('settings: custom instructions are added, never replace the built-in ones', function () {
    mya_setting_set('system_prompt', 'My chat webhook is https://chat.googleapis.com/v1/spaces/AAA');

    $p = mya_system_prompt();
    mya_assert(str_contains($p, 'save_memory'), 'built-in instructions were lost');
    mya_assert(str_contains($p, 'https://chat.googleapis.com/v1/spaces/AAA'), 'custom text missing');
    mya_assert(
        strpos($p, 'save_memory') < strpos($p, 'chat.googleapis.com'),
        'custom text should come after the built-in instructions'
    );

    mya_setting_set('system_prompt', '');
    mya_assert(!str_contains(mya_system_prompt(), 'chat.googleapis.com'), 'clearing it should remove the text');
});

mya_test('settings: web search capability adapts system prompt and payload', function () {
    mya_setting_set('enable_web_search', '1');
    mya_assert(str_contains(mya_system_prompt(), 'Web search is enabled via OpenRouter'), 'system prompt should reflect web search');

    $payload = ['model' => 'test'];
    mya_ai_apply_capabilities($payload);
    mya_assert_same([['id' => 'web']], $payload['plugins'] ?? null, 'web plugin missing from payload');

    mya_setting_set('enable_web_search', '0');
    $payload = ['model' => 'test'];
    mya_ai_apply_capabilities($payload);
    mya_assert(!isset($payload['plugins']), 'web plugin should not be present when disabled');

    // Toggle logic verification
    $curr = mya_setting_get('enable_web_search', '0') === '1';
    mya_setting_set('enable_web_search', $curr ? '0' : '1');
    mya_assert(mya_setting_get('enable_web_search', '0') === '1', 'toggling from 0 should set to 1');
    mya_setting_set('enable_web_search', '0');
});

mya_test('settings: reasoning effort and provider fallbacks update payload', function () {
    mya_setting_set('reasoning_effort', 'high');
    mya_setting_set('allow_fallbacks', '0');

    $payload = ['model' => 'test'];
    mya_ai_apply_capabilities($payload);
    mya_assert_same(['effort' => 'high'], $payload['reasoning'] ?? null, 'reasoning effort missing');
    mya_assert_same(['allow_fallbacks' => false], $payload['provider'] ?? null, 'provider fallback setting missing');

    mya_setting_set('reasoning_effort', 'default');
    mya_setting_set('allow_fallbacks', '1');

    $payload = ['model' => 'test'];
    mya_ai_apply_capabilities($payload);
    mya_assert(!isset($payload['reasoning']), 'reasoning should be omitted when default');
    mya_assert(!isset($payload['provider']), 'provider should be omitted when fallbacks enabled');
});

function mya_fake_transport(array $replies, ?array &$seen = null): callable
{
    $i = 0;

    return function (array $payload) use ($replies, &$i, &$seen) {
        if ($seen !== null) {
            $seen[] = $payload;
        }
        if (!isset($replies[$i])) {
            throw new RuntimeException('fake transport ran out of replies');
        }

        return $replies[$i++];
    };
}

function mya_reply_text(string $text): array
{
    return ['choices' => [['message' => ['role' => 'assistant', 'content' => $text]]]];
}

function mya_reply_tool(string $name, array $args, string $id = 'call_1'): array
{
    return ['choices' => [['message' => [
        'role'       => 'assistant',
        'content'    => null,
        'tool_calls' => [[
            'id'       => $id,
            'type'     => 'function',
            'function' => ['name' => $name, 'arguments' => json_encode($args)],
        ]],
    ]]]];
}

mya_test('ai: a plain answer needs no tools', function () {
    $out = mya_ai_loop(
        [['role' => 'user', 'content' => 'what is a database index?']],
        mya_fake_transport([mya_reply_text('An index speeds up lookups.')])
    );

    mya_assert_same('An index speeds up lookups.', $out['reply'], 'wrong reply');
    mya_assert_same(0, count($out['tools']), 'no tool should have run');
    mya_assert_same(false, $out['stopped_early'], 'should not stop early');
});

mya_test('ai: a tool call runs and its result reaches the next request', function () {
    $seen = [];
    $out  = mya_ai_loop(
        [['role' => 'user', 'content' => 'remember the wifi password is guest123']],
        mya_fake_transport([
            mya_reply_tool('save_memory', ['title' => 'Wifi password', 'body' => 'guest123']),
            mya_reply_text('Saved that.'),
        ], $seen)
    );

    mya_assert_same('Saved that.', $out['reply'], 'wrong final reply');
    mya_assert_same(1, count($out['tools']), 'expected one tool run');
    mya_assert_same('Saved to memory', $out['tools'][0]['summary'], 'wrong summary');
    mya_assert_same(1, count(mya_memory_search('guest123')), 'memory was not stored');

    $last = end($seen)['messages'];
    $tool = end($last);
    mya_assert_same('tool', $tool['role'], 'tool result was not sent back');
    mya_assert(str_contains($tool['content'], 'saved'), 'tool result content missing');
});

mya_test('ai: the loop stops at three rounds', function () {
    $out = mya_ai_loop(
        [['role' => 'user', 'content' => 'loop forever']],
        mya_fake_transport(array_fill(0, 8, mya_reply_tool('search_memory', ['query' => 'x'])))
    );

    mya_assert_same(true, $out['stopped_early'], 'should have stopped early');
    mya_assert_same(3, $out['rounds'], 'cap should be 3 rounds');
    mya_assert(trim($out['reply']) !== '', 'must still say something');
});

mya_test('ai: system prompt is always the first message sent', function () {
    $seen = [];
    mya_ai_loop(
        [['role' => 'user', 'content' => 'hi']],
        mya_fake_transport([mya_reply_text('hello')], $seen)
    );

    mya_assert_same('system', $seen[0]['messages'][0]['role'], 'system message missing');
});

mya_test('ai: empty response falls back to JSON protocol mode', function () {
    $seen = [];
    $out  = mya_ai_loop(
        [['role' => 'user', 'content' => 'what is the wifi password?']],
        mya_fake_transport([
            mya_reply_text(''),
            mya_reply_text('{"tool":"search_memory","arguments":{"query":"wifi password"}}'),
            mya_reply_text('It is guest123.'),
        ], $seen)
    );

    mya_assert_same('It is guest123.', $out['reply'], 'wrong final reply');
    mya_assert_same('search_memory', $out['tools'][0]['name'], 'fallback tool did not run');
    mya_assert(!isset($seen[1]['tools']), 'fallback request must not send the tools key');
});

mya_test('ai: fallback parser handles fenced json and plain text', function () {
    $fenced = mya_ai_parse_json_tool("```json\n{\"tool\":\"save_memory\",\"arguments\":{\"title\":\"t\",\"body\":\"b\"}}\n```");
    mya_assert_same('save_memory', $fenced['tool'], 'fenced json not parsed');

    $reply = mya_ai_parse_json_tool('{"reply":"hello there"}');
    mya_assert_same('hello there', $reply['reply'], 'reply json not parsed');

    mya_assert_same(null, mya_ai_parse_json_tool('just some prose'), 'prose should not parse');
});

mya_test('ai: http transport refuses to run without an api key', function () {
    mya_setting_set('openrouter_key', '');
    try {
        (mya_ai_http_transport())(['model' => 'x', 'messages' => []]);
    } catch (RuntimeException $e) {
        mya_assert(str_contains(strtolower($e->getMessage()), 'api key'), 'error should mention the key');
        return;
    }
    throw new RuntimeException('expected a RuntimeException');
});

mya_test('chat: a turn stores the user and assistant messages', function () {
    $out = mya_chat_turn('conv-1', 'hello there', mya_fake_transport([mya_reply_text('Hi!')]));

    mya_assert_same('Hi!', $out['reply'], 'wrong reply');

    $rows = mya_chat_history('conv-1');
    mya_assert_same(2, count($rows), 'expected two stored messages');
    mya_assert_same('user', $rows[0]['role'], 'first row should be the user message');
    mya_assert_same('Hi!', $rows[1]['content'], 'assistant reply not stored');
});

mya_test('chat: history is scoped to one conversation and ordered oldest first', function () {
    mya_chat_turn('conv-2', 'first', mya_fake_transport([mya_reply_text('one')]));
    mya_chat_turn('conv-2', 'second', mya_fake_transport([mya_reply_text('two')]));

    $rows = mya_chat_history('conv-2');
    mya_assert_same(4, count($rows), 'wrong row count for conv-2');
    mya_assert_same('first', $rows[0]['content'], 'wrong order');
    mya_assert_same('two', $rows[3]['content'], 'wrong order');
});

mya_test('chat: earlier turns are sent back to the model as context', function () {
    $seen = [];
    mya_chat_turn('conv-3', 'my cat is called Momo', mya_fake_transport([mya_reply_text('Noted.')]));
    mya_chat_turn('conv-3', 'what is her name?', mya_fake_transport([mya_reply_text('Momo.')], $seen));

    $contents = array_column($seen[0]['messages'], 'content');
    mya_assert(in_array('my cat is called Momo', $contents, true), 'previous turn not sent');
});

mya_test('chat: an AI outage degrades to a direct keyword search', function () {
    mya_memory_save(['title' => 'Router login', 'body' => 'The router admin page is 192.168.1.1']);

    $out = mya_chat_turn('conv-4', 'what was the router login?', function (array $p) {
        throw new RuntimeException('Could not reach OpenRouter: timeout');
    });

    mya_assert_same(true, $out['degraded'], 'should be marked degraded');
    mya_assert(str_contains($out['reply'], 'Router login'), 'matching memory should be shown');
    mya_assert(str_contains($out['reply'], '192.168.1.1'), 'memory body should be shown');
});

mya_test('chat: an outage with no matching memory still explains itself', function () {
    $out = mya_chat_turn('conv-5', 'zzzz nothing matches zzzz', function (array $p) {
        throw new RuntimeException('Could not reach OpenRouter: timeout');
    });

    mya_assert_same(true, $out['degraded'], 'should be marked degraded');
    mya_assert(str_contains(strtolower($out['reply']), 'openrouter'), 'should name the failure');
});

mya_test('chat: an empty message is rejected', function () {
    try {
        mya_chat_turn('conv-6', '   ', mya_fake_transport([mya_reply_text('x')]));
    } catch (InvalidArgumentException $e) {
        return;
    }
    throw new RuntimeException('expected InvalidArgumentException');
});

mya_test('view: menu has chat, memories, creations, tasks, secrets and settings in order', function () {
    mya_assert_same(['chat', 'memories', 'creations', 'tasks', 'secrets', 'settings'], array_keys(mya_menu()), 'wrong menu');
});

mya_test('view: unknown page falls back to chat', function () {
    $_GET['page'] = 'nonsense';
    mya_assert_same('chat', mya_current_page(), 'should fall back to chat');

    $_GET['page'] = 'settings';
    mya_assert_same('settings', mya_current_page(), 'known page not honoured');

    unset($_GET['page']);
    mya_assert_same('chat', mya_current_page(), 'default should be chat');
});

mya_test('view: escaping neutralises html', function () {
    mya_assert_same('&lt;b&gt;x&lt;/b&gt;', mya_e('<b>x</b>'), 'html not escaped');
    mya_assert_same('', mya_e(null), 'null should render empty');
});

mya_test('chat: the default conversation is main', function () {
    mya_assert_same('main', mya_default_conversation(), 'wrong default conversation');
});

mya_test('chat: recent history includes tool lines, oldest first', function () {
    mya_chat_log('conv-recent', 'user', 'remember this page');
    mya_chat_log('conv-recent', 'assistant', 'Saved.');
    mya_chat_log('conv-recent', 'tool', 'Saved to memory', 'save_memory');

    $rows = mya_chat_recent('conv-recent');
    mya_assert_same(3, count($rows), 'expected three display rows');
    mya_assert_same('remember this page', $rows[0]['content'], 'wrong order');
    mya_assert_same('tool', $rows[2]['role'], 'tool line missing');
    mya_assert_same('save_memory', $rows[2]['tool_name'], 'tool name missing');
});

mya_test('chat: recent history keeps the newest rows when capped', function () {
    for ($i = 1; $i <= 5; $i++) {
        mya_chat_log('conv-cap', 'user', "message $i");
    }

    $rows = mya_chat_recent('conv-cap', 2);
    mya_assert_same(2, count($rows), 'limit not applied');
    mya_assert_same('message 4', $rows[0]['content'], 'should keep the newest two');
    mya_assert_same('message 5', $rows[1]['content'], 'should keep the newest two');
});

mya_test('context: the stored message stays exactly what was typed', function () {
    mya_chat_turn('conv-ctx', 'remember this page', mya_fake_transport([mya_reply_text('Saved.')]), [
        'url'   => 'https://jira.example/browse/TG-4821',
        'title' => 'TG-4821 Login timeout',
    ]);

    $rows = mya_chat_recent('conv-ctx');
    mya_assert_same('remember this page', $rows[0]['content'], 'stored message was altered');
});

mya_test('context: the model receives the current page line', function () {
    $seen = [];
    mya_chat_turn('conv-ctx2', 'remember this page', mya_fake_transport([mya_reply_text('Saved.')], $seen), [
        'url'   => 'https://jira.example/browse/TG-4821',
        'title' => 'TG-4821 Login timeout',
    ]);

    $messages = $seen[0]['messages'];
    $sent     = end($messages)['content'];
    mya_assert(str_contains($sent, 'remember this page'), 'original text missing');
    mya_assert(str_contains($sent, '[Current page: TG-4821 Login timeout'), 'title missing');
    mya_assert(str_contains($sent, 'https://jira.example/browse/TG-4821'), 'url missing');
});

mya_test('context: without a page the message is sent untouched', function () {
    $seen = [];
    mya_chat_turn('conv-ctx3', 'what is 2 + 2?', mya_fake_transport([mya_reply_text('4')], $seen));

    $messages = $seen[0]['messages'];
    $sent     = end($messages)['content'];
    mya_assert_same('what is 2 + 2?', $sent, 'message should be unchanged');
});

mya_test('context: an empty or partial context adds nothing', function () {
    mya_assert_same('', mya_chat_context_line(null), 'null should add nothing');
    mya_assert_same('', mya_chat_context_line(['url' => '', 'title' => '']), 'blank should add nothing');
    mya_assert(
        str_contains(mya_chat_context_line(['url' => 'https://x.test', 'title' => '']), 'https://x.test'),
        'a url with no title should still be sent'
    );
});

mya_test('context: extension options page url and title are captured in context', function () {
    $seen = [];
    mya_chat_turn('conv-ext-ctx', 'remember this page', mya_fake_transport([mya_reply_text('Saved.')], $seen), [
        'url'   => 'chrome-extension://abcdefghijklmnop/options.html',
        'title' => 'Notebook Options',
    ]);

    $sent = end($seen[0]['messages'])['content'];
    mya_assert(str_contains($sent, 'remember this page'), 'original text missing');
    mya_assert(str_contains($sent, '[Current page: Notebook Options — chrome-extension://abcdefghijklmnop/options.html]'), 'extension context missing');
});

mya_test('memory: saving an extension options page url persists in DB', function () {
    $id = mya_memory_save([
        'title' => 'Extension Options Page',
        'body'  => 'Settings page for Chrome extension',
        'url'   => 'chrome-extension://abcdefghijklmnop/options.html',
        'tags'  => ['extension', 'options'],
    ]);

    $saved = mya_memory_get($id);
    mya_assert_same('chrome-extension://abcdefghijklmnop/options.html', $saved['url'], 'extension url not persisted');
});

mya_test('view: embed mode is on only for embed=1', function () {
    unset($_GET['embed']);
    mya_assert_same(false, mya_embed_mode(), 'should be off by default');

    $_GET['embed'] = '1';
    mya_assert_same(true, mya_embed_mode(), 'embed=1 should turn it on');

    $_GET['embed'] = '0';
    mya_assert_same(false, mya_embed_mode(), 'embed=0 should stay off');

    unset($_GET['embed']);
});

function mya_fake_webhook(?array &$seen): callable
{
    return function (string $url, string $json) use (&$seen) {
        $seen = ['url' => $url, 'json' => $json];

        return ['status' => 200, 'body' => '{}', 'error' => ''];
    };
}

mya_test('webhook: plain http is refused', function () {
    $out = mya_tool_run('send_webhook', [
        'url'     => 'http://chat.googleapis.com/v1/spaces/x',
        'message' => 'hello',
    ]);
    mya_assert(isset($out['error']), 'expected an error');
    mya_assert(str_contains(strtolower($out['error']), 'https'), 'error should mention https');
});

mya_test('webhook: private and loopback addresses are refused', function () {
    foreach (['https://127.0.0.1/hook', 'https://192.168.1.9/hook', 'https://localhost/hook'] as $url) {
        $out = mya_tool_run('send_webhook', ['url' => $url, 'message' => 'hello']);
        mya_assert(isset($out['error']), "should refuse $url");
    }
});

mya_test('webhook: posts the google chat payload shape', function () {
    $seen = null;
    $GLOBALS['mya_webhook_transport'] = mya_fake_webhook($seen);

    $out = mya_tool_run('send_webhook', [
        'url'     => 'https://chat.googleapis.com/v1/spaces/AAA/messages?key=k',
        'message' => 'build finished',
    ]);
    unset($GLOBALS['mya_webhook_transport']);

    mya_assert_same('sent', $out['status'], 'unexpected status');
    mya_assert_same('https://chat.googleapis.com/v1/spaces/AAA/messages?key=k', $seen['url'], 'wrong url');
    mya_assert_same(['text' => 'build finished'], json_decode($seen['json'], true), 'wrong payload');
});

mya_test('webhook: a non-2xx reply becomes a readable error', function () {
    $GLOBALS['mya_webhook_transport'] = function (string $url, string $json) {
        return ['status' => 404, 'body' => 'no such space', 'error' => ''];
    };

    $out = mya_tool_run('send_webhook', [
        'url'     => 'https://chat.googleapis.com/v1/spaces/gone',
        'message' => 'hello',
    ]);
    unset($GLOBALS['mya_webhook_transport']);

    mya_assert(isset($out['error']), 'expected an error');
    mya_assert(str_contains($out['error'], '404'), 'error should carry the status code');
});

mya_test('webhook: the summary names the host, never the url', function () {
    $seen = null;
    $GLOBALS['mya_webhook_transport'] = mya_fake_webhook($seen);

    $url = 'https://chat.googleapis.com/v1/spaces/AAA/messages?key=secret-token';
    $out = mya_tool_run('send_webhook', ['url' => $url, 'message' => 'hi']);
    unset($GLOBALS['mya_webhook_transport']);

    $summary = mya_tool_summary('send_webhook', $out);
    mya_assert_same('Sent to chat.googleapis.com', $summary, 'wrong summary');
    mya_assert(!str_contains($summary, 'secret-token'), 'summary must not leak the url');
});

mya_test('webhook: an over-long message is truncated, not rejected', function () {
    $seen = null;
    $GLOBALS['mya_webhook_transport'] = mya_fake_webhook($seen);

    mya_tool_run('send_webhook', [
        'url'     => 'https://chat.googleapis.com/v1/spaces/AAA',
        'message' => str_repeat('x', 5000),
    ]);
    unset($GLOBALS['mya_webhook_transport']);

    $sent = json_decode($seen['json'], true)['text'];
    mya_assert(mb_strlen($sent) <= 4000, 'message should be capped at 4000 characters');
});

mya_test('new chat: the current conversation starts out as main', function () {
    mya_setting_set('current_conversation', '');
    mya_assert_same('main', mya_current_conversation(), 'should fall back to main');
});

mya_test('new chat: starting one switches to a fresh empty conversation', function () {
    mya_setting_set('current_conversation', '');
    mya_chat_log(mya_current_conversation(), 'user', 'old message');

    $fresh = mya_start_new_conversation();
    mya_assert($fresh !== 'main', 'should not reuse main');
    mya_assert_same($fresh, mya_current_conversation(), 'the switch was not persisted');
    mya_assert_same(0, count(mya_chat_recent($fresh)), 'the new conversation should be empty');
});

mya_test('new chat: the old conversation is kept, not deleted', function () {
    mya_setting_set('current_conversation', '');
    mya_chat_log(mya_current_conversation(), 'user', 'keep me');

    mya_start_new_conversation();
    $old = mya_chat_recent('main');

    mya_assert(count($old) > 0, 'old messages were destroyed');
    mya_assert(
        in_array('keep me', array_column($old, 'content'), true),
        'the old message should still be in the database'
    );
});

mya_test('new chat: two new chats in a row get different ids', function () {
    $a = mya_start_new_conversation();
    $b = mya_start_new_conversation();
    mya_assert($a !== $b, 'ids collided');
});

mya_test('progress: a step round-trips and can be cleared', function () {
    mya_progress_set('turn-abc123', 'Searching memories');

    $p = mya_progress_get('turn-abc123');
    mya_assert_same('Searching memories', $p['step'], 'step not stored');
    mya_assert(isset($p['at']), 'timestamp missing');

    mya_progress_clear('turn-abc123');
    mya_assert_same(null, mya_progress_get('turn-abc123'), 'clear did not remove it');
});

mya_test('progress: an unknown turn reads as null', function () {
    mya_assert_same(null, mya_progress_get('turn-never-written'), 'should be null');
});

mya_test('progress: unsafe turn ids are refused, not written to disk', function () {
    foreach (['../../etc/passwd', 'a/b', 'a b', str_repeat('x', 100), ''] as $bad) {
        mya_progress_set($bad, 'nope');
        mya_assert_same(null, mya_progress_get($bad), "should refuse id: $bad");
    }
});

mya_test('progress: the loop reports thinking, then the tool it is running', function () {
    $steps = [];
    mya_ai_loop(
        [['role' => 'user', 'content' => 'remember the door code is 4821']],
        mya_fake_transport([
            mya_reply_tool('save_memory', ['title' => 'Door code', 'body' => '4821']),
            mya_reply_text('Saved.'),
        ]),
        3,
        function (string $step) use (&$steps) { $steps[] = $step; }
    );

    mya_assert_same('Thinking', $steps[0], 'first step should be thinking');
    mya_assert(in_array('Saving to memory', $steps, true), 'tool step missing: ' . implode(' | ', $steps));
    mya_assert_same('Writing the reply', end($steps), 'last step should be writing the reply');
});

mya_test('progress: every tool has a readable step label', function () {
    mya_assert_same('Searching memories', mya_tool_step_label('search_memory'), 'search label');
    mya_assert_same('Saving to memory', mya_tool_step_label('save_memory'), 'save label');
    mya_assert_same('Updating a memory', mya_tool_step_label('update_memory'), 'update label');
    mya_assert_same('Checking what to delete', mya_tool_step_label('delete_memory'), 'delete label');
    mya_assert_same('Sending the message', mya_tool_step_label('send_webhook'), 'webhook label');
    mya_assert_same('Working', mya_tool_step_label('something_else'), 'fallback label');
});

mya_test('access: the real client ip comes from X-Real-IP, not REMOTE_ADDR', function () {
    // nginx proxies to Apache, so REMOTE_ADDR is always 127.0.0.1 and proves nothing.
    $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
    $_SERVER['HTTP_X_REAL_IP'] = '192.168.88.24';
    mya_assert_same('192.168.88.24', mya_client_ip(), 'should trust X-Real-IP');

    unset($_SERVER['HTTP_X_REAL_IP']);
    mya_assert_same('127.0.0.1', mya_client_ip(), 'should fall back to REMOTE_ADDR');
});

mya_test('access: only this machine counts as local', function () {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    foreach (['127.0.0.1', '::1'] as $ip) {
        $_SERVER['HTTP_X_REAL_IP'] = $ip;
        mya_assert(mya_is_local_request(), "$ip should be local");
    }

    foreach (['192.168.88.24', '10.0.0.5', '203.0.113.9'] as $ip) {
        $_SERVER['HTTP_X_REAL_IP'] = $ip;
        mya_assert(!mya_is_local_request(), "$ip must not be local");
    }

    // No proxy header at all: Apache was reached directly, so REMOTE_ADDR decides.
    unset($_SERVER['HTTP_X_REAL_IP']);
    $_SERVER['REMOTE_ADDR'] = '192.168.88.24';
    mya_assert(!mya_is_local_request(), 'a direct LAN request must not be local');

    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    mya_assert(mya_is_local_request(), 'a direct local request should be local');
});

mya_test('secrets: saving stores the value and lists the name', function () {
    mya_secret_save('staging-db', 'hunter2-actual-password', 'vault item TG-Staging');

    $rows = mya_secret_list();
    $names = array_column($rows, 'name');
    mya_assert(in_array('staging-db', $names, true), 'name not listed');
    mya_assert_same('hunter2-actual-password', mya_secret_get('staging-db')['value'], 'value not stored');
});

mya_test('secrets: saving the same name updates instead of duplicating', function () {
    mya_secret_save('staging-db', 'new-password', 'moved');

    $count = 0;
    foreach (mya_secret_list() as $row) {
        if ($row['name'] === 'staging-db') { $count++; }
    }
    mya_assert_same(1, $count, 'duplicate row created');
    mya_assert_same('new-password', mya_secret_get('staging-db')['value'], 'value not updated');
});

mya_test('secrets: an empty name or value is refused', function () {
    foreach ([['', 'v'], ['n', '']] as $pair) {
        try {
            mya_secret_save($pair[0], $pair[1], '');
            throw new RuntimeException('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            // expected
        }
    }
});

mya_test('secrets: delete removes it', function () {
    mya_secret_save('throwaway', 'x', '');
    mya_assert(mya_secret_delete('throwaway'), 'delete returned false');
    mya_assert_same(null, mya_secret_get('throwaway'), 'still present');
});

mya_test('secrets: THE TOOL NEVER RETURNS A VALUE', function () {
    mya_secret_save('api-token', 'sk-super-secret-value', 'for the billing api');

    $out  = mya_tool_run('list_secrets', []);
    $json = json_encode($out);

    mya_assert(str_contains($json, 'api-token'), 'the name should be visible');
    mya_assert(str_contains($json, 'for the billing api'), 'the note should be visible');
    mya_assert(!str_contains($json, 'sk-super-secret-value'), 'THE VALUE LEAKED TO THE MODEL');

    foreach ($out['secrets'] as $row) {
        mya_assert_same(['name', 'note'], array_keys($row), 'a secret row exposed extra fields');
    }
});

mya_test('secrets: there is no tool that can save or read a secret', function () {
    $names = array_map(fn($t) => $t['function']['name'], mya_tool_definitions());

    foreach (['save_secret', 'get_secret', 'read_secret', 'reveal_secret'] as $forbidden) {
        mya_assert(!in_array($forbidden, $names, true), "$forbidden must not exist");
    }
});

mya_test('secrets: the assistant is told it cannot read values', function () {
    $p = mya_base_prompt();
    mya_assert(str_contains($p, 'list_secrets'), 'prompt should mention list_secrets');
    mya_assert(
        stripos($p, 'never') !== false && stripos($p, 'value') !== false,
        'prompt should state it never sees values'
    );
});

mya_test('webhook by secret: posts to the url stored in the named secret', function () {
    mya_secret_save('google chat URL', 'https://chat.googleapis.com/v1/spaces/ABC/messages?key=tok', 'use for chat');

    $seen = null;
    $GLOBALS['mya_webhook_transport'] = mya_fake_webhook($seen);

    $out = mya_tool_run('send_webhook', ['secret' => 'google chat URL', 'message' => 'hii']);
    unset($GLOBALS['mya_webhook_transport']);

    mya_assert_same('sent', $out['status'], 'should have sent');
    mya_assert_same(
        'https://chat.googleapis.com/v1/spaces/ABC/messages?key=tok',
        $seen['url'],
        'did not post to the secret url'
    );
    mya_assert_same(['text' => 'hii'], json_decode($seen['json'], true), 'wrong payload');
});

mya_test('webhook by secret: the result never carries the url or its token', function () {
    $seen = null;
    $GLOBALS['mya_webhook_transport'] = mya_fake_webhook($seen);

    $out = mya_tool_run('send_webhook', ['secret' => 'google chat URL', 'message' => 'hii']);
    unset($GLOBALS['mya_webhook_transport']);

    $json = json_encode($out);
    mya_assert(!str_contains($json, 'key=tok'), 'THE TOKEN LEAKED BACK TO THE MODEL');
    mya_assert(!str_contains($json, '/v1/spaces/ABC'), 'the url path leaked back to the model');
    mya_assert(str_contains($json, 'chat.googleapis.com'), 'the host should still be reported');
});

mya_test('webhook by secret: an unknown name errors and posts nothing', function () {
    $seen = null;
    $GLOBALS['mya_webhook_transport'] = mya_fake_webhook($seen);

    $out = mya_tool_run('send_webhook', ['secret' => 'no such secret', 'message' => 'hii']);
    unset($GLOBALS['mya_webhook_transport']);

    mya_assert(isset($out['error']), 'expected an error');
    mya_assert_same(null, $seen, 'nothing should have been posted');
});

mya_test('webhook by secret: a secret that is not an https url is refused', function () {
    mya_secret_save('not-a-webhook', 'just-a-password', '');

    $seen = null;
    $GLOBALS['mya_webhook_transport'] = mya_fake_webhook($seen);

    $out = mya_tool_run('send_webhook', ['secret' => 'not-a-webhook', 'message' => 'hii']);
    unset($GLOBALS['mya_webhook_transport']);

    mya_assert(isset($out['error']), 'expected an error');
    mya_assert_same(null, $seen, 'nothing should have been posted');
});

mya_test('webhook: the assistant is told to use secrets by name, never to ask for one', function () {
    $p = mya_base_prompt();
    mya_assert(stripos($p, 'by name') !== false, 'prompt should explain using a secret by name');
    mya_assert(
        stripos($p, 'never ask') !== false || stripos($p, 'never request') !== false,
        'prompt should forbid asking for a secret value'
    );
});

mya_test('reminders: one due now is returned, one due later is not', function () {
    mya_setting_set('notify_channel', 'both');

    $past   = mya_reminder_add('client meeting', date('Y-m-d H:i:s', time() - 60));
    $future = mya_reminder_add('dentist', date('Y-m-d H:i:s', time() + 3600));

    $ids = array_column(mya_reminder_due('chrome'), 'id');
    mya_assert(in_array($past, $ids, true), 'a due reminder was not returned');
    mya_assert(!in_array($future, $ids, true), 'a future reminder must not fire yet');
});

mya_test('reminders: marking one channel does not consume the other', function () {
    mya_setting_set('notify_channel', 'both');
    $id = mya_reminder_add('standup', date('Y-m-d H:i:s', time() - 5));

    mya_reminder_mark($id, 'chrome');

    mya_assert(
        !in_array($id, array_column(mya_reminder_due('chrome'), 'id'), true),
        'chrome should not see it twice'
    );
    mya_assert(
        in_array($id, array_column(mya_reminder_due('macos'), 'id'), true),
        'macos should still get it when both channels are on'
    );

    mya_reminder_mark($id, 'macos');
    mya_assert(
        !in_array($id, array_column(mya_reminder_due('macos'), 'id'), true),
        'macos should not see it twice either'
    );
});

mya_test('reminders: the settings choice decides which channels fire', function () {
    mya_setting_set('notify_channel', 'chrome');
    mya_assert(mya_channel_enabled('chrome'), 'chrome should be on');
    mya_assert(!mya_channel_enabled('macos'), 'macos should be off');

    mya_setting_set('notify_channel', 'macos');
    mya_assert(!mya_channel_enabled('chrome'), 'chrome should be off');
    mya_assert(mya_channel_enabled('macos'), 'macos should be on');

    mya_setting_set('notify_channel', 'both');
    mya_assert(mya_channel_enabled('chrome') && mya_channel_enabled('macos'), 'both should be on');
});

mya_test('reminders: a disabled channel is handed nothing to fire', function () {
    mya_setting_set('notify_channel', 'macos');
    mya_reminder_add('ignored by chrome', date('Y-m-d H:i:s', time() - 5));

    mya_assert_same(0, count(mya_reminder_due('chrome')), 'chrome is off and must get nothing');
    mya_assert(count(mya_reminder_due('macos')) > 0, 'macos is on and should get it');

    mya_setting_set('notify_channel', 'both');
});

mya_test('reminders: the tool turns "in 30 minutes" into a due time', function () {
    $out = mya_tool_run('set_reminder', [
        'text'              => 'client meeting to attend',
        'minutes_from_now'  => 30,
    ]);

    mya_assert_same('set', $out['status'], 'unexpected status');

    $due = strtotime($out['due_at']);
    $gap = $due - time();
    mya_assert($gap > 29 * 60 && $gap <= 30 * 60 + 5, "due time is off: {$out['due_at']}");
});

mya_test('reminders: the tool accepts an explicit clock time', function () {
    $at  = date('Y-m-d H:i:s', time() + 7200);
    $out = mya_tool_run('set_reminder', ['text' => 'call back', 'at' => $at]);

    mya_assert_same('set', $out['status'], 'unexpected status');
    mya_assert_same($at, $out['due_at'], 'due time not honoured');
});

mya_test('reminders: a past time or empty text is refused', function () {
    $past = mya_tool_run('set_reminder', ['text' => 'too late', 'minutes_from_now' => -5]);
    mya_assert(isset($past['error']), 'a past reminder should error');

    $empty = mya_tool_run('set_reminder', ['text' => '  ', 'minutes_from_now' => 10]);
    mya_assert(isset($empty['error']), 'an empty reminder should error');
});

mya_test('reminders: the assistant knows what time it is now', function () {
    $p = mya_system_prompt();
    mya_assert(str_contains($p, date('Y-m-d')), 'prompt must carry today\'s date');
    mya_assert(stripos($p, 'set_reminder') !== false, 'prompt should mention set_reminder');
});

mya_test('tasks: schema table and index exist', function () {
    $tables = mya_db()->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    mya_assert(in_array('tasks', $tables, true), 'tasks table missing');
});

mya_test('tasks: add creates task with default priority and status', function () {
    $id = mya_task_add([
        'title'   => 'Fix CORS in Api.php',
        'project' => 'api',
    ]);
    mya_assert($id > 0, 'expected positive task id');

    $task = mya_task_get($id);
    mya_assert_same('Fix CORS in Api.php', $task['title'], 'task title match');
    mya_assert_same('pending', $task['status'], 'default status should be pending');
    mya_assert_same('normal', $task['priority'], 'default priority should be normal');
    mya_assert_same('api', $task['project'], 'project match');
    mya_assert($task['due_date'] === null, 'due_date should be null');
    mya_assert($task['completed_at'] === null, 'completed_at should be null');
});

mya_test('tasks: add rejects empty title', function () {
    try {
        mya_task_add(['title' => '   ']);
    } catch (InvalidArgumentException $e) {
        return;
    }
    throw new RuntimeException('expected InvalidArgumentException for empty title');
});

mya_test('tasks: list filters and orders high priority first', function () {
    $lowId = mya_task_add(['title' => 'Low task', 'priority' => 'low']);
    $highId = mya_task_add(['title' => 'High task', 'priority' => 'high']);

    $list = mya_task_list(['status' => 'pending']);
    mya_assert(count($list) >= 2, 'expected at least 2 pending tasks');
    mya_assert_same('high', $list[0]['priority'], 'high priority task should be first');

    $highOnly = mya_task_list(['status' => 'pending', 'priority' => 'high']);
    mya_assert(count($highOnly) === 1, 'expected 1 high priority task');
    mya_assert_same($highId, (int) $highOnly[0]['id'], 'wrong high task returned');
});

mya_test('tasks: update modifies task fields', function () {
    $id = mya_task_add(['title' => 'Initial title']);
    $updated = mya_task_update($id, [
        'title'    => 'Updated title',
        'priority' => 'high',
        'project'  => 'frontend',
        'due_date' => '2026-09-01',
    ]);

    mya_assert_same('Updated title', $updated['title'], 'updated title match');
    mya_assert_same('high', $updated['priority'], 'updated priority match');
    mya_assert_same('frontend', $updated['project'], 'updated project match');
    mya_assert_same('2026-09-01', $updated['due_date'], 'updated due_date match');
});

mya_test('tasks: complete and reopen cycle', function () {
    $id = mya_task_add(['title' => 'Cycle task']);
    
    mya_assert(mya_task_complete($id), 'complete should return true');
    $task = mya_task_get($id);
    mya_assert_same('completed', $task['status'], 'status should be completed');
    mya_assert(!empty($task['completed_at']), 'completed_at should be populated');

    mya_assert(mya_task_reopen($id), 'reopen should return true');
    $task = mya_task_get($id);
    mya_assert_same('pending', $task['status'], 'status should be pending');
    mya_assert($task['completed_at'] === null, 'completed_at should be cleared');
});

mya_test('tasks: delete removes task', function () {
    $id = mya_task_add(['title' => 'To be deleted']);
    mya_assert(mya_task_delete($id), 'delete should return true');
    mya_assert(mya_task_get($id) === null, 'task should not exist after deletion');
});

mya_test('tasks: counts returns accurate aggregates', function () {
    $counts = mya_task_counts();
    mya_assert(isset($counts['pending'], $counts['high_priority'], $counts['completed'], $counts['total']), 'counts shape');
    mya_assert($counts['total'] >= $counts['pending'] + $counts['completed'], 'counts math');
});

mya_test('tasks: tools add, list, complete, delete via tool loop', function () {
    // 1. Add task tool
    $addRes = mya_tool_run('add_task', [
        'title'    => 'Refactor auth controller',
        'priority' => 'high',
        'project'  => 'api',
    ]);
    mya_assert_same('added', $addRes['status'], 'add_task status');
    $taskId = (int) $addRes['id'];

    // 2. List tasks tool
    $listRes = mya_tool_run('list_tasks', ['status' => 'pending', 'priority' => 'high']);
    mya_assert_same('ok', $listRes['status'], 'list_tasks status');
    mya_assert($listRes['count'] >= 1, 'list_tasks count');

    // 3. Complete task tool
    $compRes = mya_tool_run('complete_task', ['id' => $taskId]);
    mya_assert_same('completed', $compRes['status'], 'complete_task status');

    // 4. Delete task tool
    $delRes = mya_tool_run('delete_task', ['id' => $taskId]);
    mya_assert_same('deleted', $delRes['status'], 'delete_task status');
});

mya_test('tasks: view menu includes tasks', function () {
    $menu = mya_menu();
    mya_assert(isset($menu['tasks']), 'menu missing tasks');
    mya_assert_same('Tasks', $menu['tasks']['label'], 'menu label');
});

echo "\n{$GLOBALS['mya_pass']} passed, {$GLOBALS['mya_fail']} failed\n";
@unlink(MYA_DB_PATH);
exit($GLOBALS['mya_fail'] > 0 ? 1 : 0);
