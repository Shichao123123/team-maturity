<?php
$docx = dirname(__DIR__) . '/docs/客户演示-系统功能与方案说明.docx';
$tmp = sys_get_temp_dir() . '/tm_p7_' . md5(microtime());
mkdir($tmp);
copy($docx, "$tmp/a.zip");
$z = new ZipArchive();
$z->open("$tmp/a.zip");
$z->extractTo("$tmp/u");
$z->close();
$xml = file_get_contents("$tmp/u/word/document.xml");
$pos = mb_strpos($xml, '维护班组成员');
$window = mb_substr($xml, $pos, 500);
echo $window . "\n\n";
// extract all w:t after that within 1500 chars
$chunk = mb_substr($xml, $pos, 1500);
preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/u', $chunk, $m);
foreach ($m[1] as $i => $t) {
    echo "#$i [" . $t . "]\n";
    if ($i > 8) break;
}
