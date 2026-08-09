<?php
$root = dirname(__DIR__);
$docx = $root . '/docs/客户演示-系统功能与方案说明.docx';
$work = $root . '/scripts/_docx_fix7';
function rrmdir($d){ if(!is_dir($d))return; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f){ $f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname()); } rmdir($d);} 
rrmdir($work); mkdir($work); copy($docx, "$work/src.zip");
$z=new ZipArchive(); $z->open("$work/src.zip"); $z->extractTo("$work/u"); $z->close();
$p="$work/u/word/document.xml"; $xml=file_get_contents($p);

// title: contiguous or split 7. 成员管理 + （）
if (strpos($xml, '7. 成员管理') !== false) {
  $xml = str_replace('7. 成员管理', '7. 成员管理（事后同步，非开测前置）', $xml);
  // remove following empty （） run if present as its own t
  $xml = str_replace('>（）</w:t>', '></w:t>', $xml);
  echo "title ok\n";
} else { echo "title miss\n"; }

$to = '正式测评不要求事先导入人员名单。成员在小程序扫码作答时选择单位并填写姓名；提交后系统将人员与答卷同步入库，所属班组决定成绩归入哪个班组。PC 端可事后查看、补全或维护成员信息。';
if (strpos($xml, '维护班组成员基础信息') !== false) {
  $xml = str_replace('维护班组成员基础信息', $to, $xml);
  echo "bodyA ok\n";
}
if (strpos($xml, '所属班组决定成绩归入哪个班组；状态可区分在岗/停用等。') !== false) {
  $xml = str_replace('所属班组决定成绩归入哪个班组；状态可区分在岗/停用等。', '', $xml);
  echo "bodyB ok\n";
}
// remove orphan period run ONLY when it is a single-char t between our new text close and empty following - use very tight pattern
$xml = str_replace(
  '</w:t></w:r><w:r><w:rPr><w:rFonts w:hint="eastAsia" w:ascii="宋体" w:hAnsi="宋体" w:eastAsia="宋体"/><w:sz w:val="21"/><w:szCs w:val="21"/><w:lang w:val="en-US" w:eastAsia="zh-CN"/></w:rPr><w:t>.</w:t></w:r><w:r><w:rPr><w:rFonts w:ascii="宋体" w:hAnsi="宋体" w:eastAsia="宋体"/><w:sz w:val="21"/><w:szCs w:val="21"/></w:rPr><w:t></w:t>',
  '</w:t></w:r><w:r><w:rPr><w:rFonts w:ascii="宋体" w:hAnsi="宋体" w:eastAsia="宋体"/><w:sz w:val="21"/><w:szCs w:val="21"/></w:rPr><w:t></w:t>',
  $xml
);

file_put_contents($p, $xml);
// refresh media
foreach (['02-modules.png','03-flow.png','05-boundary.png','06-sequence.png'] as $n) {
  $src = $root . "/docs/demo-diagrams/$n";
  $dst = "$work/u/word/media/$n";
  if (is_file($src) && is_file($dst)) copy($src, $dst);
}
$zo=new ZipArchive(); $zo->open("$work/out.docx", ZipArchive::CREATE);
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$work/u", FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) { if($f->isFile()){ $full=$f->getPathname(); $local=str_replace("\\","/", substr($full, strlen("$work/u")+1)); $zo->addFile($full,$local);} }
$zo->close();
copy("$work/out.docx", $docx);
echo "bytes=".filesize($docx)."\n";
