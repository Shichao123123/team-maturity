<?php
$docx = dirname(__DIR__) . "/docs/客户演示-系统功能与方案说明.docx";
$work = dirname(__DIR__) . "/scripts/_docx_fix_paren";
function rrmdir($d){ if(!is_dir($d))return; $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f){ $f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname()); } rmdir($d);} 
rrmdir($work); mkdir($work); copy($docx, "$work/src.zip");
$z=new ZipArchive(); $z->open("$work/src.zip"); $z->extractTo("$work/u"); $z->close();
$p="$work/u/word/document.xml"; $xml=file_get_contents($p);
$n=0;
$xml=str_replace("成员管理（事后同步，非开测前置）（）", "成员管理（事后同步，非开测前置）", $xml, $n);
// also clear empty （） run if still separate
$xml=str_replace(">（）</w:t>", "></w:t>", $xml);
file_put_contents($p,$xml);
echo "n=$n\n";
$zo=new ZipArchive(); $zo->open("$work/out.docx", ZipArchive::CREATE);
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$work/u", FilesystemIterator::SKIP_DOTS));
foreach($it as $f){ if($f->isFile()){ $full=$f->getPathname(); $local=str_replace("\\","/", substr($full, strlen("$work/u")+1)); $zo->addFile($full,$local);} }
$zo->close(); copy("$work/out.docx", $docx); echo "OK\n";
