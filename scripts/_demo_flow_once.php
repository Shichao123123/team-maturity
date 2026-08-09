<?php
/**
 * 一次性演示链路：找空闲班组 → 建批次 → 绑定 → 开测 → 生成小程序码
 */
define('APP_PATH', dirname(__DIR__) . '/backend/application/');
define('RUNTIME_PATH', dirname(__DIR__) . '/backend/runtime/');
require dirname(__DIR__) . '/backend/thinkphp/base.php';
\think\Loader::addNamespace('app', APP_PATH);
require APP_PATH . 'common.php';
\think\Config::set(include APP_PATH . 'database.php', 'database');
$localFile = APP_PATH . 'config.local.php';
if (is_file($localFile)) {
    $local = include $localFile;
    if (is_array($local)) {
        \think\Config::set([
            'mini' => array_merge((array)\think\Config::get('mini'), [
                'wx_appid'    => isset($local['wx_appid']) ? $local['wx_appid'] : '',
                'wx_secret'   => isset($local['wx_secret']) ? $local['wx_secret'] : '',
                'dev_login'   => !empty($local['dev_login']),
                'qr_path'     => 'pages/exam/index',
                'wxacode_env' => isset($local['wxacode_env']) ? $local['wxacode_env'] : 'develop',
            ]),
        ]);
    }
}

use app\index\service\BatchService;
use app\index\service\WxMiniService;
use think\Db;

$busy = Db::name('tm_batch')->where('status', 'in', [0, 1])->whereNull('deleted_at')->field('id,name,status')->select();
echo 'open_batches=' . count($busy) . "\n";
foreach ($busy as $b) {
    echo "  #{$b['id']} {$b['name']} status={$b['status']}\n";
}

$ver = Db::name('tm_indicator_version')->where('status', 1)->order('id desc')->find();
if (!$ver) {
    fwrite(STDERR, "no published version\n");
    exit(1);
}
echo "published_ver=#{$ver['id']} {$ver['name']}\n";
$drafts = Db::name('tm_indicator_version')->where('status', 0)->count();
$pubs = Db::name('tm_indicator_version')->where('status', 1)->count();
$offs = Db::name('tm_indicator_version')->where('status', 2)->count();
echo "versions draft=$drafts published=$pubs disabled=$offs\n";

$used = Db::name('tm_batch_team')->alias('bt')
    ->join('tm_batch b', 'b.id=bt.batch_id')
    ->where('b.status', 'in', [0, 1])
    ->whereNull('b.deleted_at')
    ->column('bt.team_id');
$used = array_unique(array_map('intval', $used ?: []));
$q = Db::name('tm_team')->whereNull('deleted_at')->where('status', 1)->order('id asc');
if ($used) {
    $q->where('id', 'not in', $used);
}
$team = $q->find();
if (!$team) {
    echo "NO_FREE_TEAM\n";
    exit(1);
}
echo "free_team=#{$team['id']} {$team['name']}\n";

list($ok, $msg, $bid) = BatchService::create([
    'name'       => '演示链路' . date('mdHi'),
    'version_id' => (int)$ver['id'],
    'start_time' => date('Y-m-d H:i:s', time() - 600),
    'end_time'   => date('Y-m-d H:i:s', time() + 86400 * 3),
], 1);
echo 'create ok=' . ($ok ? 1 : 0) . " $msg id=$bid\n";
if (!$ok) {
    exit(1);
}
list($ok, $msg) = BatchService::bindTeams($bid, [(int)$team['id']]);
echo 'bind ok=' . ($ok ? 1 : 0) . " $msg\n";
if (!$ok) {
    exit(1);
}
list($ok, $msg, $startData) = BatchService::start($bid);
$qcnt = (is_array($startData) && isset($startData['question_cnt'])) ? $startData['question_cnt'] : 0;
echo 'start ok=' . ($ok ? 1 : 0) . " $msg q=$qcnt\n";
if (!$ok) {
    exit(1);
}

list($ok, $bin, $meta) = WxMiniService::getBatchWxacode($bid, true, 280);
echo 'wxacode ok=' . ($ok ? 1 : 0) . ' ';
if ($ok) {
    echo 'bytes=' . strlen($bin) . ' env=' . $meta['env_version'] . "\n";
} else {
    echo $bin . "\n";
}
echo "DEMO_BATCH_ID=$bid\n";
echo "MINI_PATH=pages/exam/index?batch_id=$bid\n";
echo "OK_LOCAL_DEMO_PC\n";
