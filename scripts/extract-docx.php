<?php
$src = $argv[1] ?? '';
$out = $argv[2] ?? '';
if ($src === '' || $out === '' || !is_file($src)) {
    fwrite(STDERR, "usage: php extract-docx.php <docx> <out.txt>\n");
    exit(1);
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tm_docx_' . md5($src . microtime(true));
mkdir($tmp);
$zipPath = $tmp . DIRECTORY_SEPARATOR . 'q.zip';
copy($src, $zipPath);

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    fwrite(STDERR, "cannot open docx zip\n");
    exit(2);
}
$zip->extractTo($tmp . DIRECTORY_SEPARATOR . 'unz');
$zip->close();

$xmlPath = $tmp . DIRECTORY_SEPARATOR . 'unz' . DIRECTORY_SEPARATOR . 'word' . DIRECTORY_SEPARATOR . 'document.xml';
if (!is_file($xmlPath)) {
    fwrite(STDERR, "document.xml missing\n");
    exit(3);
}

$xml = file_get_contents($xmlPath);
$xml = preg_replace('/<\/w:p>/', "\n", $xml);
$xml = preg_replace('/<w:tab[^\\/]*\\/>/', "\t", $xml);
$text = strip_tags($xml);
$text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
$text = preg_replace("/\r\n/", "\n", $text);
$text = preg_replace("/\n{3,}/", "\n\n", $text);
file_put_contents($out, $text);
echo 'OK LEN=' . strlen($text) . ' LINES=' . substr_count($text, "\n") . PHP_EOL;
