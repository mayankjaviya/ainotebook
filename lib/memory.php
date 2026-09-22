<?php

require_once __DIR__ . '/db.php';

function mya_memory_save(array $data): int
{
    $title = trim((string) ($data['title'] ?? ''));
    $body  = trim((string) ($data['body'] ?? ''));

    if ($title === '' || $body === '') {
        throw new InvalidArgumentException('A memory needs both a title and a body.');
    }

    $stmt = mya_db()->prepare("
        INSERT INTO memories (title, body, url, tags, raw_text, created_at, updated_at)
        VALUES (:title, :body, :url, :tags, :raw_text, :now, :now2)
    ");
    $now = mya_now();
    $stmt->execute([
        ':title'    => $title,
        ':body'     => $body,
        ':url'      => mya_memory_clean_url($data['url'] ?? null),
        ':tags'     => mya_memory_tags_to_string($data['tags'] ?? null),
        ':raw_text' => isset($data['raw_text']) ? (string) $data['raw_text'] : null,
        ':now'      => $now,
        ':now2'     => $now,
    ]);

    return (int) mya_db()->lastInsertId();
}

function mya_memory_get(int $id): ?array
{
    $stmt = mya_db()->prepare("SELECT * FROM memories WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function mya_memory_search(string $query, int $limit = 8): array
{
    $match = mya_memory_fts_query($query);
    if ($match === '') {
        return [];
    }

    $stmt = mya_db()->prepare("
        SELECT m.*
        FROM memories_fts f
        JOIN memories m ON m.id = f.rowid
        WHERE memories_fts MATCH :match
        ORDER BY bm25(memories_fts, 10.0, 1.0, 5.0)
        LIMIT :limit
    ");
    $stmt->bindValue(':match', $match, PDO::PARAM_STR);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function mya_memory_update(int $id, array $data): array
{
    $row = mya_memory_get($id);
    if ($row === null) {
        throw new RuntimeException("No memory with id $id.");
    }

    $title = array_key_exists('title', $data) ? trim((string) $data['title']) : $row['title'];
    $body  = array_key_exists('body', $data) ? trim((string) $data['body']) : $row['body'];

    if ($title === '' || $body === '') {
        throw new InvalidArgumentException('A memory needs both a title and a body.');
    }

    $stmt = mya_db()->prepare("
        UPDATE memories
        SET title = :title, body = :body, url = :url, tags = :tags, updated_at = :now
        WHERE id = :id
    ");
    $stmt->execute([
        ':title' => $title,
        ':body'  => $body,
        ':url'   => array_key_exists('url', $data)
            ? mya_memory_clean_url($data['url'])
            : $row['url'],
        ':tags'  => array_key_exists('tags', $data)
            ? mya_memory_tags_to_string($data['tags'])
            : $row['tags'],
        ':now'   => mya_now(),
        ':id'    => $id,
    ]);

    return mya_memory_get($id);
}

function mya_memory_delete(int $id): bool
{
    $stmt = mya_db()->prepare("DELETE FROM memories WHERE id = :id");
    $stmt->execute([':id' => $id]);

    return $stmt->rowCount() > 0;
}

function mya_memory_list(string $query = '', int $limit = 50, int $offset = 0): array
{
    if (trim($query) !== '') {
        return mya_memory_search($query, $limit);
    }

    $stmt = mya_db()->prepare("
        SELECT * FROM memories ORDER BY updated_at DESC, id DESC LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function mya_memory_count(): int
{
    return (int) mya_db()->query("SELECT count(*) FROM memories")->fetchColumn();
}

function mya_memory_tags_to_string($tags): ?string
{
    if ($tags === null || $tags === '') {
        return null;
    }
    if (!is_array($tags)) {
        $tags = explode(',', (string) $tags);
    }

    $clean = [];
    foreach ($tags as $tag) {
        $tag = trim((string) $tag);
        if ($tag !== '') {
            $clean[] = strtolower($tag);
        }
    }

    return $clean === [] ? null : implode(',', array_unique($clean));
}

function mya_memory_clean_url($url): ?string
{
    $url = trim((string) ($url ?? ''));

    return $url === '' ? null : $url;
}

function mya_memory_fts_query(string $text): string
{
    $stop = ['the','a','an','is','of','to','in','on','for','what','was','my','me',
             'and','it','that','this','where','how','do','did','you','i'];

    preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $m);

    $terms = [];
    foreach ($m[0] as $word) {
        if (mb_strlen($word) < 2 || in_array($word, $stop, true)) {
            continue;
        }
        $terms[] = '"' . $word . '"';
    }

    return implode(' OR ', array_slice(array_unique($terms), 0, 12));
}
