<?php
define('APP_PATH', dirname(__DIR__) . '/backend/application/');
define('RUNTIME_PATH', dirname(__DIR__) . '/backend/runtime/');
require dirname(__DIR__) . '/backend/thinkphp/base.php';
\think\Loader::addNamespace('app', APP_PATH);
require APP_PATH . 'common.php';
\think\Config::set(include APP_PATH . 'database.php', 'database');

use app\index\service\LevelJudgeService;

foreach ([69.99, 70, 80, 90, 90.01, 95] as $s) {
    $r = LevelJudgeService::judge($s);
    echo $s . ' => ' . ($r ? $r['level_name'] : 'null') . PHP_EOL;
}
