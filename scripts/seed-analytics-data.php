<?php
/**
 * 为数据分析/看板制造大量演示数据（组织、班组、成员、多期已算分批次、答卷、逐题作答与班组结果）。
 *
 * 用法：
 *   D:\phpstudy_pro\Extensions\php\php7.3.4nts\php.exe scripts\seed-analytics-data.php
 *   ... seed-analytics-data.php --reset   # 仅清理上次本脚本写入的数据
 *
 * 标记约定（便于幂等清理）：
 *   - 组织/班组名以「」开头
 *   - 批次名以「【演示数据】」开头
 *   - 成员手机号以 1398 开头（脚本专用号段）
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(0);

$projectRoot = dirname(__DIR__);
$backend = $projectRoot . DIRECTORY_SEPARATOR . 'backend';

define('APP_PATH', $backend . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
define('RUNTIME_PATH', $backend . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR);
require $backend . '/thinkphp/base.php';
\think\Loader::addNamespace('app', APP_PATH);
require APP_PATH . 'common.php';
\think\Config::set(include APP_PATH . 'database.php', 'database');

use app\index\service\LevelJudgeService;
use app\index\service\QuestionPickService;
use think\Db;

$argvList = isset($argv) ? $argv : [];
$resetOnly = in_array('--reset', $argvList, true);

const SEED_ORG_PREFIX = '';
const SEED_BATCH_PREFIX = '【演示数据】';
const SEED_MOBILE_PREFIX = '1398';

function seedLog($msg)
{
    echo $msg . PHP_EOL;
}

function seedCleanup()
{
    $batchIds = Db::name('tm_batch')
        ->where('name', 'like', SEED_BATCH_PREFIX . '%')
        ->column('id');
    $batchIds = array_map('intval', $batchIds ?: []);

    if ($batchIds) {
        $idList = implode(',', $batchIds);
        Db::name('tm_team_result')->where('batch_id', 'in', $batchIds)->delete();
        try {
            Db::execute("DELETE a FROM tm_paper_answer a INNER JOIN tm_paper p ON p.id = a.paper_id WHERE p.batch_id IN ({$idList})");
        } catch (\Exception $e) {
            $paperIds = Db::name('tm_paper')->where('batch_id', 'in', $batchIds)->column('id');
            if ($paperIds) {
                foreach (array_chunk($paperIds, 500) as $chunk) {
                    Db::name('tm_paper_answer')->where('paper_id', 'in', $chunk)->delete();
                }
            }
        }
        Db::name('tm_paper')->where('batch_id', 'in', $batchIds)->delete();
        Db::name('tm_batch_team')->where('batch_id', 'in', $batchIds)->delete();
        try {
            Db::name('tm_batch_indicator_snap')->where('batch_id', 'in', $batchIds)->delete();
        } catch (\Exception $e) {
            // ignore
        }
        Db::name('tm_batch')->where('id', 'in', $batchIds)->delete();
        seedLog('已清理演示批次: ' . count($batchIds));
    }

    $teamIds = Db::name('tm_team')
        ->where('name', 'like', SEED_ORG_PREFIX . '%')
        ->column('id');
    $teamIds = array_map('intval', $teamIds ?: []);
    if ($teamIds) {
        Db::name('tm_member')->where('team_id', 'in', $teamIds)->delete();
        Db::name('tm_team')->where('id', 'in', $teamIds)->update([
            'deleted_at' => tmNow(),
            'status'     => 0,
            'updated_at' => tmNow(),
        ]);
        seedLog('已清理演示班组: ' . count($teamIds));
    }

    $mCnt = Db::name('tm_member')
        ->where('mobile', 'like', SEED_MOBILE_PREFIX . '%')
        ->delete();
    if ($mCnt) {
        seedLog('已清理演示号段成员: ' . $mCnt);
    }

    $orgIds = Db::name('tm_org')
        ->where('name', 'like', SEED_ORG_PREFIX . '%')
        ->column('id');
    $orgIds = array_map('intval', $orgIds ?: []);
    if ($orgIds) {
        Db::name('tm_org')->where('id', 'in', $orgIds)->update([
            'deleted_at' => tmNow(),
            'status'     => 0,
            'updated_at' => tmNow(),
        ]);
        seedLog('已清理演示组织: ' . count($orgIds));
    }
}

/** 确定性伪随机 0~1 */
function seedRand($seed)
{
    $x = sin($seed * 12.9898) * 43758.5453;
    return $x - floor($x);
}

function seedPickLevelCode($score)
{
    $lv = LevelJudgeService::judge($score);
    return $lv ? $lv['level_code'] : 'passive';
}

function ensureOrg($name, $parentId, $orgType, $sortNo)
{
    $row = Db::name('tm_org')
        ->whereNull('deleted_at')
        ->where('name', $name)
        ->where('parent_id', (int)$parentId)
        ->find();
    if ($row) {
        return (int)$row['id'];
    }
    return (int)Db::name('tm_org')->insertGetId([
        'parent_id'  => (int)$parentId,
        'name'       => $name,
        'org_type'   => (int)$orgType,
        'sort_no'    => (int)$sortNo,
        'status'     => 1,
        'created_at' => tmNow(),
    ]);
}

function ensureTeam($orgId, $name)
{
    $row = Db::name('tm_team')
        ->whereNull('deleted_at')
        ->where('org_id', (int)$orgId)
        ->where('name', $name)
        ->find();
    if ($row) {
        return (int)$row['id'];
    }
    return (int)Db::name('tm_team')->insertGetId([
        'org_id'     => (int)$orgId,
        'name'       => $name,
        'status'     => 1,
        'created_at' => tmNow(),
    ]);
}

function ensureMembers($teamId, $count, &$mobileSeq)
{
    $exist = (int)Db::name('tm_member')
        ->whereNull('deleted_at')
        ->where('team_id', (int)$teamId)
        ->where('mobile', 'like', SEED_MOBILE_PREFIX . '%')
        ->count();
    $need = max(0, $count - $exist);
    $posts = ['班组长', '安全员', '操作工', '巡检工', '仪表工', '电工'];
    $roles = ['leader', 'member', 'member', 'member', 'member', 'member'];
    for ($i = 0; $i < $need; $i++) {
        $mobileSeq++;
        $mobile = SEED_MOBILE_PREFIX . str_pad((string)$mobileSeq, 7, '0', STR_PAD_LEFT);
        if (strlen($mobile) > 11) {
            $mobile = substr($mobile, 0, 11);
        }
        Db::name('tm_member')->insert([
            'team_id'    => (int)$teamId,
            'name'       => '演示员' . $mobileSeq,
            'mobile'     => $mobile,
            'post_name'  => $posts[$i % count($posts)],
            'role_code'  => $roles[$i % count($roles)],
            'status'     => 1,
            'created_at' => tmNow(),
        ]);
    }
}

/**
 * 按目标总分挑选逐题选项，使 item_score 合计接近 targetScore。
 *
 * @param array  $examItems QuestionPickService::buildExamSet 的 items
 * @param float  $targetScore
 * @param int    $seedBase
 * @param string $answeredAt
 * @return array [answerRows(不含 paper_id), actualScore]
 */
function seedBuildPaperAnswers(array $examItems, $targetScore, $seedBase, $answeredAt)
{
    $full = 0.0;
    foreach ($examItems as $item) {
        $full += (float)$item['indicator']['weight'];
    }
    if ($full <= 0) {
        $full = 100.0;
    }
    $targetRatio = max(0.05, min(1.0, (float)$targetScore / $full));

    $picks = [];
    foreach ($examItems as $qi => $item) {
        $opts = isset($item['options']) ? $item['options'] : [];
        if (!$opts) {
            continue;
        }
        $want = max(0.0, min(1.0, $targetRatio + (seedRand($seedBase + $qi * 17) - 0.5) * 0.4));
        $best = $opts[0];
        $bestDiff = abs((float)$best['score_ratio'] - $want);
        foreach ($opts as $o) {
            $d = abs((float)$o['score_ratio'] - $want);
            if ($d < $bestDiff - 1e-9) {
                $best = $o;
                $bestDiff = $d;
            }
        }
        // 少量随机扰动，避免全卷同一档
        if (seedRand($seedBase * 5 + $qi) < 0.18) {
            $best = $opts[(int)floor(seedRand($seedBase + $qi + 99) * count($opts)) % count($opts)];
        }
        $picks[] = [
            'item' => $item,
            'opt'  => $best,
            'qi'   => $qi,
        ];
    }

    // 若合计偏离目标较多，按差额微调若干题的选项
    $calcTotal = function ($picks) {
        $sum = 0.0;
        foreach ($picks as $p) {
            $sum += (float)$p['item']['indicator']['weight'] * (float)$p['opt']['score_ratio'];
        }
        return $sum;
    };
    $total = $calcTotal($picks);
    $diff = (float)$targetScore - $total;
    if (abs($diff) > 1.5 && $picks) {
        $nudgeN = min(count($picks), (int)ceil(abs($diff) / 2));
        for ($n = 0; $n < $nudgeN; $n++) {
            $idx = (int)floor(seedRand($seedBase + 1000 + $n) * count($picks)) % count($picks);
            $opts = $picks[$idx]['item']['options'];
            if (count($opts) < 2) {
                continue;
            }
            usort($opts, function ($a, $b) {
                return ((float)$a['score_ratio'] > (float)$b['score_ratio']) ? 1 : -1;
            });
            $cur = (float)$picks[$idx]['opt']['score_ratio'];
            $chosen = $picks[$idx]['opt'];
            if ($diff > 0) {
                foreach ($opts as $o) {
                    if ((float)$o['score_ratio'] > $cur + 1e-9) {
                        $chosen = $o;
                        break;
                    }
                }
                // 取更高档中最接近的一档
                foreach ($opts as $o) {
                    if ((float)$o['score_ratio'] > $cur + 1e-9) {
                        $chosen = $o;
                    }
                }
            } else {
                foreach ($opts as $o) {
                    if ((float)$o['score_ratio'] < $cur - 1e-9) {
                        $chosen = $o;
                        break;
                    }
                }
            }
            $oldScore = (float)$picks[$idx]['item']['indicator']['weight'] * $cur;
            $newScore = (float)$picks[$idx]['item']['indicator']['weight'] * (float)$chosen['score_ratio'];
            $picks[$idx]['opt'] = $chosen;
            $diff -= ($newScore - $oldScore);
            if (abs($diff) < 1.0) {
                break;
            }
        }
        $total = $calcTotal($picks);
    }

    $rows = [];
    foreach ($picks as $p) {
        $weight = round((float)$p['item']['indicator']['weight'], 4);
        $ratio = round((float)$p['opt']['score_ratio'], 4);
        $itemScore = round($weight * $ratio, 4);
        $rows[] = [
            'question_id'  => (int)$p['item']['question']['id'],
            'option_id'    => (int)$p['opt']['id'],
            'indicator_id' => (int)$p['item']['indicator']['id'],
            'weight'       => $weight,
            'score_ratio'  => $ratio,
            'item_score'   => $itemScore,
            'answered_at'  => $answeredAt,
        ];
    }

    return [$rows, round($total, 2)];
}

function seedInsertAnswerRows(array $rows)
{
    if (!$rows) {
        return;
    }
    foreach (array_chunk($rows, 300) as $chunk) {
        Db::name('tm_paper_answer')->insertAll($chunk);
    }
}

/**
 * 按班组已写入答卷汇总薄弱指标（得分率最低的若干项）。
 */
function seedBuildWeakFromPapers(array $paperIds, array $examItems, $teamScore)
{
    if (!$paperIds) {
        return [];
    }
    $rows = Db::name('tm_paper_answer')
        ->where('paper_id', 'in', $paperIds)
        ->field('indicator_id, AVG(item_score) as avg_score, AVG(weight) as full_score')
        ->group('indicator_id')
        ->select();
    $nameMap = [];
    foreach ($examItems as $it) {
        $nameMap[(int)$it['indicator']['id']] = $it['indicator']['name'];
    }
    $list = [];
    foreach ($rows as $r) {
        $full = (float)$r['full_score'];
        $avg = (float)$r['avg_score'];
        if ($full <= 0) {
            continue;
        }
        $list[] = [
            'indicator_id'   => (int)$r['indicator_id'],
            'indicator_name' => isset($nameMap[(int)$r['indicator_id']]) ? $nameMap[(int)$r['indicator_id']] : ('指标#' . $r['indicator_id']),
            'avg_score'      => round($avg, 2),
            'full_score'     => round($full, 2),
            'rate'           => $avg / $full,
            'measure'        => '加强现场培训与标准宣贯，完善班组台账与互保机制',
        ];
    }
    usort($list, function ($a, $b) {
        if ($a['rate'] == $b['rate']) {
            return 0;
        }
        return ($a['rate'] < $b['rate']) ? -1 : 1;
    });
    $weakCnt = $teamScore < 70 ? 5 : ($teamScore < 80 ? 4 : ($teamScore < 90 ? 3 : 2));
    $weak = array_slice($list, 0, $weakCnt);
    foreach ($weak as &$w) {
        unset($w['rate']);
    }
    unset($w);
    return $weak;
}

seedLog('=== 数据分析演示数据种子 ===');
Db::startTrans();
try {
    seedCleanup();
    if ($resetOnly) {
        Db::commit();
        seedLog('仅清理完成（--reset）');
        exit(0);
    }

    $ver = Db::name('tm_indicator_version')->where('status', 1)->order('id asc')->find();
    if (!$ver) {
        throw new \RuntimeException('没有已发布题库版本，请先发布版本');
    }
    $versionId = (int)$ver['id'];
    seedLog('使用题库版本: #' . $versionId . ' ' . $ver['name'] . ' / ' . $ver['version_no']);

    $errors = QuestionPickService::validateVersionQuestions($versionId);
    if ($errors) {
        throw new \RuntimeException('题库校验失败: ' . implode('；', $errors));
    }

    // 用 first 抽题，保证各演示批次题目一致，便于对照
    $examSet = QuestionPickService::buildExamSet($versionId, 'first');
    $examItems = isset($examSet['items']) ? $examSet['items'] : [];
    if (!$examItems) {
        throw new \RuntimeException('抽题结果为空');
    }
    seedLog('试卷题目数: ' . count($examItems));

    // —— 组织树 ——
    $rootId = (int)Db::name('tm_org')->whereNull('deleted_at')->where('name', '华安能源集团')->value('id');
    if ($rootId <= 0) {
        $rootId = ensureOrg('华安能源集团', 0, 1, 1);
    }
    $plant3 = ensureOrg(SEED_ORG_PREFIX . '第三炼化分厂', $rootId, 2, 30);
    $plant4 = ensureOrg(SEED_ORG_PREFIX . '动力公用分厂', $rootId, 2, 40);

    $ws = [];
    $ws[] = ensureOrg(SEED_ORG_PREFIX . '加氢精制工段', $plant3, 3, 1);
    $ws[] = ensureOrg(SEED_ORG_PREFIX . '芳烃工段', $plant3, 3, 2);
    $ws[] = ensureOrg(SEED_ORG_PREFIX . '聚烯烃工段', $plant3, 3, 3);
    $ws[] = ensureOrg(SEED_ORG_PREFIX . '热电工段', $plant4, 3, 1);
    $ws[] = ensureOrg(SEED_ORG_PREFIX . '循环水工段', $plant4, 3, 2);
    $ws[] = ensureOrg(SEED_ORG_PREFIX . '空分工段', $plant4, 3, 3);

    $existWs = Db::name('tm_org')
        ->whereNull('deleted_at')
        ->where('id', 'in', [5, 6, 7])
        ->column('id');
    foreach ($existWs as $eid) {
        $ws[] = (int)$eid;
    }
    $ws = array_values(array_unique($ws));

    $teamDefs = [
        [0, '甲班', 62.0, 3.2],
        [0, '乙班', 71.5, 2.4],
        [0, '丙班', 78.0, 1.8],
        [1, '甲班', 55.0, 4.0],
        [1, '乙班', 68.0, 2.8],
        [1, '夜班', 83.0, 1.2],
        [2, '一班', 74.0, 2.0],
        [2, '二班', 88.5, 0.9],
        [2, '三班', 91.0, 0.6],
        [3, '运行一班', 66.0, 2.6],
        [3, '运行二班', 79.5, 1.5],
        [3, '检修班', 85.0, 1.0],
        [4, '甲班', 58.0, 3.5],
        [4, '乙班', 72.0, 2.2],
        [5, '一班', 81.0, 1.4],
        [5, '二班', 93.5, 0.4],
        ['org5', '常减压丙班', 70.0, 2.5],
        ['org5', '常减压丁班', 86.0, 1.1],
        ['org6', '催化乙班', 64.0, 3.0],
        ['org6', '催化夜班', 89.0, 0.8],
        ['org7', '储运二班', 76.0, 1.9],
        ['org7', '储运三班', 95.0, 0.3],
    ];

    $mobileSeq = (int)date('ymd') * 10;
    $seedTeams = [];
    foreach ($teamDefs as $idx => $def) {
        list($orgKey, $suffix, $base, $growth) = $def;
        if ($orgKey === 'org5') {
            $orgId = 5;
            $name = $suffix;
        } elseif ($orgKey === 'org6') {
            $orgId = 6;
            $name = $suffix;
        } elseif ($orgKey === 'org7') {
            $orgId = 7;
            $name = $suffix;
        } else {
            $orgId = $ws[(int)$orgKey];
            $orgName = Db::name('tm_org')->where('id', $orgId)->value('name');
            $short = preg_replace('/^/', '', (string)$orgName);
            $name = SEED_ORG_PREFIX . $short . $suffix;
        }
        $teamId = ensureTeam($orgId, $name);
        $memberCnt = 6 + ($idx % 5);
        ensureMembers($teamId, $memberCnt, $mobileSeq);
        $seedTeams[$teamId] = [
            'id'     => $teamId,
            'name'   => $name,
            'org_id' => $orgId,
            'base'   => $base,
            'growth' => $growth,
            'noise'  => 1.5 + seedRand(100 + $idx) * 2.5,
        ];
    }

    $existTeams = Db::name('tm_team')
        ->whereNull('deleted_at')
        ->where('status', 1)
        ->where('id', 'in', [1, 2, 3, 4, 5])
        ->select();
    $existProfiles = [
        1 => [48.0, 5.5],
        2 => [73.0, 2.3],
        3 => [82.0, 1.6],
        4 => [67.0, 2.9],
        5 => [90.0, 0.7],
    ];
    foreach ($existTeams as $t) {
        $tid = (int)$t['id'];
        if (isset($seedTeams[$tid])) {
            continue;
        }
        $p = isset($existProfiles[$tid]) ? $existProfiles[$tid] : [70.0, 2.0];
        ensureMembers($tid, 5, $mobileSeq);
        $seedTeams[$tid] = [
            'id'     => $tid,
            'name'   => $t['name'],
            'org_id' => (int)$t['org_id'],
            'base'   => $p[0],
            'growth' => $p[1],
            'noise'  => 2.0,
        ];
    }

    seedLog('班组就绪: ' . count($seedTeams));

    $batchSpecs = [
        ['2024年度第四季度班组测评', '2024-10-08 08:00:00', '2024-10-31 18:00:00'],
        ['2025年度第一季度班组测评', '2025-01-10 08:00:00', '2025-01-31 18:00:00'],
        ['2025年度第二季度班组测评', '2025-04-08 08:00:00', '2025-04-30 18:00:00'],
        ['2025年度第三季度班组测评', '2025-07-07 08:00:00', '2025-07-31 18:00:00'],
        ['2025年度第四季度班组测评', '2025-10-09 08:00:00', '2025-10-31 18:00:00'],
        ['2026年度春季专项测评', '2026-03-05 08:00:00', '2026-03-25 18:00:00'],
        ['2026年度第二季度班组测评', '2026-04-10 08:00:00', '2026-04-30 18:00:00'],
        ['2026年度年中综合测评', '2026-07-01 08:00:00', '2026-07-20 18:00:00'],
    ];

    $teamIdsAll = array_keys($seedTeams);
    $createdBatchIds = [];
    $resultCnt = 0;
    $paperCnt = 0;
    $answerCnt = 0;

    foreach ($batchSpecs as $bIdx => $spec) {
        list($title, $start, $end) = $spec;
        $batchId = (int)Db::name('tm_batch')->insertGetId([
            'name'               => SEED_BATCH_PREFIX . $title,
            'version_id'         => $versionId,
            'question_pick_mode' => 'first',
            'start_time'         => $start,
            'end_time'           => $end,
            'status'             => 2,
            'score_status'       => 2,
            'created_by'         => 1,
            'created_at'         => $start,
        ]);
        $createdBatchIds[] = $batchId;

        // 写入开测快照，答卷详情页按快照补齐全部题目
        $examSet['picked_at'] = $start;
        Db::name('tm_batch_indicator_snap')->insert([
            'batch_id'          => $batchId,
            'source_version_id' => $versionId,
            'snap_json'         => json_encode($examSet, JSON_UNESCAPED_UNICODE),
            'created_at'        => $start,
        ]);

        $isLast = ($bIdx === count($batchSpecs) - 1);
        $scoredAt = $isLast ? tmNow() : date('Y-m-d H:i:s', strtotime($end) + 3600);
        $batchAnswerBuf = [];

        foreach ($teamIdsAll as $tOrd => $teamId) {
            $meta = $seedTeams[$teamId];
            $skipRoll = seedRand($batchId * 17 + $teamId * 3 + 9);
            if ($bIdx < 7 && $skipRoll < 0.12 && ($tOrd % 7 === $bIdx % 7)) {
                continue;
            }

            $members = Db::name('tm_member')
                ->whereNull('deleted_at')
                ->where('team_id', $teamId)
                ->where('status', 1)
                ->field('id,name')
                ->order('id asc')
                ->select();
            if (!$members) {
                continue;
            }

            $noise = (seedRand($batchId + $teamId * 13) - 0.5) * 2 * $meta['noise'];
            $score = $meta['base'] + $bIdx * $meta['growth'] + $noise;
            if ($bIdx === count($batchSpecs) - 1) {
                // 目标分略抬高：选项分档离散，实际总分会略低于目标
                $bucket = $tOrd % 4;
                if ($bucket === 0) {
                    $score = 48 + seedRand($teamId) * 18;      // → passive
                } elseif ($bucket === 1) {
                    $score = 72 + seedRand($teamId) * 7;       // → dependent
                } elseif ($bucket === 2) {
                    $score = 84 + seedRand($teamId) * 6;       // → independent
                } else {
                    $score = 96 + seedRand($teamId) * 3.5;     // → essential
                }
            }
            $score = round(max(35, min(99.5, $score)), 2);

            $memberTotal = count($members);
            $submitN = max(3, min($memberTotal, 3 + (int)floor(seedRand($teamId + $bIdx) * ($memberTotal - 2))));
            $submitMembers = array_slice($members, 0, $submitN);

            Db::name('tm_batch_team')->insert([
                'batch_id'      => $batchId,
                'team_id'       => $teamId,
                'member_total'  => $memberTotal,
                'submitted_cnt' => $submitN,
                'created_at'    => $start,
            ]);

            $personalScores = [];
            $paperIds = [];
            foreach ($submitMembers as $mi => $m) {
                $delta = (seedRand($batchId * 31 + $teamId * 7 + $mi) - 0.5) * 6;
                $targetPs = round(max(30, min(100, $score + $delta)), 2);
                $submittedAt = date('Y-m-d H:i:s', strtotime($start) + 86400 + $mi * 3600 + ($teamId % 40) * 60);

                list($answerRows, $actualPs) = seedBuildPaperAnswers(
                    $examItems,
                    $targetPs,
                    $batchId * 10007 + $teamId * 97 + $mi * 13,
                    $submittedAt
                );

                $paperId = (int)Db::name('tm_paper')->insertGetId([
                    'batch_id'       => $batchId,
                    'team_id'        => $teamId,
                    'member_id'      => (int)$m['id'],
                    'wx_user_id'     => null,
                    'status'         => 1,
                    'personal_score' => $actualPs,
                    'submitted_at'   => $submittedAt,
                    'created_at'     => $start,
                ]);
                $paperIds[] = $paperId;
                $personalScores[] = $actualPs;
                $paperCnt++;

                foreach ($answerRows as $ar) {
                    $ar['paper_id'] = $paperId;
                    $batchAnswerBuf[] = $ar;
                    $answerCnt++;
                }
                if (count($batchAnswerBuf) >= 800) {
                    seedInsertAnswerRows($batchAnswerBuf);
                    $batchAnswerBuf = [];
                }
            }

            $teamScore = round(array_sum($personalScores) / count($personalScores), 2);
            $levelCode = seedPickLevelCode($teamScore);

            // 先落盘本班组答卷明细，再汇总薄弱项
            if ($batchAnswerBuf) {
                seedInsertAnswerRows($batchAnswerBuf);
                $batchAnswerBuf = [];
            }
            $weak = seedBuildWeakFromPapers($paperIds, $examItems, $teamScore);

            Db::name('tm_team_result')->insert([
                'batch_id'            => $batchId,
                'team_id'             => $teamId,
                'team_score'          => $teamScore,
                'level_code'          => $levelCode,
                'submit_cnt'          => $submitN,
                'weak_indicator_json' => json_encode($weak, JSON_UNESCAPED_UNICODE),
                'scored_at'           => $scoredAt,
            ]);
            $resultCnt++;
        }

        if ($batchAnswerBuf) {
            seedInsertAnswerRows($batchAnswerBuf);
        }
        seedLog(sprintf(
            '批次 #%d %s → 结果 %d 班组，答卷写入完成',
            $batchId,
            SEED_BATCH_PREFIX . $title,
            (int)Db::name('tm_team_result')->where('batch_id', $batchId)->count()
        ));
    }

    Db::commit();

    $latestBatchId = (int)Db::name('tm_team_result')->order('scored_at desc,id desc')->value('batch_id');
    $dist = Db::name('tm_team_result')
        ->where('batch_id', $latestBatchId)
        ->field('level_code, COUNT(*) as cnt, ROUND(AVG(team_score),1) as avg_score')
        ->group('level_code')
        ->select();
    $batchCnt = (int)Db::name('tm_team_result')->group('batch_id')->count();
    $teamCnt = (int)Db::name('tm_team')->whereNull('deleted_at')->where('status', 1)->count();
    $orgCnt = (int)Db::name('tm_org')->whereNull('deleted_at')->where('status', 1)->count();
    $memberCnt = (int)Db::name('tm_member')->whereNull('deleted_at')->where('status', 1)->count();
    $totalResults = (int)Db::name('tm_team_result')->count();
    $totalPapers = (int)Db::name('tm_paper')->where('status', 1)->count();
    $totalAnswers = (int)Db::name('tm_paper_answer')->count();
    $samplePaperId = (int)Db::name('tm_paper')->where('batch_id', $latestBatchId)->where('status', 1)->order('id desc')->value('id');
    $sampleAns = $samplePaperId
        ? (int)Db::name('tm_paper_answer')->where('paper_id', $samplePaperId)->count()
        : 0;

    seedLog('');
    seedLog('=== 完成 ===');
    seedLog('组织=' . $orgCnt . ' 班组=' . $teamCnt . ' 成员=' . $memberCnt);
    seedLog('已算分批次数(有结果)=' . $batchCnt . ' 班组结果行=' . $totalResults);
    seedLog('已提交答卷=' . $totalPapers . ' 逐题作答行=' . $totalAnswers . '（本次答卷=' . $paperCnt . ' / 本次作答=' . $answerCnt . '）');
    seedLog('样例答卷 #' . $samplePaperId . ' 逐题数=' . $sampleAns . '（应约等于试卷题数 ' . count($examItems) . '）');
    seedLog('最近算分批次 #' . $latestBatchId . ' 等级分布:');
    foreach ($dist as $d) {
        seedLog('  - ' . $d['level_code'] . ': ' . $d['cnt'] . ' 班组, 均分 ' . $d['avg_score']);
    }
    seedLog('');
    seedLog('可在后台查看：测评结果 / 成员答卷详情（应能看到逐题作答）');
    seedLog('清理命令：php scripts/seed-analytics-data.php --reset');
} catch (\Exception $e) {
    Db::rollback();
    fwrite(STDERR, '失败: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
    exit(1);
}
