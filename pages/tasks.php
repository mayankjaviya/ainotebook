<?php
require_once __DIR__ . '/../lib/tasks.php';

$notice = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    try {
        if ($action === 'add') {
            mya_task_add([
                'title'    => (string) ($_POST['title'] ?? ''),
                'priority' => (string) ($_POST['priority'] ?? 'normal'),
                'due_date' => (string) ($_POST['due_date'] ?? ''),
                'project'  => (string) ($_POST['project'] ?? ''),
            ]);
            $notice = 'Task added.';
        } elseif ($action === 'toggle') {
            $task = mya_task_get($id);
            if ($task !== null) {
                if ($task['status'] === 'completed') {
                    mya_task_reopen($id);
                    $notice = 'Task reopened.';
                } else {
                    mya_task_complete($id);
                    $notice = 'Task completed!';
                }
            }
        } elseif ($action === 'update') {
            mya_task_update($id, [
                'title'    => (string) ($_POST['title'] ?? ''),
                'priority' => (string) ($_POST['priority'] ?? 'normal'),
                'due_date' => (string) ($_POST['due_date'] ?? ''),
                'project'  => (string) ($_POST['project'] ?? ''),
            ]);
            $notice = 'Task updated.';
        } elseif ($action === 'delete') {
            $notice = mya_task_delete($id) ? 'Task deleted.' : 'Nothing to delete.';
        }
    } catch (Throwable $e) {
        $notice = $e->getMessage();
    }
}

$counts = mya_task_counts();
$tab    = (string) ($_GET['tab'] ?? 'pending');
if (!in_array($tab, ['pending', 'high', 'completed', 'all'], true)) {
    $tab = 'pending';
}

$query   = trim((string) ($_GET['q'] ?? ''));
$project = trim((string) ($_GET['project'] ?? ''));
$editing = (int) ($_GET['edit'] ?? 0);

$filter = ['search' => $query, 'project' => $project];
if ($tab === 'high') {
    $filter['status']   = 'pending';
    $filter['priority'] = 'high';
} elseif ($tab === 'completed') {
    $filter['status'] = 'completed';
} elseif ($tab === 'all') {
    $filter['status'] = 'all';
} else {
    $filter['status'] = 'pending';
}

$tasks = mya_task_list($filter);
$today = date('Y-m-d');
?>
<div class="page page-wide">
    <div class="page-head">
        <h1>Tasks <span class="muted">(<?= (int) $counts['pending'] ?> pending)</span></h1>
    </div>

    <?php if ($notice !== ''): ?>
        <div class="card muted notice-card"><?= mya_e($notice) ?></div>
    <?php endif; ?>

    <!-- Quick Add Task Form -->
    <form method="post" class="card task-add-card">
        <input type="hidden" name="action" value="add">
        <div class="task-add-row">
            <input class="input task-input-title" name="title" placeholder="Add a new task or action item…" required autofocus>
            <select class="input task-select-priority" name="priority" title="Priority">
                <option value="normal" selected>Priority: Normal</option>
                <option value="high">Priority: High</option>
                <option value="low">Priority: Low</option>
            </select>
            <input class="input task-input-project" name="project" placeholder="Project (e.g. api)">
            <input class="input task-input-date" type="date" name="due_date" title="Due Date">
            <button class="btn btn-primary" type="submit">Add Task</button>
        </div>
    </form>

    <!-- Filter Bar / Tabs -->
    <div class="task-tabs-row">
        <div class="task-tabs">
            <a class="task-tab <?= $tab === 'pending' ? 'is-active' : '' ?>" href="?page=tasks&tab=pending">
                Pending <span class="tab-badge"><?= $counts['pending'] ?></span>
            </a>
            <a class="task-tab <?= $tab === 'high' ? 'is-active' : '' ?>" href="?page=tasks&tab=high">
                High Priority <span class="tab-badge"><?= $counts['high_priority'] ?></span>
            </a>
            <a class="task-tab <?= $tab === 'completed' ? 'is-active' : '' ?>" href="?page=tasks&tab=completed">
                Completed <span class="tab-badge"><?= $counts['completed'] ?></span>
            </a>
            <a class="task-tab <?= $tab === 'all' ? 'is-active' : '' ?>" href="?page=tasks&tab=all">
                All <span class="tab-badge"><?= $counts['total'] ?></span>
            </a>
        </div>

        <form method="get" class="task-search-form">
            <input type="hidden" name="page" value="tasks">
            <input type="hidden" name="tab" value="<?= mya_e($tab) ?>">
            <input class="input task-search-input" type="search" name="q" value="<?= mya_e($query) ?>" placeholder="Filter tasks…">
        </form>
    </div>

    <!-- Tasks List -->
    <?php if ($tasks === []): ?>
        <div class="card empty-card">
            <p class="muted">
                <?php if ($query !== ''): ?>
                    No tasks found matching "<?= mya_e($query) ?>".
                <?php elseif ($tab === 'completed'): ?>
                    No completed tasks yet.
                <?php elseif ($tab === 'high'): ?>
                    No high-priority tasks right now! 🎉
                <?php else: ?>
                    No pending tasks on your list. Add one above or tell the AI assistant in Chat!
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="tasks-list">
        <?php foreach ($tasks as $task): ?>
            <?php $isDone = $task['status'] === 'completed'; ?>
            <?php $isHigh = $task['priority'] === 'high'; ?>
            <?php $isLow  = $task['priority'] === 'low'; ?>
            <?php $isOverdue = !$isDone && !empty($task['due_date']) && $task['due_date'] < $today; ?>
            <?php $isDueToday = !$isDone && !empty($task['due_date']) && $task['due_date'] === $today; ?>

            <div class="card task-card <?= $isDone ? 'is-done' : '' ?> <?= $editing === (int) $task['id'] ? 'is-editing' : '' ?>">
                <?php if ($editing === (int) $task['id']): ?>
                    <form method="post" class="task-edit-form">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
                        <input class="input mem-field" name="title" value="<?= mya_e($task['title']) ?>" required>
                        
                        <div class="task-edit-meta-row">
                            <select class="input" name="priority">
                                <option value="normal" <?= $task['priority'] === 'normal' ? 'selected' : '' ?>>Normal Priority</option>
                                <option value="high" <?= $task['priority'] === 'high' ? 'selected' : '' ?>>High Priority</option>
                                <option value="low" <?= $task['priority'] === 'low' ? 'selected' : '' ?>>Low Priority</option>
                            </select>
                            <input class="input" name="project" value="<?= mya_e($task['project']) ?>" placeholder="Project">
                            <input class="input" type="date" name="due_date" value="<?= mya_e($task['due_date']) ?>">
                        </div>

                        <div class="mem-actions">
                            <button class="btn btn-primary" type="submit">Save</button>
                            <a class="btn btn-quiet" href="?page=tasks&tab=<?= mya_e($tab) ?>&q=<?= urlencode($query) ?>">Cancel</a>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="task-row-main">
                        <!-- Toggle Checkbox Form -->
                        <form method="post" class="task-toggle-form">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
                            <button type="submit" class="task-checkbox-btn" title="<?= $isDone ? 'Mark pending' : 'Mark completed' ?>">
                                <span class="task-custom-checkbox <?= $isDone ? 'checked' : '' ?>">
                                    <?= $isDone ? '&#10003;' : '' ?>
                                </span>
                            </button>
                        </form>

                        <div class="task-content">
                            <div class="task-title <?= $isDone ? 'task-title-done' : '' ?>">
                                <?= mya_e($task['title']) ?>
                            </div>

                            <div class="task-badges">
                                <?php if ($isHigh): ?>
                                    <span class="badge badge-high">High Priority</span>
                                <?php elseif ($isLow): ?>
                                    <span class="badge badge-low">Low Priority</span>
                                <?php endif; ?>

                                <?php if (!empty($task['project'])): ?>
                                    <span class="badge badge-project">#<?= mya_e($task['project']) ?></span>
                                <?php endif; ?>

                                <?php if (!empty($task['due_date'])): ?>
                                    <span class="badge <?= $isOverdue ? 'badge-overdue' : ($isDueToday ? 'badge-today' : 'badge-date') ?>">
                                        <?= $isOverdue ? 'Overdue: ' : ($isDueToday ? 'Due today: ' : 'Due: ') ?><?= mya_e($task['due_date']) ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($isDone && !empty($task['completed_at'])): ?>
                                    <span class="muted text-xs">Completed <?= date('M j, g:ia', strtotime($task['completed_at'])) ?></span>
                                <?php else: ?>
                                    <span class="muted text-xs">Added <?= date('M j', strtotime($task['created_at'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="task-actions">
                            <a class="btn btn-quiet btn-sm" href="?page=tasks&tab=<?= mya_e($tab) ?>&q=<?= urlencode($query) ?>&edit=<?= (int) $task['id'] ?>">Edit</a>
                            <form method="post" onsubmit="return confirm('Delete this task?');" style="display:inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
                                <button class="btn btn-quiet btn-sm" type="submit">Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
