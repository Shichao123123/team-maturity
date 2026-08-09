<?php
$docx = dirname(__DIR__) . '/docs/客户演示-系统功能与方案说明.docx';
$work = dirname(__DIR__) . '/scripts/_docx_fix_paren2';
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

$pos = mb_strpos($xml, '事后同步，非开测前置');
$chunk = mb_substr($xml, $pos, 400);
preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/u', $chunk, $m);
foreach ($m[1] as $i => $t) {
    echo "#$i len=" . strlen($t) . " hex=" . bin2hex($t) . " text=[$t]\n";
    if ($i > 6) {
        break;
    }
}

// Remove any w:t that is exactly （） or () after the title area — limit to first 2 occurrences near title
$xml2 = preg_replace('/(<w:t[^>]*>)（）(<\/w:t>)/u', '$1$2', $xml, 3, $c1);
$xml2 = preg_replace('/(<w:t[^>]*>)\(\)(<\/w:t>)/u', '$1$2', $xml2, 3, $c2);
echo "cleared fullwidth=$c1 ascii=$c2\n";

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
echo "DONE\n";
