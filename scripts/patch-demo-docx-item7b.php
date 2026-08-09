<?php
$root = dirname(__DIR__);
$docx = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . '客户演示-系统功能与方案说明.docx';
$work = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . '_docx_patch_item7b';

function rrmdir($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}
rrmdir($work);
mkdir($work, 0777, true);
copy($docx, $work . '/src.zip');
$z = new ZipArchive();
$z->open($work . '/src.zip');
$z->extractTo($work . '/unz');
$z->close();
$xmlPath = $work . '/unz/word/document.xml';
$xml = file_get_contents($xmlPath);

// 修复被拆坏的标题
foreach ([
    '. 成员管理（事后同步，非开测前置）（）' => '7. 成员管理（事后同步，非开测前置）',
    '7. 成员管理（事后同步，非开测前置）（）' => '7. 成员管理（事后同步，非开测前置）',
    '成员管理（事后同步，非开测前置）（）' => '成员管理（事后同步，非开测前置）',
] as $from => $toTitle) {
    if (strpos($xml, $from) !== false) {
        $xml = str_replace($from, $toTitle, $xml);
        echo "titleFix: {$from}\n";
    }
}
// 若 7 与正文标题分 run：单独补 7.
$xml = preg_replace('/(<w:t>)\.\s*成员管理（事后同步，非开测前置）(<\/w:t>)/u', '$17. 成员管理（事后同步，非开测前置）$2', $xml, 1);

$to = '正式测评不要求事先导入人员名单。成员在小程序扫码作答时选择单位并填写姓名；提交后系统将人员与答卷同步入库，所属班组决定成绩归入哪个班组。PC 端可事后查看、补全或维护成员信息。';

$ok = 0;
if (strpos($xml, '维护班组成员基础信息') !== false) {
    $xml = str_replace('维护班组成员基础信息', $to, $xml);
    $ok++;
}
if (strpos($xml, '所属班组决定成绩归入哪个班组；状态可区分在岗/停用等。') !== false) {
    $xml = str_replace('所属班组决定成绩归入哪个班组；状态可区分在岗/停用等。', '', $xml);
    $ok++;
}
// 清掉夹在中间的孤立句点 run
$xml = preg_replace(
    '/(维护成员信息。)(<\/w:t><\/w:r><w:r>.*?<\/w:rPr><w:t>)\.(<\/w:t><\/w:r><w:r>.*?<\/w:rPr><w:t>)(<\/w:t>)/u',
    '$1$2$3$4',
    $xml,
    1,
    $dotCleared
);
// 更直接：若存在 “信息。</w:t> ... <w:t>.</w:t> ... <w:t></w:t>” 结构，去掉中间的 .
$xml = preg_replace(
    '/(PC 端可事后查看、补全或维护成员信息\。)(<\/w:t><\/w:r>)(<w:r>.*?<\/w:rPr><w:t>)\.(<\/w:t><\/w:r>)/u',
    '$1$2',
    $xml,
    1,
    $dotCleared2
);
$dotCleared = $dotCleared + $dotCleared2;

file_put_contents($xmlPath, $xml);
echo "okParts={$ok} dotCleared={$dotCleared}\n";

$out = $work . '/out.docx';
$zo = new ZipArchive();
$zo->open($out, ZipArchive::CREATE);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work . '/unz', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile()) {
        $full = $f->getPathname();
        $local = str_replace('\\', '/', substr($full, strlen($work . '/unz') + 1));
        $zo->addFile($full, $local);
    }
}
$zo->close();
copy($out, $docx) || copy($out, $root . '/docs/客户演示-系统功能与方案说明-已更新扫码流程.docx');
echo "DONE\n";
