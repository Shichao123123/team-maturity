<?php
/**
 * 联调：小程序自助登记（login → batchTeams → enterBatch）
 * 用法：php scripts/test-mini-self-register.php [baseUrl]
 */
$base = isset($argv[1]) ? rtrim($argv[1], '/') : 'http://127.0.0.1:8088';

$p = new PDO('mysql:host=127.0.0.1;dbname=team_maturity;charset=utf8mb4', 'root', '123456');
$team = $p->query("SELECT id, name FROM tm_team WHERE deleted_at IS NULL AND status=1 ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$team) {
    fwrite(STDERR, "无可用班组，请先维护组织/班组\n");
    exit(1);
}
$ver = $p->query("SELECT id FROM tm_indicator_version ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$versionId = $ver ? (int)$ver['id'] : 0;
if ($versionId <= 0) {
    // 批次需要 version_id；若无版本则插一条占位
    $p->exec("INSERT INTO tm_indicator_version (name, version_no, status, created_at) VALUES ('联调占位','V0-test',1,NOW())");
    $versionId = (int)$p->lastInsertId();
}

// 仅进行中批次可登记（须已开测）；没有则新建一条进行中批次
$batch = $p->query("SELECT id FROM tm_batch WHERE deleted_at IS NULL AND status=1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$batch) {
    $p->prepare("INSERT INTO tm_batch (name, version_id, start_time, end_time, status, created_at) VALUES (?,?,?,?,1,NOW())")
        ->execute(['联调自助登记批次', $versionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 86400 * 7)]);
    $batchId = (int)$p->lastInsertId();
} else {
    $batchId = (int)$batch['id'];
}

$bind = $p->prepare("SELECT id FROM tm_batch_team WHERE batch_id=? AND team_id=?");
$bind->execute([$batchId, $team['id']]);
if (!$bind->fetch()) {
    $p->prepare("INSERT INTO tm_batch_team (batch_id, team_id, member_total, submitted_cnt, created_at) VALUES (?,?,0,0,NOW())")
        ->execute([$batchId, $team['id']]);
}

function httpJson($method, $url, $body = null, $headers = [])
{
    $ch = curl_init($url);
    $hdrs = array_merge(['Content-Type: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $hdrs,
        CURLOPT_TIMEOUT        => 15,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($raw, true);
    return [$code, $json, $raw];
}

// ThinkPHP 默认接收 form；用 x-www-form-urlencoded 更稳
function httpForm($method, $url, $fields = [], $headers = [])
{
    $ch = curl_init($url);
    $hdrs = $headers;
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $hdrs,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($raw, true), $raw];
}

echo "== base {$base}\n";
echo "== batch_id={$batchId} team_id={$team['id']} ({$team['name']})\n";

list($c1, $j1, $r1) = httpForm('POST', $base . '/TmMinilogin/login', ['code' => 'test_user_' . time()]);
echo "login http={$c1} code=" . (isset($j1['code']) ? $j1['code'] : '?') . " msg=" . (isset($j1['msg']) ? $j1['msg'] : $r1) . "\n";
if (empty($j1['data']['token'])) {
    fwrite(STDERR, "login failed\n{$r1}\n");
    exit(1);
}
$token = $j1['data']['token'];

list($c2, $j2, $r2) = httpForm('GET', $base . '/TmMini/batchTeams?batch_id=' . $batchId, [], ['token: ' . $token]);
// GET with CURLOPT_POSTFIELDS may break; use plain GET
$ch = curl_init($base . '/TmMini/batchTeams?batch_id=' . $batchId);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['token: ' . $token],
    CURLOPT_TIMEOUT        => 15,
]);
$r2 = curl_exec($ch);
$c2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$j2 = json_decode($r2, true);
echo "batchTeams http={$c2} teams=" . (isset($j2['data']['teams']) ? count($j2['data']['teams']) : 0) . "\n";

$name = '自助登记' . substr((string)time(), -4);
list($c3, $j3, $r3) = httpForm('POST', $base . '/TmMini/enterBatch', [
    'batch_id' => $batchId,
    'team_id'  => $team['id'],
    'name'     => $name,
], ['token: ' . $token]);
echo "enterBatch http={$c3} code=" . (isset($j3['code']) ? $j3['code'] : '?') . " msg=" . (isset($j3['msg']) ? $j3['msg'] : '') . "\n";
if (empty($j3['data']['member_id'])) {
    fwrite(STDERR, "enterBatch failed\n{$r3}\n");
    exit(1);
}
$memberId = (int)$j3['data']['member_id'];
$row = $p->query("SELECT id, team_id, name, mobile, wx_user_id FROM tm_member WHERE id={$memberId}")->fetch(PDO::FETCH_ASSOC);
echo "member=" . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
echo "OK\n";
