<?php

require_once __DIR__ . '/db.php';

// Values live here and are shown only in the browser. Nothing in this file is
// ever handed to the model: the only tool that touches secrets returns names
// and notes, and there is deliberately no tool that can read a value.
function mya_secret_save(string $name, string $value, string $note = ''): void
{
    $name  = trim($name);
    $value = trim($value);

    if ($name === '' || $value === '') {
        throw new InvalidArgumentException('A secret needs both a name and a value.');
    }

    $stmt = mya_db()->prepare("
        INSERT INTO secrets (name, value, note, created_at, updated_at)
        VALUES (:name, :value, :note, :now, :now2)
        ON CONFLICT(name) DO UPDATE SET
            value = excluded.value, note = excluded.note, updated_at = excluded.updated_at
    ");
    $now = mya_now();
    $stmt->execute([
        ':name'  => $name,
        ':value' => $value,
        ':note'  => trim($note),
        ':now'   => $now,
        ':now2'  => $now,
    ]);
}

function mya_secret_get(string $name): ?array
{
    $stmt = mya_db()->prepare("SELECT * FROM secrets WHERE name = :name");
    $stmt->execute([':name' => trim($name)]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function mya_secret_list(): array
{
    return mya_db()->query("SELECT * FROM secrets ORDER BY name ASC")->fetchAll();
}

// What the model is allowed to see: names and notes, never values.
function mya_secret_index(): array
{
    $out = [];
    foreach (mya_secret_list() as $row) {
        $out[] = ['name' => $row['name'], 'note' => (string) $row['note']];
    }

    return $out;
}

function mya_secret_delete(string $name): bool
{
    $stmt = mya_db()->prepare("DELETE FROM secrets WHERE name = :name");
    $stmt->execute([':name' => trim($name)]);

    return $stmt->rowCount() > 0;
}
