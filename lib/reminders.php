<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

function mya_reminder_add(string $text, string $dueAt): int
{
    $text = trim($text);
    if ($text === '') {
        throw new InvalidArgumentException('A reminder needs some text.');
    }

    $due = strtotime($dueAt);
    if ($due === false) {
        throw new InvalidArgumentException("I could not read that time: $dueAt");
    }

    $stmt = mya_db()->prepare("
        INSERT INTO reminders (text, due_at, created_at)
        VALUES (:text, :due, :now)
    ");
    $stmt->execute([
        ':text' => $text,
        ':due'  => date('Y-m-d H:i:s', $due),
        ':now'  => mya_now(),
    ]);

    return (int) mya_db()->lastInsertId();
}

// Reminders this channel should fire right now: past due, not yet delivered on
// this channel, and only if the owner turned this channel on.
function mya_reminder_due(string $channel): array
{
    if (!mya_channel_enabled($channel)) {
        return [];
    }

    $column = mya_reminder_column($channel);

    $stmt = mya_db()->prepare("
        SELECT * FROM reminders
        WHERE due_at <= :now AND $column IS NULL
        ORDER BY due_at ASC
    ");
    $stmt->execute([':now' => mya_now()]);

    return $stmt->fetchAll();
}

function mya_reminder_mark(int $id, string $channel): void
{
    $column = mya_reminder_column($channel);

    $stmt = mya_db()->prepare("UPDATE reminders SET $column = :now WHERE id = :id");
    $stmt->execute([':now' => mya_now(), ':id' => $id]);
}

function mya_reminder_upcoming(int $limit = 20): array
{
    $stmt = mya_db()->prepare("
        SELECT * FROM reminders WHERE due_at > :now ORDER BY due_at ASC LIMIT :limit
    ");
    $stmt->bindValue(':now', mya_now(), PDO::PARAM_STR);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function mya_reminder_delete(int $id): bool
{
    $stmt = mya_db()->prepare("DELETE FROM reminders WHERE id = :id");
    $stmt->execute([':id' => $id]);

    return $stmt->rowCount() > 0;
}

function mya_notify_channel(): string
{
    $channel = (string) mya_setting_get('notify_channel', 'chrome');

    return in_array($channel, ['chrome', 'macos', 'both'], true) ? $channel : 'chrome';
}

function mya_channel_enabled(string $channel): bool
{
    $chosen = mya_notify_channel();

    return $chosen === 'both' || $chosen === $channel;
}

// The column name is interpolated into SQL, so it may only ever come from here.
function mya_reminder_column(string $channel): string
{
    switch ($channel) {
        case 'chrome':
            return 'notified_chrome_at';
        case 'macos':
            return 'notified_macos_at';
    }

    throw new InvalidArgumentException("Unknown notification channel: $channel");
}
