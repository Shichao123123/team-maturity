<?php
define('APP_PATH', dirname(__DIR__) . '/backend/application/');
define('RUNTIME_PATH', dirname(__DIR__) . '/backend/runtime/');
require dirname(__DIR__) . '/backend/thinkphp/base.php';
\think\Loader::addNamespace('app', APP_PATH);
require APP_PATH . 'common.php';
\think\Config::set(include APP_PATH . 'database.php', 'database');

use app\index\service\QuestionPickService;
use think\Db;

$vid = Db::name('tm_indicator_version')->where('version_no', 'V1.0.0')->value('id');
$col = Db::query("SHOW COLUMNS FROM tm_indicator_version LIKE 'question_pick_mode'");
echo 'has_col=' . (empty($col) ? 'NO' : 'YES') . PHP_EOL;
echo 'version_mode=' . Db::name('tm_indicator_version')->where('id', $vid)->value('question_pick_mode') . PHP_EOL;

$set = QuestionPickService::buildExamSet($vid);
echo 'mode=' . $set['pick_mode'] . ' items=' . count($set['items']) . PHP_EOL;
$multi = 0;
foreach ($set['items'] as $it) {
    if ($it['candidate_cnt'] > 1) {
        $multi++;
    }
}
echo 'multi_pool_indicators=' . $multi . PHP_EOL;
$errs = QuestionPickService::validateVersionQuestions($vid);
echo 'validate_errors=' . count($errs) . PHP_EOL;
echo "OK\n";
