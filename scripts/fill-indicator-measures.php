<?php
/**
 * 每个三级指标补齐四个等级措施：已有内容保留，缺失填「暂无」。
 * 用法：php scripts/fill-indicator-measures.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$projectRoot = dirname(__DIR__);
$backend = $projectRoot . DIRECTORY_SEPARATOR . 'backend';

define('APP_PATH', $backend . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
define('RUNTIME_PATH', $backend . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR);
require $backend . '/thinkphp/base.php';
\think\Loader::addNamespace('app', APP_PATH);
require APP_PATH . 'common.php';
\think\Config::set(include APP_PATH . 'database.php', 'database');

use think\Db;

$levels = ['passive', 'dependent', 'independent', 'essential'];
$now = date('Y-m-d H:i:s');

$indicators = Db::name('tm_indicator')
    ->where('level_no', 3)
    ->where('status', 1)
    ->field('id,name,sort_no')
    ->order('id asc')
    ->select();

$inserted = 0;
$kept = 0;

Db::startTrans();
try {
    foreach ($indicators as $ind) {
        $iid = (int)$ind['id'];
        $title = mb_substr($ind['name'], 0, 120);
        $existing = Db::name('tm_empower_measure')
            ->where('indicator_id', $iid)
            ->column('id', 'level_code');

        foreach ($levels as $li => $code) {
            if (isset($existing[$code])) {
                $kept++;
                continue;
            }
            Db::name('tm_empower_measure')->insert([
                'level_code'   => $code,
                'indicator_id' => $iid,
                'title'        => $title,
                'content'      => '暂无',
                'sort_no'      => (int)$ind['sort_no'] * 10 + ($li + 1),
                'status'       => 1,
                'created_at'   => $now,
            ]);
            $inserted++;
        }
    }
    Db::commit();
} catch (\Exception $e) {
    Db::rollback();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$byLevel = Db::query(
    "SELECT level_code, COUNT(*) AS c FROM tm_empower_measure WHERE indicator_id IS NOT NULL GROUP BY level_code"
);
$withContent = (int)Db::name('tm_empower_measure')
    ->where('indicator_id', '>', 0)
    ->where('content', '<>', '暂无')
    ->count();

echo "indicators=" . count($indicators) . " kept={$kept} inserted={$inserted}\n";
echo "with_real_content={$withContent}\n";
echo "by_level=" . json_encode($byLevel, JSON_UNESCAPED_UNICODE) . "\n";
echo "ALL_DONE\n";
