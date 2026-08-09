<?php
/**
 * 从 docs/bank-extract.txt 解析标准题库并导入 team_maturity
 * 用法：php scripts/import-question-bank.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$projectRoot = dirname(__DIR__);
$backend = $projectRoot . DIRECTORY_SEPARATOR . 'backend';
$extract = $projectRoot . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'bank-extract.txt';

if (!is_file($extract)) {
    fwrite(STDERR, "缺少抽取文件: {$extract}\n");
    exit(1);
}

define('APP_PATH', $backend . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
define('RUNTIME_PATH', $backend . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR);
require $backend . '/thinkphp/base.php';
\think\Loader::addNamespace('app', APP_PATH);
require APP_PATH . 'common.php';
\think\Config::set(include APP_PATH . 'database.php', 'database');

use think\Db;

$text = file_get_contents($extract);
$text = preg_replace("/\r\n?/", "\n", $text);
$lines = explode("\n", $text);

// 定位题库起始
$start = 0;
foreach ($lines as $i => $line) {
    if (mb_strpos($line, '成熟度测评题库') !== false) {
        $start = $i + 1;
        break;
    }
}

$L1_WEIGHTS = [18.07, 15.66, 20.48, 14.46, 19.28, 12.05];

function parseWeightName($line)
{
    $line = trim($line);
    if (preg_match('/^(.+?)（(\d+(?:\.\d+)?)）$/u', $line, $m)) {
        return [trim($m[1]), (float)$m[2]];
    }
    return null;
}

function isL1Weight($w, $known)
{
    foreach ($known as $kw) {
        if (abs($w - $kw) < 0.001) {
            return true;
        }
    }
    return false;
}

function splitOptions($raw)
{
    $raw = trim($raw);
    // 去掉题干后的 * 与选项串
    if (preg_match('/\[单选题\]\s*\*(.*)$/u', $raw, $m)) {
        $optPart = $m[1];
        $title = trim(preg_replace('/\s*\[单选题\].*$/u', '', $raw));
    } else {
        return [trim($raw), []];
    }
    $parts = preg_split('/○/u', $optPart);
    $opts = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') {
            $opts[] = $p;
        }
    }
    return [$title, $opts];
}

/**
 * 按语义给选项打分比例（文档中选项顺序常被打乱）
 */
function assignRatios(array $opts)
{
    $n = count($opts);
    if ($n === 0) {
        return [];
    }

    $rankScore = function ($text) {
        $t = $text;
        $score = 50;

        // 强负面（优先判）
        if (preg_match('/^未|^没有|^不了解|^不清楚|^尚未|^从不|^从未|^几乎从不|^几乎无人|^完全没有|^很难|^零星/u', $t)) {
            $score -= 40;
        }
        if (preg_match('/不太|较少|很少|较差|不及时|不方便|效果一般|落实效果不佳|偶尔|部分/u', $t)) {
            $score -= 15;
        }
        // 强正面
        if (preg_match('/^完善|^严格|^非常|^总是|^每次都|^全员|^已制定并有效|^便于理解和使用|^效果非常好|^已配备且效果良好|^培训形式多样|^持续跟踪|^完全固化|^非常明确|^参与度非常高|^现场物品摆放有序|^都会进行全面|^获取了详细|^进行了全面|^红、橙、黄、蓝/u', $t)) {
            $score += 40;
        }
        if (preg_match('/不存在未持证|从未出现此类情况|不存在迟报|及时采取有效|定期检查和维护后进行了记录|^每月$/u', $t)) {
            $score += 45;
        }
        // 中性偏正
        if (preg_match('/大多数|经常|一般，|已配备但|部分固化|偶尔指导/u', $t)) {
            $score += 5;
        }
        // 「不太完善」等含否定修饰时再扣
        if (preg_match('/不(太|够|能|便|完善)|未(能|建立|制定|配备|开展|召开|获取|采取)/u', $t)) {
            $score -= 25;
        }
        return $score;
    };

    $scored = [];
    foreach ($opts as $i => $o) {
        $scored[] = ['i' => $i, 'text' => $o, 's' => $rankScore($o)];
    }
    usort($scored, function ($a, $b) {
        if ($a['s'] === $b['s']) {
            return $a['i'] - $b['i'];
        }
        return $b['s'] - $a['s'];
    });

    if ($n === 4) {
        $ratios = [1.0, 0.75, 0.5, 0.25];
    } elseif ($n === 5) {
        $ratios = [1.0, 0.8, 0.6, 0.4, 0.2];
    } elseif ($n === 3) {
        $ratios = [1.0, 0.6, 0.25];
    } else {
        $ratios = [];
        for ($k = 0; $k < $n; $k++) {
            $ratios[] = round(1.0 - (0.75 * $k / max(1, $n - 1)), 4);
        }
    }

    $out = array_fill(0, $n, 0.25);
    foreach ($scored as $rank => $item) {
        $out[$item['i']] = $ratios[$rank];
    }
    return $out;
}

$items = []; // parsed questions
$curL1 = null;
$curL2 = null;
$pendingL3 = null;
$i = $start;
$len = count($lines);

while ($i < $len) {
    $line = trim($lines[$i]);
    $i++;
    if ($line === '' || in_array($line, ['序号', '一级指标', '二级指标', '三级指标', '三级指标题目', '本质安全型班组建设措施'], true)) {
        continue;
    }
    if (preg_match('/^\d+$/', $line)) {
        // 纯序号行，下一行通常是一级指标
        continue;
    }

    $wn = parseWeightName($line);
    if ($wn && isL1Weight($wn[1], $L1_WEIGHTS)) {
        $curL1 = ['name' => $wn[0], 'weight' => $wn[1]];
        $curL2 = null;
        $pendingL3 = null;
        continue;
    }

    if ($wn) {
        // 三级指标
        $pendingL3 = ['name' => $wn[0], 'weight' => $wn[1]];
        continue;
    }

    if (mb_strpos($line, '[单选题]') !== false) {
        if (!$curL1 || !$pendingL3) {
            echo "WARN skip question without L1/L3: {$line}\n";
            continue;
        }
        list($title, $opts) = splitOptions($line);
        // 措施：后续连续非空且不是新指标/题目的行
        $measure = '';
        while ($i < $len) {
            $peek = trim($lines[$i]);
            if ($peek === '') {
                $i++;
                break;
            }
            if (parseWeightName($peek) || mb_strpos($peek, '[单选题]') !== false || preg_match('/^\d+$/', $peek)) {
                break;
            }
            if (in_array($peek, ['序号', '一级指标', '二级指标', '三级指标', '三级指标题目', '本质安全型班组建设措施'], true)) {
                break;
            }
            // 二级指标：无权重、不像措施
            if (!preg_match('/^\d+\./', $peek) && $measure === '' && mb_strlen($peek) < 40 && mb_strpos($peek, '。') === false) {
                // 可能是下一条二级，留给外层
                break;
            }
            $measure .= ($measure === '' ? '' : "\n") . $peek;
            $i++;
        }

        if (!$curL2) {
            $curL2 = ['name' => '未分类'];
        }

        $items[] = [
            'l1' => $curL1,
            'l2' => $curL2,
            'l3' => $pendingL3,
            'title' => $title,
            'options' => $opts,
            'ratios' => assignRatios($opts),
            'measure' => $measure,
        ];
        $pendingL3 = null;
        continue;
    }

    // 二级指标
    if ($curL1) {
        $curL2 = ['name' => $line];
    }
}

echo '解析题目数=' . count($items) . PHP_EOL;
if (count($items) === 0) {
    exit(2);
}

// 统计
$byL1 = [];
foreach ($items as $it) {
    $n = $it['l1']['name'];
    if (!isset($byL1[$n])) {
        $byL1[$n] = ['w' => $it['l1']['weight'], 'cnt' => 0, 'w3' => 0];
    }
    $byL1[$n]['cnt']++;
    $byL1[$n]['w3'] += $it['l3']['weight'];
}
foreach ($byL1 as $n => $s) {
    echo "L1 {$n} weight={$s['w']} questions={$s['cnt']} sumL3=" . round($s['w3'], 2) . PHP_EOL;
}

Db::startTrans();
try {
    // 清理旧标准版（若存在）
    $old = Db::name('tm_indicator_version')->where('version_no', 'V1.0.0')->find();
    if ($old) {
        $vid = $old['id'];
        $qids = Db::name('tm_question')->where('version_id', $vid)->column('id');
        if ($qids) {
            Db::name('tm_option')->where('question_id', 'in', $qids)->delete();
        }
        Db::name('tm_question')->where('version_id', $vid)->delete();
        $indIds = Db::name('tm_indicator')->where('version_id', $vid)->column('id');
        if ($indIds) {
            Db::name('tm_empower_measure')->where('indicator_id', 'in', $indIds)->delete();
        }
        Db::name('tm_indicator')->where('version_id', $vid)->delete();
        Db::name('tm_indicator_version')->where('id', $vid)->delete();
    }

    $versionId = Db::name('tm_indicator_version')->insertGetId([
        'name'         => '班组安全成熟度标准题库',
        'version_no'   => 'V1.0.0',
        'status'       => 1,
        'published_at' => date('Y-m-d H:i:s'),
        'remark'       => '来源：题库以及成熟度在线测评模块.docx',
        'created_at'   => date('Y-m-d H:i:s'),
    ]);

    $l1Map = []; // name => id
    $l2Map = []; // l1name|l2name => id
    $sortL1 = 0;
    $sortQ = 0;
    $optCnt = 0;
    $measureCnt = 0;

    foreach ($items as $it) {
        $l1Name = $it['l1']['name'];
        if (!isset($l1Map[$l1Name])) {
            $sortL1++;
            $l1Map[$l1Name] = Db::name('tm_indicator')->insertGetId([
                'version_id' => $versionId,
                'parent_id'  => 0,
                'level_no'   => 1,
                'code'       => 'L1-' . $sortL1,
                'name'       => $l1Name,
                'weight'     => $it['l1']['weight'],
                'sort_no'    => $sortL1,
                'status'     => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        $l1Id = $l1Map[$l1Name];

        $l2Key = $l1Name . '|' . $it['l2']['name'];
        if (!isset($l2Map[$l2Key])) {
            $l2Map[$l2Key] = Db::name('tm_indicator')->insertGetId([
                'version_id' => $versionId,
                'parent_id'  => $l1Id,
                'level_no'   => 2,
                'code'       => null,
                'name'       => $it['l2']['name'],
                'weight'     => 0,
                'sort_no'    => count($l2Map) + 1,
                'status'     => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        $l2Id = $l2Map[$l2Key];

        $l3Id = Db::name('tm_indicator')->insertGetId([
            'version_id' => $versionId,
            'parent_id'  => $l2Id,
            'level_no'   => 3,
            'code'       => null,
            'name'       => $it['l3']['name'],
            'weight'     => $it['l3']['weight'],
            'sort_no'    => ++$sortQ,
            'status'     => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $qid = Db::name('tm_question')->insertGetId([
            'version_id'   => $versionId,
            'indicator_id' => $l3Id,
            'title'        => $it['title'],
            'remark'       => '',
            'sort_no'      => $sortQ,
            'status'       => 1,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        foreach ($it['options'] as $oi => $optText) {
            Db::name('tm_option')->insert([
                'question_id' => $qid,
                'content'     => $optText,
                'score_ratio' => $it['ratios'][$oi],
                'sort_no'     => $oi + 1,
            ]);
            $optCnt++;
        }

        // 每个三级指标补齐四个等级措施：有建设措施的写入本质安全型，其余填「暂无」
        $levelCodes = ['passive', 'dependent', 'independent', 'essential'];
        $measureText = $it['measure'] !== '' ? $it['measure'] : '暂无';
        $l3Title = mb_substr($it['l3']['name'], 0, 120);
        foreach ($levelCodes as $li => $code) {
            $content = ($code === 'essential') ? $measureText : '暂无';
            Db::name('tm_empower_measure')->insert([
                'level_code'   => $code,
                'indicator_id' => $l3Id,
                'title'        => $l3Title,
                'content'      => $content,
                'sort_no'      => $sortQ * 10 + ($li + 1),
                'status'       => 1,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            $measureCnt++;
        }
    }

    Db::commit();
    echo "导入完成 version_id={$versionId}\n";
    echo "一级=" . count($l1Map) . " 二级=" . count($l2Map) . " 三级/题={$sortQ} 选项={$optCnt} 措施={$measureCnt}\n";

    // 写出解析预览 JSON 便于人工核对选项比例
    $preview = array_map(function ($it) {
        $pairs = [];
        foreach ($it['options'] as $i => $o) {
            $pairs[] = ['content' => $o, 'ratio' => $it['ratios'][$i]];
        }
        return [
            'l1' => $it['l1']['name'],
            'l3' => $it['l3']['name'] . ' (' . $it['l3']['weight'] . ')',
            'title' => $it['title'],
            'options' => $pairs,
            'has_measure' => $it['measure'] !== '',
        ];
    }, $items);
    $previewPath = $projectRoot . '/docs/bank-import-preview.json';
    file_put_contents($previewPath, json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "预览: {$previewPath}\n";
    echo "ALL_DONE\n";
} catch (\Exception $e) {
    Db::rollback();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(3);
}
