<?php
/**
 * 联调批次闭环：创建 → 绑班组 → 开测 → 登记 → 算分
 */
define('APP_PATH', dirname(__DIR__) . '/backend/application/');
define('RUNTIME_PATH', dirname(__DIR__) . '/backend/runtime/');
require dirname(__DIR__) . '/backend/thinkphp/base.php';
\think\Loader::addNamespace('app', APP_PATH);
require APP_PATH . 'common.php';
\think\Config::set(include APP_PATH . 'database.php', 'database');

use app\index\service\BatchService;
use app\index\service\MemberSelfRegisterService;
use think\Db;

$ver = Db::name('tm_indicator_version')->where('status', 1)->order('id desc')->find();
if (!$ver) {
    fwrite(STDERR, "no published version\n");
    exit(1);
}
$team = Db::name('tm_team')->whereNull('deleted_at')->where('status', 1)->order('id asc')->find();
if (!$team) {
    fwrite(STDERR, "no team\n");
    exit(1);
}

list($ok, $msg, $batchId) = BatchService::create([
    'name'       => '联调批次' . date('His'),
    'version_id' => (int)$ver['id'],
    'start_time' => date('Y-m-d H:i:s', time() - 3600),
    'end_time'   => date('Y-m-d H:i:s', time() + 86400 * 7),
], 1);
echo "create: ok=" . ($ok ? 1 : 0) . " msg={$msg} id={$batchId}\n";
if (!$ok) {
    exit(1);
}

list($ok, $msg) = BatchService::bindTeams($batchId, [(int)$team['id']]);
echo "bind: ok=" . ($ok ? 1 : 0) . " msg={$msg}\n";
if (!$ok) {
    exit(1);
}

// 未开测不可登记
list($can, $denyMsg) = MemberSelfRegisterService::assertBatchTeam($batchId, (int)$team['id']);
echo "enter_before_start: ok=" . ($can ? 1 : 0) . " msg={$denyMsg}\n";
if ($can) {
    fwrite(STDERR, "expected deny before start\n");
    exit(1);
}

list($ok, $msg, $data) = BatchService::start($batchId);
echo "start: ok=" . ($ok ? 1 : 0) . " msg={$msg} q=" . (isset($data['question_cnt']) ? $data['question_cnt'] : 0) . "\n";
if (!$ok) {
    exit(1);
}

$snap = Db::name('tm_batch_indicator_snap')->where('batch_id', $batchId)->find();
echo "snap=" . ($snap ? 'yes' : 'no') . "\n";

list($can, $okMsg) = MemberSelfRegisterService::assertBatchTeam($batchId, (int)$team['id']);
echo "enter_after_start: ok=" . ($can ? 1 : 0) . " msg={$okMsg}\n";
if (!$can) {
    exit(1);
}

// 自助登记
$wxId = (int)Db::name('tm_wx_user')->insertGetId([
    'open_id'    => 'batch_test_' . time(),
    'created_at' => tmNow(),
]);
$member = MemberSelfRegisterService::ensureMember($wxId, (int)$team['id'], '批次联调员');
echo "member_id={$member['id']}\n";

// 写入已提交答卷供算分
$paperId = (int)Db::name('tm_paper')->insertGetId([
    'batch_id'       => $batchId,
    'team_id'        => (int)$team['id'],
    'member_id'      => (int)$member['id'],
    'wx_user_id'     => $wxId,
    'status'         => 1,
    'personal_score' => 85.5,
    'submitted_at'   => tmNow(),
    'created_at'     => tmNow(),
]);
$q = Db::name('tm_question')->where('status', 1)->order('id asc')->limit(2)->select();
foreach ($q as $row) {
    $opt = Db::name('tm_option')->where('question_id', $row['id'])->order('sort_no asc')->find();
    if (!$opt) {
        continue;
    }
    Db::name('tm_paper_answer')->insert([
        'paper_id'     => $paperId,
        'question_id'  => (int)$row['id'],
        'option_id'    => (int)$opt['id'],
        'indicator_id' => (int)$row['indicator_id'],
        'weight'       => 2.5,
        'score_ratio'  => (float)$opt['score_ratio'],
        'item_score'   => round(2.5 * (float)$opt['score_ratio'], 4),
        'answered_at'  => tmNow(),
    ]);
}

list($ok, $msg, $scoreData) = BatchService::score($batchId);
echo "score: ok=" . ($ok ? 1 : 0) . " msg={$msg}\n";
if (!$ok) {
    exit(1);
}
$tr = Db::name('tm_team_result')->where('batch_id', $batchId)->where('team_id', (int)$team['id'])->find();
echo "team_result score={$tr['team_score']} level={$tr['level_code']} submit={$tr['submit_cnt']}\n";

list($ok, $msg) = BatchService::finish($batchId);
echo "finish: ok=" . ($ok ? 1 : 0) . " msg={$msg}\n";

$detail = BatchService::detail($batchId);
echo "detail status={$detail['status']} progress_teams=" . count($detail['progress']) . "\n";
echo "OK batch_id={$batchId}\n";
