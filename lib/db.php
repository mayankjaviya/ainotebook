<?php

function mya_root(): string
{
    return dirname(__DIR__);
}

function mya_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = defined('MYA_DB_PATH') ? MYA_DB_PATH : mya_root() . '/data/memory.sqlite';
    $dir  = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create data folder: $dir");
    }
    if (!is_writable($dir)) {
        throw new RuntimeException("Data folder is not writable: $dir");
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    mya_db_schema($pdo);

    return $pdo;
}

function mya_db_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS memories (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            title      TEXT NOT NULL,
            body       TEXT NOT NULL,
            url        TEXT,
            tags       TEXT,
            raw_text   TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )
    ");

    $pdo->exec("
        CREATE VIRTUAL TABLE IF NOT EXISTS memories_fts USING fts5(
            title, body, tags,
            content='memories', content_rowid='id'
        )
    ");

    $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS memories_ai AFTER INSERT ON memories BEGIN
            INSERT INTO memories_fts(rowid, title, body, tags)
            VALUES (new.id, new.title, new.body, ifnull(new.tags,''));
        END
    ");
    $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS memories_ad AFTER DELETE ON memories BEGIN
            INSERT INTO memories_fts(memories_fts, rowid, title, body, tags)
            VALUES ('delete', old.id, old.title, old.body, ifnull(old.tags,''));
        END
    ");
    $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS memories_au AFTER UPDATE ON memories BEGIN
            INSERT INTO memories_fts(memories_fts, rowid, title, body, tags)
            VALUES ('delete', old.id, old.title, old.body, ifnull(old.tags,''));
            INSERT INTO memories_fts(rowid, title, body, tags)
            VALUES (new.id, new.title, new.body, ifnull(new.tags,''));
        END
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS messages (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id TEXT NOT NULL,
            role            TEXT NOT NULL,
            content         TEXT NOT NULL,
            tool_name       TEXT,
            created_at      TEXT NOT NULL
        )
    ");
    $pdo->exec("
        CREATE INDEX IF NOT EXISTS messages_conv
        ON messages (conversation_id, id)
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reminders (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            text               TEXT NOT NULL,
            due_at             TEXT NOT NULL,
            created_at         TEXT NOT NULL,
            notified_chrome_at TEXT,
            notified_macos_at  TEXT
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS reminders_due ON reminders (due_at)");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS secrets (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT NOT NULL UNIQUE,
            value      TEXT NOT NULL,
            note       TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tasks (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            title        TEXT NOT NULL,
            status       TEXT NOT NULL DEFAULT 'pending',
            priority     TEXT NOT NULL DEFAULT 'normal',
            due_date     TEXT,
            project      TEXT,
            created_at   TEXT NOT NULL,
            completed_at TEXT
        )
    ");
    $pdo->exec("
        CREATE INDEX IF NOT EXISTS tasks_status_priority
        ON tasks (status, priority, due_date)
    ");
}

function mya_now(): string
{
    return date('Y-m-d H:i:s');
}
