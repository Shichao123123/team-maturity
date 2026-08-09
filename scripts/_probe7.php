<?php
$xml = file_get_contents(__DIR__ . '/_docx_patch_work/unz/word/document.xml');

function showAround($xml, $needle, $before = 20, $after = 120)
{
    $pos = mb_strpos($xml, $needle);
    if ($pos === false) {
        echo "MISS: {$needle}\n";
        return;
    }
    echo "HIT: {$needle} @{$pos}\n";
    echo mb_substr($xml, max(0, $pos - $before), $before + mb_strlen($needle) + $after) . "\n---\n";
}

showAround($xml, '7. 成员管理');
showAround($xml, '成员管理（');
showAround($xml, '维护班组成员基础信息');

// Flatten text of paragraphs containing 成员管理基础信息 by stripping tags in a window
$pos = mb_strpos($xml, '维护班组成员基础信息');
$window = mb_substr($xml, $pos, 800);
$flat = html_entity_decode(strip_tags(preg_replace('/<\/w:p>/', "\n", $window)), ENT_QUOTES, 'UTF-8');
echo "FLAT:\n{$flat}\n";
