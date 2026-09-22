<?php
require_once __DIR__ . '/../lib/chat.php';
require_once __DIR__ . '/../lib/view.php';
require_once __DIR__ . '/../lib/settings.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'new_chat') {
    mya_start_new_conversation();
}

$conversation = mya_current_conversation();
$history      = mya_chat_recent($conversation);
$webSearch    = mya_setting_get('enable_web_search', '0') === '1';

$class = [
    'user'      => 'msg-user',
    'assistant' => 'msg-assistant',
    'tool'      => 'msg-tool',
];
?>
<div class="page chat-page" id="chat-container">
    <div class="chat-main-section">
        <div class="chat-head">
            <button type="button" id="web-search-toggle" class="btn btn-quiet web-search-toggle <?= $webSearch ? 'is-active' : '' ?>" title="Click to toggle OpenRouter Web Search ON/OFF">
                <span class="web-search-icon">🌐</span>
                <span id="web-search-status" class="web-search-status">Web Search: <?= $webSearch ? 'ON' : 'OFF' ?></span>
            </button>
            <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="new_chat">
                <button class="btn btn-quiet chat-new" type="submit"
                        <?= $history === [] ? 'disabled' : '' ?>>New chat</button>
            </form>
        </div>

        <div id="chat-log" class="chat-log" data-conversation="<?= mya_e($conversation) ?>">
            <?php if ($history === []): ?>
                <div class="chat-empty">What should I remember, or what would you like to know?</div>
            <?php endif; ?>

            <?php foreach ($history as $row): ?>
                <div class="msg <?= $class[$row['role']] ?? 'msg-assistant' ?>"><?= str_contains($row['content'], '![') || $row['role'] === 'assistant' ? mya_markdown($row['content']) : mya_e($row['content']) ?></div>
            <?php endforeach; ?>
        </div>

        <form id="chat-form" class="composer" autocomplete="off">
            <div id="image-preview-bar" class="image-preview-bar hidden"></div>
            <div class="composer-controls">
                <button type="button" id="chat-attach-btn" class="btn btn-quiet composer-attach" title="Attach Image">&#128206;</button>
                <input type="file" id="chat-image-input" accept="image/*" multiple hidden>
                <textarea id="chat-input" class="textarea composer-input" rows="1"
                          placeholder="Ask anything, attach an image, or tell me to remember something…"></textarea>
                <button type="submit" class="btn btn-primary composer-send" aria-label="Send">&#8593;</button>
            </div>
        </form>
    </div>

    <!-- Artifact Side Drawer -->
    <aside id="artifact-drawer" class="artifact-drawer hidden">
        <div class="artifact-toolbar">
            <div class="artifact-meta">
                <span class="artifact-toolbar-icon">&#127912;</span>
                <span id="artifact-drawer-title" class="artifact-drawer-title">Artifact Preview</span>
            </div>
            <div class="artifact-actions">
                <div class="artifact-pills">
                    <button type="button" id="art-tab-preview" class="art-pill active" onclick="myaSwitchArtifactTab('preview')">Preview</button>
                    <button type="button" id="art-tab-code" class="art-pill" onclick="myaSwitchArtifactTab('code')">Code</button>
                </div>
                <button type="button" class="btn-art-action" onclick="myaCopyArtifactCode()" title="Copy Code">&#128203; Copy</button>
                <button type="button" class="btn-art-action" onclick="myaDownloadArtifact()" title="Download HTML">&#128190; Download</button>
                <button type="button" class="btn-art-close" onclick="myaCloseArtifact()" title="Close Drawer">&#215;</button>
            </div>
        </div>
        <div class="artifact-body">
            <div id="artifact-view-preview" class="artifact-pane active">
                <iframe id="artifact-iframe" class="artifact-iframe" sandbox="allow-scripts"></iframe>
            </div>
            <div id="artifact-view-code" class="artifact-pane">
                <pre class="artifact-code-wrapper"><code id="artifact-code-display"></code></pre>
            </div>
        </div>
    </aside>
</div>
<script src="assets/app.js?v=24"></script>
