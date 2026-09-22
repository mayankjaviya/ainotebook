<?php
require_once __DIR__ . '/../lib/ai.php';
require_once __DIR__ . '/../lib/reminders.php';

$notice     = '';
$model      = mya_setting_get('model', mya_default_model());
$modelsList = mya_models_list();
$key        = trim((string) mya_setting_get('openrouter_key', ''));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $newKey = trim((string) ($_POST['openrouter_key'] ?? ''));
        if ($newKey !== '') {
            mya_setting_set('openrouter_key', $newKey);
            $key = $newKey;
        }

        // Delete model action
        $deleteModel = trim((string) ($_POST['delete_model'] ?? ''));
        if ($deleteModel !== '') {
            $modelsList = array_values(array_filter($modelsList, static fn($m) => $m !== $deleteModel));
            if ($modelsList === []) {
                $modelsList = [mya_default_model()];
            }
            mya_models_save($modelsList);
            if ($model === $deleteModel) {
                $model = $modelsList[0];
                mya_setting_set('model', $model);
            }
            $notice = 'Model removed.';
        }

        // Add model action
        $addModel = trim((string) ($_POST['add_model'] ?? ''));
        if ($addModel !== '') {
            if (!in_array($addModel, $modelsList, true)) {
                $modelsList[] = $addModel;
                mya_models_save($modelsList);
            }
            mya_setting_set('model', $addModel);
            $model  = $addModel;
            $notice = 'Model "' . mya_e($addModel) . '" added and set as active.';
        } elseif ($deleteModel === '') {
            // Selected active model
            $selectedModel = trim((string) ($_POST['selected_model'] ?? ''));
            if ($selectedModel !== '' && in_array($selectedModel, $modelsList, true)) {
                mya_setting_set('model', $selectedModel);
                $model = $selectedModel;
            }
            $notice = 'Settings saved.';
        }

        mya_setting_set('system_prompt', trim((string) ($_POST['system_prompt'] ?? '')));

        $channel = (string) ($_POST['notify_channel'] ?? 'chrome');
        mya_setting_set(
            'notify_channel',
            in_array($channel, ['chrome', 'macos', 'both'], true) ? $channel : 'chrome'
        );

        mya_setting_set('enable_web_search', isset($_POST['enable_web_search']) ? '1' : '0');

        $reasoningMap = ['0' => 'default', '1' => 'low', '2' => 'medium', '3' => 'high'];
        $reasoning    = (string) ($_POST['reasoning_effort'] ?? '');
        if (!in_array($reasoning, ['default', 'low', 'medium', 'high'], true)) {
            $num       = (string) ($_POST['reasoning_effort_num'] ?? '0');
            $reasoning = $reasoningMap[$num] ?? 'default';
        }
        mya_setting_set('reasoning_effort', $reasoning);

        mya_setting_set('allow_fallbacks', isset($_POST['allow_fallbacks']) ? '1' : '0');
    } elseif ($action === 'test') {
        try {
            $payload = [
                'model'      => $model,
                'messages'   => [['role' => 'user', 'content' => 'Reply with the single word: ready']],
                'max_tokens' => 10,
            ];
            mya_ai_apply_capabilities($payload);
            $response = (mya_ai_http_transport())($payload);
            $text     = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
            $notice   = $text === ''
                ? 'Connected, but the model returned nothing. Try another model.'
                : 'Connected. The model replied: ' . $text;
        } catch (Throwable $e) {
            $notice = 'Failed: ' . $e->getMessage();
        }
    }
}

$masked            = $key === '' ? '' : substr($key, 0, 8) . str_repeat('•', 12) . substr($key, -4);
$prompt            = (string) mya_setting_get('system_prompt', '');
$webSearch         = mya_setting_get('enable_web_search', '0') === '1';
$reasoning         = mya_setting_get('reasoning_effort', 'default');
$reasoningMap      = ['default' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];
$reasoningVal      = $reasoningMap[$reasoning] ?? 0;
$reasoningLabelMap = [
    0 => 'Default (Model decides)',
    1 => 'Low effort',
    2 => 'Medium effort',
    3 => 'High effort',
];
$allowFallbacks = mya_setting_get('allow_fallbacks', '1') === '1';
$modelsList     = mya_models_list();
?>
<div class="page">
    <h1>Settings</h1>

    <?php if ($notice !== ''): ?>
        <div class="card notice-card"><?= mya_e($notice) ?></div>
    <?php endif; ?>

    <form method="post" class="card settings-card" autocomplete="off">
        <input type="hidden" name="action" value="save">

        <!-- Section: API Key -->
        <div class="form-section">
            <label class="set-label" for="k">OpenRouter API Key</label>
            <input class="input mem-field" id="k" type="password" name="openrouter_key"
                   placeholder="<?= $masked === '' ? 'sk-or-v1-…' : mya_e($masked) ?>"
                   autocomplete="new-password" autocorrect="off" autocapitalize="off" spellcheck="false">
            <p class="muted">
                <?= $key === '' ? 'No key saved yet.' : 'A key is currently saved. Leave blank to keep existing key.' ?>
                Get your key at <a href="https://openrouter.ai/keys" target="_blank" rel="noopener">openrouter.ai/keys</a>.
            </p>
        </div>

        <!-- Section: Available Models -->
        <div class="form-section">
            <label class="set-label">Available Models &amp; Active Selection</label>
            <p class="muted" style="margin-top:-2px; margin-bottom:12px;">
                Choose which model to use for chat conversations, or add custom model IDs from OpenRouter.ai.
            </p>

            <div class="model-list">
                <?php foreach ($modelsList as $m): ?>
                    <div class="model-item <?= $m === $model ? 'is-active' : '' ?>">
                        <label class="model-radio-label">
                            <input type="radio" name="selected_model" value="<?= mya_e($m) ?>" <?= $m === $model ? 'checked' : '' ?>>
                            <span class="model-name"><?= mya_e($m) ?></span>
                        </label>
                        <div class="model-item-actions">
                            <?php if ($m === mya_default_model()): ?>
                                <span class="badge badge-default">Default</span>
                            <?php endif; ?>
                            <?php if ($m === $model): ?>
                                <span class="badge badge-active">Active</span>
                            <?php endif; ?>
                            <?php if (count($modelsList) > 1): ?>
                                <button type="submit" name="delete_model" value="<?= mya_e($m) ?>"
                                        class="btn-icon-delete" title="Remove model from saved list"
                                        onclick="return confirm('Remove <?= mya_e($m) ?> from your saved models?');">
                                    &#215;
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="add-model-box">
                <label class="set-label-sub" for="add_m_input">Add a New Model ID</label>
                <div class="add-model-row">
                    <input class="input mem-field" id="add_m_input" name="add_model"
                           placeholder="e.g. openai/gpt-4o-mini, anthropic/claude-3.5-sonnet"
                           autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
                    <button type="submit" class="btn btn-secondary btn-add-model">+ Add Model</button>
                </div>
            </div>
        </div>

        <!-- Section: Model Capabilities -->
        <div class="form-section">
            <label class="set-label">Model Capabilities (OpenRouter.ai)</label>
            <div class="checkbox-stack">
                <label class="set-checkbox-card <?= $webSearch ? 'is-enabled' : '' ?>">
                    <input type="checkbox" name="enable_web_search" value="1" <?= $webSearch ? 'checked' : '' ?>>
                    <div class="checkbox-body">
                        <div class="checkbox-header">
                            <span class="checkbox-title">🌐 Web Search Plugin</span>
                            <span class="badge <?= $webSearch ? 'badge-active' : 'badge-default' ?>"><?= $webSearch ? 'Enabled' : 'Disabled' ?></span>
                        </div>
                        <span class="checkbox-desc">Allow OpenRouter to execute live web searches for real-time web results and current information (<code>plugins: [{"id":"web"}]</code>)</span>
                    </div>
                </label>
                <label class="set-checkbox-card">
                    <input type="checkbox" name="allow_fallbacks" value="1" <?= $allowFallbacks ? 'checked' : '' ?>>
                    <div class="checkbox-body">
                        <span class="checkbox-title">Provider Fallbacks</span>
                        <span class="checkbox-desc">Allow OpenRouter to automatically route requests to secondary providers if the primary host is down</span>
                    </div>
                </label>
            </div>
        </div>

        <!-- Section: Reasoning Effort Slider -->
        <div class="form-section">
            <div class="range-slider-container">
                <div class="range-slider-header">
                    <label class="set-label" style="margin:0;">Reasoning Effort</label>
                    <span id="re-val-label" class="badge badge-active"><?= mya_e($reasoningLabelMap[$reasoningVal]) ?></span>
                </div>
                <div class="range-slider-wrapper">
                    <input type="range" min="0" max="3" step="1" value="<?= $reasoningVal ?>"
                           id="re-slider" name="reasoning_effort_num" class="range-slider"
                           oninput="myaUpdateReasoning(this.value)">
                    <input type="hidden" id="re-input" name="reasoning_effort" value="<?= mya_e($reasoning) ?>">
                </div>
                <div class="range-ticks">
                    <span>Default</span>
                    <span>Low</span>
                    <span>Medium</span>
                    <span>High</span>
                </div>
                <p class="muted" style="margin-top:8px;">
                    Applies to reasoning-capable models (e.g. DeepSeek R1, OpenAI o1/o3-mini, Gemini Flash Thinking).
                </p>
            </div>
        </div>

        <!-- Section: Custom Instructions -->
        <div class="form-section">
            <label class="set-label" for="p">Custom Instructions (Optional)</label>
            <textarea class="textarea mem-field" id="p" name="system_prompt" rows="4"
                      placeholder="e.g. My Google Chat webhook is https://chat.googleapis.com/…"><?= mya_e($prompt) ?></textarea>
            <p class="muted">Added after the built-in instructions, never replacing them &mdash; the assistant retains memory capabilities.</p>
        </div>

        <!-- Section: Reminder Notifications -->
        <div class="form-section">
            <label class="set-label">Reminder Notifications Channel</label>
            <?php $channel = mya_notify_channel(); ?>
            <div class="radio-stack">
                <label class="set-radio-card">
                    <input type="radio" name="notify_channel" value="chrome" <?= $channel === 'chrome' ? 'checked' : '' ?>>
                    <span><strong>Chrome notification</strong> &mdash; Requires the extension installed and Chrome running</span>
                </label>
                <label class="set-radio-card">
                    <input type="radio" name="notify_channel" value="macos" <?= $channel === 'macos' ? 'checked' : '' ?>>
                    <span><strong>macOS notification</strong> &mdash; Works with Chrome closed, requires background cron job</span>
                </label>
                <label class="set-radio-card">
                    <input type="radio" name="notify_channel" value="both" <?= $channel === 'both' ? 'checked' : '' ?>>
                    <span><strong>Both channels</strong></span>
                </label>
            </div>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary btn-save" type="submit">Save Settings</button>
        </div>
    </form>

    <form method="post" class="card test-card">
        <input type="hidden" name="action" value="test">
        <div class="test-row">
            <button class="btn btn-secondary" type="submit">Test Connection</button>
            <span class="muted">Sends one small API test request to verify key &amp; active model.</span>
        </div>
    </form>
</div>

<script>
function myaUpdateReasoning(val) {
    var labels = ['Default (Model decides)', 'Low effort', 'Medium effort', 'High effort'];
    var keys   = ['default', 'low', 'medium', 'high'];
    var num    = parseInt(val, 10) || 0;
    document.getElementById('re-val-label').textContent = labels[num] || labels[0];
    document.getElementById('re-input').value = keys[num] || keys[0];
}
</script>

