<?php
require_once __DIR__ . '/../lib/secrets.php';

$notice = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save') {
            mya_secret_save(
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['value'] ?? ''),
                (string) ($_POST['note'] ?? '')
            );
            $notice = 'Saved.';
        } elseif ($action === 'delete') {
            $notice = mya_secret_delete((string) ($_POST['name'] ?? '')) ? 'Deleted.' : 'Nothing to delete.';
        }
    } catch (Throwable $e) {
        $notice = $e->getMessage();
    }
}

$rows = mya_secret_list();
?>
<div class="page">
    <h1>Secrets <span class="muted">(<?= count($rows) ?>)</span></h1>

    <p class="muted secret-warning">
        The assistant can see these names and notes, never the values.
        Add them here &mdash; never type a password into the chat, because that text is
        sent to the AI before anything can stop it.
    </p>

    <?php if ($notice !== ''): ?>
        <div class="card muted"><?= mya_e($notice) ?></div>
    <?php endif; ?>

    <form method="post" class="card" autocomplete="off">
        <input type="hidden" name="action" value="save">
        <input class="input mem-field" name="name" placeholder="Name, e.g. staging-db" required autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
        <input class="input mem-field" type="password" name="value" placeholder="Value" required autocomplete="new-password" autocorrect="off" autocapitalize="off" spellcheck="false">
        <input class="input mem-field" name="note" placeholder="Note (optional) &mdash; the assistant can read this" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
        <button class="btn btn-primary" type="submit">Save secret</button>
    </form>

    <?php if ($rows === []): ?>
        <p class="muted">Nothing stored yet.</p>
    <?php endif; ?>

    <?php foreach ($rows as $row): ?>
        <div class="card">
            <div class="mem-title"><?= mya_e($row['name']) ?></div>

            <?php if (trim((string) $row['note']) !== ''): ?>
                <div class="muted"><?= mya_e($row['note']) ?></div>
            <?php endif; ?>

            <div class="secret-value">
                <code class="secret-text" data-value="<?= mya_e($row['value']) ?>">••••••••••••</code>
                <button class="btn btn-quiet secret-reveal" type="button">Show</button>
                <button class="btn btn-quiet secret-copy" type="button">Copy</button>
            </div>

            <div class="mem-meta">
                <span class="muted">updated <?= mya_e($row['updated_at']) ?></span>
                <form method="post" onsubmit="return confirm('Delete this secret?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="name" value="<?= mya_e($row['name']) ?>">
                    <button class="btn btn-quiet" type="submit">Delete</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<script src="assets/secrets.js?v=1"></script>
