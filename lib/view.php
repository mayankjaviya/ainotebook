<?php

require_once __DIR__ . '/settings.php';

function mya_menu(): array
{
    return [
        'chat'      => ['label' => 'Chat',      'icon' => '&#9679;'],
        'memories'  => ['label' => 'Memories',  'icon' => '&#9632;'],
        'creations' => ['label' => 'Creations', 'icon' => '&#9733;'],
        'tasks'     => ['label' => 'Tasks',     'icon' => '&#9744;'],
        'secrets'   => ['label' => 'Secrets',   'icon' => '&#9650;'],
        'settings'  => ['label' => 'Settings',  'icon' => '&#9670;'],
    ];
}

function mya_creations_list(): array
{
    $db   = mya_db();
    $stmt = $db->query("
        SELECT id, conversation_id, content, created_at
        FROM messages
        WHERE role = 'assistant'
          AND (content LIKE '%<artifact%' OR content LIKE '%<!DOCTYPE html%' OR content LIKE '%<html%' OR content LIKE '%<svg%')
        ORDER BY id DESC
    ");

    $creations = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $content = $row['content'];

        if (preg_match_all('/<artifact\b[^>]*?\btitle=["\']?([^"\'>]+)["\']?[^>]*?>(.*?)<\/artifact>/is', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $creations[] = [
                    'id'              => $row['id'],
                    'conversation_id' => $row['conversation_id'],
                    'title'           => trim($m[1]),
                    'type'            => 'html',
                    'code'            => trim($m[2]),
                    'created_at'      => $row['created_at'],
                ];
            }
        } elseif (preg_match('/```(?:[a-z0-9_-]+)?\r?\n([\s\S]*?)\r?\n```/i', $content, $m)) {
            $raw = trim($m[1]);
            if (preg_match('/^(<!DOCTYPE html|<html|<svg)/i', ltrim($raw))) {
                $creations[] = [
                    'id'              => $row['id'],
                    'conversation_id' => $row['conversation_id'],
                    'title'           => 'Visual Web Application',
                    'type'            => 'html',
                    'code'            => $raw,
                    'created_at'      => $row['created_at'],
                ];
            }
        }
    }

    return $creations;
}

function mya_current_page(): string
{
    $page = (string) ($_GET['page'] ?? 'chat');

    return array_key_exists($page, mya_menu()) ? $page : 'chat';
}

function mya_embed_mode(): bool
{
    return (string) ($_GET['embed'] ?? '') === '1';
}

function mya_e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function mya_markdown(?string $text): string
{
    $text = (string) $text;
    if (trim($text) === '') {
        return '';
    }

    // Strip reasoning <think>...</think> tags
    $text = preg_replace('/<think>.*?<\/think>/is', '', $text);
    $text = preg_replace('/<\/think>/i', '', $text);

    // Extract <artifact title="..." type="...">content</artifact> blocks
    $artifacts = [];
    $text = preg_replace_callback(
        '/<artifact\b[^>]*?\btitle=["\']?([^"\'>]+)["\']?[^>]*?>(.*?)<\/artifact>/is',
        static function ($m) use (&$artifacts) {
            $key   = '%%ARTIFACT_BLOCK_' . count($artifacts) . '%%';
            $title = htmlspecialchars(trim($m[1]), ENT_QUOTES, 'UTF-8');
            $type  = 'html';
            $code  = trim($m[2]);

            $artifacts[$key] = '<div class="artifact-card" data-type="' . $type . '">'
                . '<div class="artifact-card-icon">&#127912;</div>'
                . '<div class="artifact-card-info">'
                . '<div class="artifact-card-title">' . $title . '</div>'
                . '<div class="artifact-card-sub">Interactive Visual Preview</div>'
                . '</div>'
                . '<button class="btn btn-secondary btn-open-artifact" type="button" data-title="' . $title . '" data-type="' . $type . '">Open Preview &#8599;</button>'
                . '<script class="artifact-code-data" type="text/plain">' . base64_encode($code) . '</script>'
                . '</div>';

            return $key;
        },
        $text
    );

    // 1. Escape HTML
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // 2. Extract code blocks
    $codeBlocks = [];
    $text = preg_replace_callback('/```(?:[a-z0-9_-]+)?\r?\n(.*?)\r?\n```/s', static function ($m) use (&$codeBlocks, &$artifacts) {
        $raw = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        if (preg_match('/^(<!DOCTYPE html|<html|<svg)/i', ltrim($raw))) {
            $key   = '%%ARTIFACT_BLOCK_' . count($artifacts) . '%%';
            $title = 'Visual Preview';
            $type  = 'html';

            return '<div class="artifact-card" data-type="' . $type . '">'
                . '<div class="artifact-card-icon">&#127912;</div>'
                . '<div class="artifact-card-info">'
                . '<div class="artifact-card-title">' . $title . '</div>'
                . '<div class="artifact-card-sub">Interactive Visual Preview</div>'
                . '</div>'
                . '<button class="btn btn-secondary btn-open-artifact" type="button" data-title="' . $title . '" data-type="' . $type . '">Open Preview &#8599;</button>'
                . '<script class="artifact-code-data" type="text/plain">' . base64_encode($raw) . '</script>'
                . '</div>';
        }

        $key = '%%CODE_BLOCK_' . count($codeBlocks) . '%%';
        $codeBlocks[$key] = '<pre><code>' . $m[1] . '</code></pre>';

        return $key;
    }, $text);

    // 3. Extract inline code
    $inlineCode = [];
    $text = preg_replace_callback('/`([^`]+)`/', static function ($m) use (&$inlineCode) {
        $key = '%%INLINE_CODE_' . count($inlineCode) . '%%';
        $inlineCode[$key] = '<code>' . $m[1] . '</code>';

        return $key;
    }, $text);

    // 4. Headers
    $text = preg_replace('/^### (.*?)$/m', '<h3>$1</h3>', $text);
    $text = preg_replace('/^## (.*?)$/m', '<h2>$1</h2>', $text);
    $text = preg_replace('/^# (.*?)$/m', '<h1>$1</h1>', $text);

    // 5. Bold & Italic
    $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text);
    $text = preg_replace('/__(.*?)__/s', '<strong>$1</strong>', $text);
    $text = preg_replace('/(?<!\*)\*(?!\*)(.*?)\*/s', '<em>$1</em>', $text);

    // 5b. Images ![alt](url)
    $text = preg_replace_callback('/!\[([^\]]*)\]\((data:image\/[^\)]+|https?:\/\/[^\)]+)\)/i', static function ($m) {
        $alt = htmlspecialchars(trim($m[1]), ENT_QUOTES, 'UTF-8');
        $url = trim($m[2]);

        return '<div class="msg-image-wrapper"><img src="' . $url . '" alt="' . $alt . '" class="msg-image-thumb" onclick="window.myaOpenImageModal(this.src)"></div>';
    }, $text);

    // 6. Links [label](url)
    $text = preg_replace_callback('/(?<!\!)\[([^\]]+)\]\(([^)]+)\)/', static function ($m) {
        $label = $m[1];
        $url   = trim($m[2]);
        if (preg_match('/^(https?:\/\/|chrome-extension:\/\/|moz-extension:\/\/)/i', $url)) {
            return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>';
        }

        return $label . ' (' . $url . ')';
    }, $text);

    // Split inline bullet items after punctuation/colons onto newlines
    $text = preg_replace('/([:\.\!\?])\s+[\-\*]\s+/u', "$1\n- ", $text);

    // 7. Lists
    $lines    = explode("\n", $text);
    $inList   = false;
    $listType = null;
    $outLines = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (preg_match('/^[\-\*]\s+(.*)$/', $trimmed, $m)) {
            if (!$inList || $listType !== 'ul') {
                if ($inList) {
                    $outLines[] = "</$listType>";
                }
                $outLines[] = "\n<ul>";
                $inList   = true;
                $listType = 'ul';
            }
            $outLines[] = '<li>' . $m[1] . '</li>';
        } elseif (preg_match('/^\d+\.\s+(.*)$/', $trimmed, $m)) {
            if (!$inList || $listType !== 'ol') {
                if ($inList) {
                    $outLines[] = "</$listType>";
                }
                $outLines[] = "\n<ol>";
                $inList   = true;
                $listType = 'ol';
            }
            $outLines[] = '<li>' . $m[1] . '</li>';
        } else {
            if ($inList) {
                $outLines[] = "</$listType>";
                $inList   = false;
                $listType = null;
            }
            $outLines[] = $line;
        }
    }
    if ($inList) {
        $outLines[] = "</$listType>";
    }

    $text = implode("\n", $outLines);

    // 8. Paragraphs
    $blocks          = preg_split('/\n{2,}/', $text);
    $formattedBlocks = [];
    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') {
            continue;
        }
        if (preg_match('/^<(ul|ol|h1|h2|h3|pre|div)/i', $block)) {
            $formattedBlocks[] = $block;
        } else {
            $formattedBlocks[] = '<p>' . nl2br($block) . '</p>';
        }
    }

    $result = implode("\n", $formattedBlocks);

    // Re-insert inline code, code blocks, and artifacts
    foreach ($inlineCode as $key => $val) {
        $result = str_replace($key, $val, $result);
    }
    foreach ($codeBlocks as $key => $val) {
        $result = str_replace($key, $val, $result);
    }
    foreach ($artifacts as $key => $val) {
        $result = str_replace($key, $val, $result);
    }

    return $result;
}
