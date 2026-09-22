<?php

require_once __DIR__ . '/db.php';

function mya_task_add(array $data): int
{
    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('A task needs a title.');
    }

    $priority = strtolower(trim((string) ($data['priority'] ?? 'normal')));
    if (!in_array($priority, ['low', 'normal', 'high'], true)) {
        $priority = 'normal';
    }

    $dueDate = trim((string) ($data['due_date'] ?? ''));
    if ($dueDate !== '') {
        $ts = strtotime($dueDate);
        $dueDate = $ts !== false ? date('Y-m-d', $ts) : null;
    } else {
        $dueDate = null;
    }

    $project = trim((string) ($data['project'] ?? ''));
    $project = $project !== '' ? $project : null;

    $stmt = mya_db()->prepare("
        INSERT INTO tasks (title, status, priority, due_date, project, created_at, completed_at)
        VALUES (:title, 'pending', :priority, :due_date, :project, :created_at, NULL)
    ");
    $stmt->execute([
        ':title'      => $title,
        ':priority'   => $priority,
        ':due_date'   => $dueDate,
        ':project'    => $project,
        ':created_at' => mya_now(),
    ]);

    return (int) mya_db()->lastInsertId();
}

function mya_task_get(int $id): ?array
{
    $stmt = mya_db()->prepare("SELECT * FROM tasks WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function mya_task_list(array $filter = []): array
{
    $where  = [];
    $params = [];

    $status = (string) ($filter['status'] ?? 'pending');
    if ($status !== 'all' && in_array($status, ['pending', 'completed'], true)) {
        $where[]  = 'status = :status';
        $params[':status'] = $status;
    }

    $priority = (string) ($filter['priority'] ?? '');
    if (in_array($priority, ['low', 'normal', 'high'], true)) {
        $where[]  = 'priority = :priority';
        $params[':priority'] = $priority;
    }

    $project = trim((string) ($filter['project'] ?? ''));
    if ($project !== '') {
        $where[]  = 'project = :project';
        $params[':project'] = $project;
    }

    $search = trim((string) ($filter['search'] ?? ''));
    if ($search !== '') {
        $where[]  = '(title LIKE :search OR project LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    $sql = "SELECT * FROM tasks";
    if ($where !== []) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }

    if ($status === 'completed') {
        $sql .= " ORDER BY completed_at DESC, id DESC";
    } else {
        $sql .= " ORDER BY 
            CASE priority WHEN 'high' THEN 1 WHEN 'normal' THEN 2 WHEN 'low' THEN 3 ELSE 4 END ASC,
            CASE WHEN due_date IS NOT NULL AND due_date != '' THEN 0 ELSE 1 END ASC,
            due_date ASC,
            id DESC";
    }

    $limit = (int) ($filter['limit'] ?? 100);
    $sql  .= " LIMIT " . max(1, $limit);

    $stmt = mya_db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function mya_task_update(int $id, array $data): array
{
    $task = mya_task_get($id);
    if ($task === null) {
        throw new InvalidArgumentException("Task with id $id not found.");
    }

    $title = isset($data['title']) ? trim((string) $data['title']) : $task['title'];
    if ($title === '') {
        throw new InvalidArgumentException('A task needs a title.');
    }

    $priority = isset($data['priority']) ? strtolower(trim((string) $data['priority'])) : $task['priority'];
    if (!in_array($priority, ['low', 'normal', 'high'], true)) {
        $priority = $task['priority'];
    }

    if (array_key_exists('due_date', $data)) {
        $dueRaw = trim((string) $data['due_date']);
        if ($dueRaw !== '') {
            $ts = strtotime($dueRaw);
            $dueDate = $ts !== false ? date('Y-m-d', $ts) : null;
        } else {
            $dueDate = null;
        }
    } else {
        $dueDate = $task['due_date'];
    }

    if (array_key_exists('project', $data)) {
        $projRaw = trim((string) $data['project']);
        $project = $projRaw !== '' ? $projRaw : null;
    } else {
        $project = $task['project'];
    }

    $stmt = mya_db()->prepare("
        UPDATE tasks
        SET title = :title, priority = :priority, due_date = :due_date, project = :project
        WHERE id = :id
    ");
    $stmt->execute([
        ':title'    => $title,
        ':priority' => $priority,
        ':due_date' => $dueDate,
        ':project'  => $project,
        ':id'       => $id,
    ]);

    return mya_task_get($id);
}

function mya_task_complete(int $id): bool
{
    $stmt = mya_db()->prepare("
        UPDATE tasks
        SET status = 'completed', completed_at = :now
        WHERE id = :id
    ");
    $stmt->execute([
        ':now' => mya_now(),
        ':id'  => $id,
    ]);

    return $stmt->rowCount() > 0;
}

function mya_task_reopen(int $id): bool
{
    $stmt = mya_db()->prepare("
        UPDATE tasks
        SET status = 'pending', completed_at = NULL
        WHERE id = :id
    ");
    $stmt->execute([':id' => $id]);

    return $stmt->rowCount() > 0;
}

function mya_task_delete(int $id): bool
{
    $stmt = mya_db()->prepare("DELETE FROM tasks WHERE id = :id");
    $stmt->execute([':id' => $id]);

    return $stmt->rowCount() > 0;
}

function mya_task_counts(): array
{
    $pdo = mya_db();

    $pending = (int) $pdo->query("SELECT count(*) FROM tasks WHERE status = 'pending'")->fetchColumn();
    $high    = (int) $pdo->query("SELECT count(*) FROM tasks WHERE status = 'pending' AND priority = 'high'")->fetchColumn();
    $done    = (int) $pdo->query("SELECT count(*) FROM tasks WHERE status = 'completed'")->fetchColumn();
    $total   = (int) $pdo->query("SELECT count(*) FROM tasks")->fetchColumn();

    return [
        'pending'       => $pending,
        'high_priority' => $high,
        'completed'     => $done,
        'total'         => $total,
    ];
}
