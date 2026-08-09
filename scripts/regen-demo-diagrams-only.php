<?php
/**
 * 仅重绘 demo-diagrams PNG，不覆盖客户已编辑的 Word 正文。
 * 用法：php scripts/regen-demo-diagrams-only.php
 */
$root = dirname(__DIR__);
$docs = $root . DIRECTORY_SEPARATOR . 'docs';
$mediaDir = $docs . DIRECTORY_SEPARATOR . 'demo-diagrams';
$outDummy = $docs . DIRECTORY_SEPARATOR . '_tmp_regen_diagrams.docx';

// 复用 export 脚本：通过 argv 指定临时输出，避免覆盖主 docx
passthru(
    escapeshellarg(PHP_BINARY) . ' '
    . escapeshellarg($root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'export-demo-docx.php') . ' '
    . escapeshellarg($outDummy),
    $code
);
if (is_file($outDummy)) {
    unlink($outDummy);
}
echo "diagrams refreshed under docs/demo-diagrams/, exit={$code}\n";
exit($code);
