<?php
$notice = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    try {
        if ($action === 'update') {
            mya_memory_update($id, [
                'title' => (string) ($_POST['title'] ?? ''),
                'body'  => (string) ($_POST['body'] ?? ''),
                'url'   => (string) ($_POST['url'] ?? ''),
                'tags'  => (string) ($_POST['tags'] ?? ''),
            ]);
            $notice = 'Saved.';
        } elseif ($action === 'delete') {
            $notice = mya_memory_delete($id) ? 'Deleted.' : 'Nothing to delete.';
        }
    } catch (Throwable $e) {
        $notice = $e->getMessage();
    }
}

$query   = trim((string) ($_GET['q'] ?? ''));
$rows    = mya_memory_list($query, 100);
$total   = mya_memory_count();
$editing = (int) ($_GET['edit'] ?? 0);
?>
<div class="page page-wide">
    <h1>Memories <span class="muted">(<?= $total ?>)</span></h1>

    <?php if ($notice !== ''): ?>
        <div class="card muted"><?= mya_e($notice) ?></div>
    <?php endif; ?>

    <form method="get" class="mem-search">
        <input type="hidden" name="page" value="memories">
        <input class="input" type="search" name="q" value="<?= mya_e($query) ?>"
               placeholder="Search your memories…">
    </form>

    <?php if ($rows === []): ?>
        <p class="muted"><?= $query === '' ? 'Nothing saved yet.' : 'No memory matches that.' ?></p>
    <?php endif; ?>

    <div class="mem-grid">
    <?php foreach ($rows as $row): ?>
        <div class="card mem-card <?= $editing === (int) $row['id'] ? 'mem-card-wide' : '' ?>">
            <?php if ($editing === (int) $row['id']): ?>
                <form method="post">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <input class="input mem-field" name="title" value="<?= mya_e($row['title']) ?>">
                    <textarea class="textarea mem-field" name="body" rows="3"><?= mya_e($row['body']) ?></textarea>
                    <input class="input mem-field" name="url" value="<?= mya_e($row['url']) ?>" placeholder="URL">
                    <input class="input mem-field" name="tags" value="<?= mya_e($row['tags']) ?>" placeholder="tags, comma separated">
                    <div class="mem-actions">
                        <button class="btn btn-primary" type="submit">Save</button>
                        <a class="btn btn-quiet" href="?page=memories&q=<?= urlencode($query) ?>">Cancel</a>
                    </div>
                </form>
            <?php else: ?>
                <h2 class="mem-title"><?= mya_e($row['title']) ?></h2>
                <div class="mem-body"><?= nl2br(mya_e($row['body'])) ?></div>

                <?php if ($row['url'] !== null): ?>
                    <a class="mem-url" href="<?= mya_e($row['url']) ?>" target="_blank"
                       rel="noopener" title="<?= mya_e($row['url']) ?>">
                        <?= mya_e(parse_url($row['url'], PHP_URL_HOST) ?: $row['url']) ?> &#8599;
                    </a>
                <?php endif; ?>

                <div class="mem-meta">
                    <span class="mem-tags">
                        <?php foreach (array_filter(explode(',', (string) $row['tags'])) as $tag): ?>
                            <span class="tag"><?= mya_e($tag) ?></span>
                        <?php endforeach; ?>
                        <span class="muted"><?= mya_e($row['updated_at']) ?></span>
                    </span>
                    <span class="mem-actions">
                        <a class="btn btn-quiet" href="?page=memories&q=<?= urlencode($query) ?>&edit=<?= (int) $row['id'] ?>">Edit</a>
                        <form method="post" onsubmit="return confirm('Delete this memory?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <button class="btn btn-quiet" type="submit">Delete</button>
                        </form>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
</div>
