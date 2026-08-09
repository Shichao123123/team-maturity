<?php
$j = json_decode(file_get_contents(dirname(__DIR__) . '/docs/bank-import-preview.json'), true);
echo $j[0]['l3'] . PHP_EOL;
foreach ($j[0]['options'] as $o) {
    echo $o['ratio'] . ' | ' . $o['content'] . PHP_EOL;
}
echo "---\nTOTAL=" . count($j) . PHP_EOL;
