<?php
require_once __DIR__ . '/../lib/view.php';
require_once __DIR__ . '/../lib/chat.php';

$creations = mya_creations_list();
?>
<div class="page">
    <h1>Creations <span class="muted">(<?= count($creations) ?>)</span></h1>
    <p class="muted">All interactive web applications, animations, and visual artifacts created during conversations.</p>

    <?php if ($creations === []): ?>
        <div class="card muted">No visual web creations found yet. Ask the chat assistant to build an interactive website or demo!</div>
    <?php else: ?>
        <div class="creations-grid">
            <?php foreach ($creations as $item): ?>
                <div class="creation-card">
                    <div class="creation-card-header">
                        <div class="creation-card-icon">&#127912;</div>
                        <div>
                            <div class="creation-card-title"><?= mya_e($item['title']) ?></div>
                            <div class="creation-card-date"><?= mya_e($item['created_at']) ?></div>
                        </div>
                    </div>
                    <div class="creation-card-actions">
                        <button type="button" class="btn btn-secondary btn-open-artifact"
                                data-title="<?= mya_e($item['title']) ?>" data-type="html">
                            Preview &#8599;
                        </button>
                        <script class="artifact-code-data" type="text/plain"><?= base64_encode($item['code']) ?></script>
                        <a href="?page=chat&conversation=<?= urlencode($item['conversation_id']) ?>" class="btn btn-quiet">Chat &#8594;</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
