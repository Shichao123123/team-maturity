<?php
// PHP 内置服务器路由：
// D:\phpstudy_pro\Extensions\php\php7.3.4nts\php.exe -S 127.0.0.1:8088 -t public public/router.php
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = __DIR__ . DIRECTORY_SEPARATOR . 'index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['PATH_INFO'] = ($uri === '/' || $uri === '') ? '/Login' : $uri;

require __DIR__ . '/index.php';
