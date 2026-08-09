<?php
/**
 * 补丁：仅修正第 7 条（跨 run 拆分的文本），并刷新示意图。
 * 保留用户其余删改。
 */
$root = dirname(__DIR__);
$docx = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . '客户演示-系统功能与方案说明.docx';
$work = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . '_docx_patch_item7';
$mediaDir = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'demo-diagrams';

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
$zipCopy = $work . DIRECTORY_SEPARATOR . 'src.zip';
copy($docx, $zipCopy);
$zip = new ZipArchive();
$zip->open($zipCopy);
$unz = $work . DIRECTORY_SEPARATOR . 'unz';
$zip->extractTo($unz);
$zip->close();

$xmlPath = $unz . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . 'document.xml';
$xml = file_get_contents($xmlPath);

// 标题：7. 成员管理 + 后续 run 中的（）
$xml2 = preg_replace(
    '/(<w:t>)7\. 成员管理(<\/w:t><\/w:r><w:r[^>]*>.*?<w:t[^>]*>)（）(<\/w:t>)/u',
    '$1' . '7. 成员管理（事后同步，非开测前置）' . '$2$3',
    $xml,
    1,
    $countTitle
);
if ($countTitle === 0) {
    // 兜底：只替换标题 run，并尽量清掉紧随的（）
    $xml2 = preg_replace('/(<w:t>)7\. 成员管理(<\/w:t>)/u', '$1' . '7. 成员管理（事后同步，非开测前置）' . '$2', $xml, 1, $countTitle);
    $xml2 = preg_replace('/(<w:t[^>]*>)（）(<\/w:t>)/u', '$1$2', $xml2, 1);
}
echo "title replaces={$countTitle}\n";

// 正文：两个相邻 run
$fromA = '维护班组成员基础信息';
$fromB = '.所属班组决定成绩归入哪个班组；状态可区分在岗/停用等。';
$to = '正式测评不要求事先导入人员名单。成员在小程序扫码作答时选择单位并填写姓名；提交后系统将人员与答卷同步入库，所属班组决定成绩归入哪个班组。PC 端可事后查看、补全或维护成员信息。';

if (strpos($xml2, $fromA) !== false && strpos($xml2, $fromB) !== false) {
    // 把第一段换成完整新文案，第二段清空
    $xml2 = str_replace($fromA, $to, $xml2);
    $xml2 = str_replace($fromB, '', $xml2);
    echo "body OK\n";
} else {
    echo "body MISS\n";
    echo 'hasA=' . (strpos($xml2, $fromA) !== false ? '1' : '0') . ' hasB=' . (strpos($xml2, $fromB) !== false ? '1' : '0') . "\n";
}

file_put_contents($xmlPath, $xml2);

foreach (['02-modules.png', '03-flow.png', '05-boundary.png', '06-sequence.png'] as $name) {
    $src = $mediaDir . DIRECTORY_SEPARATOR . $name;
    $dst = $unz . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . $name;
    if (is_file($src) && is_file($dst)) {
        copy($src, $dst);
        echo "MEDIA {$name}\n";
    }
}

$outTmp = $work . DIRECTORY_SEPARATOR . 'out.docx';
$zipOut = new ZipArchive();
$zipOut->open($outTmp, ZipArchive::CREATE);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($unz, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $full = $file->getPathname();
    $local = str_replace('\\', '/', substr($full, strlen($unz) + 1));
    $zipOut->addFile($full, $local);
}
$zipOut->close();

$alt = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . '客户演示-系统功能与方案说明-已更新扫码流程.docx';
if (@copy($outTmp, $docx)) {
    echo "WRITTEN main\n";
} elseif (@copy($outTmp, $alt)) {
    echo "WRITTEN alt\n";
} else {
    echo "WRITE FAIL\n";
    exit(1);
}
echo "DONE\n";
