<?php

require_once __DIR__ . '/lib/access.php';
require_once __DIR__ . '/lib/view.php';

mya_require_local();
require_once __DIR__ . '/lib/memory.php';

$page   = mya_current_page();
$embed  = mya_embed_mode();
$menu   = mya_menu();
$hasKey = trim((string) mya_setting_get('openrouter_key', '')) !== '';
$recent = mya_memory_list('', 5);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Notebook</title>
<link rel="stylesheet" href="assets/app.css?v=20">
</head>
<body class="<?= $embed ? 'is-embed' : '' ?>">
<div class="shell">
    <?php if (!$embed): ?>
    <aside class="sidebar">
        <div class="brand">Notebook</div>

        <nav class="menu">
            <?php foreach ($menu as $key => $item): ?>
                <a class="menu-item <?= $key === $page ? 'is-active' : '' ?>"
                   href="?page=<?= mya_e($key) ?>">
                    <span class="menu-icon"><?= $item['icon'] ?></span>
                    <span><?= mya_e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($recent !== []): ?>
            <div class="side-section">
                <div class="side-title">Recent</div>
                <?php foreach ($recent as $row): ?>
                    <a class="side-link" href="?page=memories&q=<?= urlencode($row['title']) ?>">
                        <?= mya_e($row['title']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </aside>
    <?php endif; ?>

    <main class="main">
        <?php if (!$hasKey && $page !== 'settings'): ?>
            <div class="banner">
                No OpenRouter API key yet.
                <a href="?page=settings">Add it in Settings</a> to start chatting.
            </div>
        <?php endif; ?>

        <?php require __DIR__ . '/pages/' . $page . '.php'; ?>
    </main>
</div>

<!-- Global Artifact Preview Modal -->
<div id="artifact-modal" class="artifact-modal-overlay hidden">
    <div class="artifact-modal-content">
        <div class="artifact-toolbar">
            <div class="artifact-meta">
                <span class="artifact-toolbar-icon">&#127912;</span>
                <span id="artifact-modal-title" class="artifact-drawer-title">Artifact Preview</span>
            </div>
            <div class="artifact-actions">
                <div class="artifact-pills">
                    <button type="button" id="art-modal-tab-preview" class="art-pill active" onclick="myaSwitchModalTab('preview')">Preview</button>
                    <button type="button" id="art-modal-tab-code" class="art-pill" onclick="myaSwitchModalTab('code')">Code</button>
                </div>
                <button type="button" class="btn-art-action" onclick="myaCopyArtifactCode()" title="Copy Code">&#128203; Copy</button>
                <button type="button" class="btn-art-action" onclick="myaDownloadArtifact()" title="Download HTML">&#128190; Download</button>
                <button type="button" class="btn-art-close" onclick="myaCloseArtifactModal()" title="Close">&#215;</button>
            </div>
        </div>
        <div class="artifact-body">
            <div id="artifact-modal-view-preview" class="artifact-pane active">
                <iframe id="artifact-modal-iframe" class="artifact-iframe" sandbox="allow-scripts"></iframe>
            </div>
            <div id="artifact-modal-view-code" class="artifact-pane">
                <pre class="artifact-code-wrapper"><code id="artifact-modal-code-display"></code></pre>
            </div>
        </div>
    </div>
</div>
</body>
</html>
