<?php
$docx = dirname(__DIR__) . '/docs/客户演示-系统功能与方案说明.docx';
$work = dirname(__DIR__) . '/scripts/_docx_fix_paren3';
function rrmdir($d)
{
    if (!is_dir($d)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($d);
}
rrmdir($work);
mkdir($work);
copy($docx, "$work/src.zip");
$z = new ZipArchive();
$z->open("$work/src.zip");
$z->extractTo("$work/u");
$z->close();
$p = "$work/u/word/document.xml";
$xml = file_get_contents($p);

// 在「非开测前置」之后，清掉紧随的拆分全角括号两个 run
$xml2 = preg_replace(
    '/(非开测前置)(<\/w:t><\/w:r>)(<w:r>.*?<\/w:rPr><w:t[^>]*>)（(<\/w:t><\/w:r>)(<w:r>.*?<\/w:rPr><w:t[^>]*>)）(<\/w:t>)/u',
    '$1$2$3$4$5$6',
    $xml,
    1,
    $c
);
// 若上面太严，分别清空单独的 （ 和 ） run（各限 1 次，且仅出现在标题附近时）
if ($c === 0) {
    // 找 非开测前置 后 500 字符窗口内替换
    $pos = mb_strpos($xml, '非开测前置');
    if ($pos !== false) {
        $head = mb_substr($xml, 0, $pos + mb_strlen('非开测前置'));
        $tail = mb_substr($xml, $pos + mb_strlen('非开测前置'));
        $win = mb_substr($tail, 0, 600);
        $rest = mb_substr($tail, 600);
        $win2 = preg_replace('/(<w:t[^>]*>)（(<\/w:t>)/u', '$1$2', $win, 1);
        $win2 = preg_replace('/(<w:t[^>]*>)）(<\/w:t>)/u', '$1$2', $win2, 1);
        $xml2 = $head . $win2 . $rest;
        $c = 1;
        echo "window clear\n";
    }
}
echo "c=$c\n";
file_put_contents($p, $xml2);

$zo = new ZipArchive();
$zo->open("$work/out.docx", ZipArchive::CREATE);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$work/u", FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isFile()) {
        $full = $f->getPathname();
        $local = str_replace('\\', '/', substr($full, strlen("$work/u") + 1));
        $zo->addFile($full, $local);
    }
}
$zo->close();
copy("$work/out.docx", $docx);
copy($docx, dirname(__DIR__) . '/docs/客户演示-系统功能与方案说明-已更新扫码流程.docx');
echo "DONE\n";
