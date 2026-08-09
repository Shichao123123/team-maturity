<?php
/**
 * 联调小程序答卷闭环：开测快照 → startPaper → answer → submit → myResult
 */
define('APP_PATH', dirname(__DIR__) . '/backend/application/');
define('RUNTIME_PATH', dirname(__DIR__) . '/backend/runtime/');
require dirname(__DIR__) . '/backend/thinkphp/base.php';
\think\Loader::addNamespace('app', APP_PATH);
require APP_PATH . 'common.php';
\think\Config::set(include APP_PATH . 'database.php', 'database');

use app\index\service\BatchService;
use app\index\service\MiniPaperService;
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
    'name'       => '小程序答卷联调' . date('His'),
    'version_id' => (int)$ver['id'],
    'start_time' => date('Y-m-d H:i:s', time() - 3600),
    'end_time'   => date('Y-m-d H:i:s', time() + 86400 * 7),
], 1);
echo "create: ok=" . ($ok ? 1 : 0) . " msg={$msg} id={$batchId}\n";
if (!$ok) {
    exit(1);
}

list($ok, $msg) = BatchService::bindTeams($batchId, [(int)$team['id']]);
if (!$ok && strpos($msg, '重叠') !== false) {
    // 释放联调残留的进行中批次，避免时段冲突
    $busyIds = Db::name('tm_batch_team')->alias('bt')
        ->join('tm_batch b', 'b.id = bt.batch_id')
        ->where('bt.team_id', (int)$team['id'])
        ->where('b.status', 'in', [0, 1])
        ->whereNull('b.deleted_at')
        ->where('b.id', '<>', $batchId)
        ->column('b.id');
    foreach ($busyIds as $bid) {
        BatchService::finish((int)$bid, false);
        echo "finish_busy batch_id={$bid}\n";
    }
    list($ok, $msg) = BatchService::bindTeams($batchId, [(int)$team['id']]);
}
echo "bind: ok=" . ($ok ? 1 : 0) . " msg={$msg}\n";
if (!$ok) {
    exit(1);
}

list($ok, $msg, $startData) = BatchService::start($batchId);
echo "start: ok=" . ($ok ? 1 : 0) . " msg={$msg} q=" . (isset($startData['question_cnt']) ? $startData['question_cnt'] : 0) . "\n";
if (!$ok) {
    exit(1);
}

$wxId = (int)Db::name('tm_wx_user')->insertGetId([
    'open_id'    => 'mini_paper_' . time(),
    'nickname'   => '联调',
    'created_at' => tmNow(),
]);
$name = '答卷联调员' . substr((string)time(), -4);

list($ok, $msg, $data, $code) = MiniPaperService::startPaper($wxId, $batchId, (int)$team['id'], $name);
echo "startPaper: ok=" . ($ok ? 1 : 0) . " code={$code} msg={$msg} paper=" . (isset($data['paper_id']) ? $data['paper_id'] : 0)
    . " total=" . (isset($data['total']) ? $data['total'] : 0) . "\n";
if (!$ok || empty($data['questions'])) {
    exit(1);
}
// 安全：前端不应拿到权重
$sampleOpt = $data['questions'][0]['options'][0];
if (isset($sampleOpt['score_ratio']) || isset($data['questions'][0]['weight'])) {
    fwrite(STDERR, "leak weight/score_ratio to client\n");
    exit(1);
}

$paperId = (int)$data['paper_id'];
$questions = $data['questions'];

// 未答完不可提交
list($ok, $msg, $subData, $code) = MiniPaperService::submit($wxId, $paperId);
echo "submit_incomplete: ok=" . ($ok ? 1 : 0) . " code={$code} msg={$msg}\n";
if ($ok) {
    fwrite(STDERR, "expected reject incomplete submit\n");
    exit(1);
}

foreach ($questions as $q) {
    $optId = (int)$q['options'][0]['option_id'];
    list($ok, $msg, $ans, $code) = MiniPaperService::answer($wxId, $paperId, (int)$q['question_id'], $optId);
    if (!$ok) {
        fwrite(STDERR, "answer fail q={$q['question_id']} msg={$msg}\n");
        exit(1);
    }
}
echo "answered={$ans['answered']}/{$ans['total']}\n";

list($ok, $msg, $sub, $code) = MiniPaperService::submit($wxId, $paperId);
echo "submit: ok=" . ($ok ? 1 : 0) . " code={$code} score=" . (isset($sub['personal_score']) ? $sub['personal_score'] : '?')
    . " team=" . (isset($sub['team']['team_name']) ? $sub['team']['team_name'] : '') . "\n";
if (!$ok || $code !== 200) {
    exit(1);
}

list($ok, $msg, $again, $code) = MiniPaperService::submit($wxId, $paperId);
echo "submit_idempotent: ok=" . ($ok ? 1 : 0) . " code={$code}\n";
if (!$ok || $code !== 202) {
    fwrite(STDERR, "expected 202 on resubmit\n");
    exit(1);
}

list($ok, $msg, $ans2, $code) = MiniPaperService::answer(
    $wxId,
    $paperId,
    (int)$questions[0]['question_id'],
    (int)$questions[0]['options'][1]['option_id']
);
echo "answer_after_submit: ok=" . ($ok ? 1 : 0) . " code={$code}\n";
if ($ok || $code !== 202) {
    fwrite(STDERR, "expected 202 locked answer\n");
    exit(1);
}

list($ok, $msg, $result, $code) = MiniPaperService::myResult($wxId, $batchId);
echo "myResult: ok=" . ($ok ? 1 : 0) . " score=" . (isset($result['personal_score']) ? $result['personal_score'] : '?') . "\n";
if (!$ok) {
    exit(1);
}

list($ok, $msg, $batches, $code) = MiniPaperService::myBatches($wxId);
echo "myBatches: count=" . count($batches) . "\n";

$cnt = (int)Db::name('tm_batch_team')->where('batch_id', $batchId)->where('team_id', (int)$team['id'])->value('submitted_cnt');
echo "batch_team.submitted_cnt={$cnt}\n";
if ($cnt < 1) {
    fwrite(STDERR, "submitted_cnt not increased\n");
    exit(1);
}

echo "OK batch_id={$batchId} paper_id={$paperId} wx_user_id={$wxId}\n";
