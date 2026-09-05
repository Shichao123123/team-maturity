<?php
namespace app\index\service;

use app\index\model\MemberModel;
use think\Db;

/**
 * 结果查询：按批次查看班组汇总、薄弱指标、个人答卷。
 */
class ResultService
{
    /**
     * 获取可选测评批次列表（未删除，按 id 倒序）。
     *
     * @return array 批次行列表，含 id、name、start_time、end_time、status、score_status
     */
    public static function listBatches()
    {
        return Db::name('tm_batch')->alias('b')
            ->whereNull('b.deleted_at')
            ->field('b.id, b.name, b.start_time, b.end_time, b.status, b.score_status')
            ->order('b.id desc')
            ->select();
    }

    /**
     * 查询批次下班组汇总列表（含未算分班组的占位行）。
     *
     * @param int    $batchId   批次 ID
     * @param string $keyword   班组名称关键字（空则不过滤）
     * @param string $levelCode 成熟度等级编码（空则不过滤）
     * @param int    $page      页码（≥1 且 limit>0 时分页；0 表示返回全部，供导出用）
     * @param int    $limit     每页条数
     * @return array [bool ok, string msg, array|null data] data 含 batch、list、count
     */
    public static function teamList($batchId, $keyword = '', $levelCode = '', $page = 0, $limit = 0)
    {
        $batch = BatchService::find($batchId);
        if (!$batch) {
            return [false, '批次不存在', null];
        }

        $rows = BatchService::progress($batchId);
        $levelNames = Db::name('tm_level_threshold')->column('level_name', 'level_code');

        // 排名：有班组分的按分降序
        $ranked = $rows;
        usort($ranked, function ($a, $b) {
            $sa = ($a['team_score'] === null || $a['team_score'] === '') ? -1 : (float)$a['team_score'];
            $sb = ($b['team_score'] === null || $b['team_score'] === '') ? -1 : (float)$b['team_score'];
            if ($sa == $sb) {
                return $a['team_id'] - $b['team_id'];
            }
            return ($sa > $sb) ? -1 : 1;
        });
        $rankMap = [];
        $rank = 0;
        foreach ($ranked as $r) {
            if ($r['team_score'] === null || $r['team_score'] === '') {
                $rankMap[(int)$r['team_id']] = null;
                continue;
            }
            $rank++;
            $rankMap[(int)$r['team_id']] = $rank;
        }

        $list = [];
        foreach ($rows as $row) {
            $tid = (int)$row['team_id'];
            if ($keyword !== '' && mb_strpos($row['team_name'], $keyword) === false) {
                continue;
            }
            if ($levelCode !== '' && ($row['level_code'] ?: '') !== $levelCode) {
                continue;
            }
            $row['rank_no'] = isset($rankMap[$tid]) ? $rankMap[$tid] : null;
            $row['level_name'] = $row['level_name'] ?: (
                ($row['level_code'] && isset($levelNames[$row['level_code']]))
                    ? $levelNames[$row['level_code']] : ''
            );
            $row['scored'] = ($row['team_score'] !== null && $row['team_score'] !== '') ? 1 : 0;
            $list[] = $row;
        }

        // 列表按排名：有分在前
        usort($list, function ($a, $b) {
            $ra = $a['rank_no'] === null ? 99999 : (int)$a['rank_no'];
            $rb = $b['rank_no'] === null ? 99999 : (int)$b['rank_no'];
            if ($ra === $rb) {
                return $a['team_id'] - $b['team_id'];
            }
            return $ra - $rb;
        });

        $count = count($list);
        $page = (int)$page;
        $limit = (int)$limit;
        if ($page > 0 && $limit > 0) {
            $list = array_slice($list, ($page - 1) * $limit, $limit);
        }

        return [true, 'ok', [
            'batch' => [
                'id'                => (int)$batch['id'],
                'name'              => $batch['name'],
                'start_time'        => $batch['start_time'],
                'end_time'          => $batch['end_time'],
                'status'            => (int)$batch['status'],
                'status_text'       => BatchService::statusText($batch['status']),
                'score_status'      => (int)$batch['score_status'],
                'score_status_text' => BatchService::scoreStatusText($batch['score_status']),
            ],
            'list'  => $list,
            'count' => $count,
        ]];
    }

    /**
     * 某班组参与过的测评批次及得分摘要（班组管理详情入口）。
     *
     * @param int $teamId
     * @return array [bool ok, string msg, array|null data]
     */
    public static function teamBatchList($teamId)
    {
        $teamId = (int)$teamId;
        $team = Db::name('tm_team')->where('id', $teamId)->whereNull('deleted_at')->find();
        if (!$team) {
            return [false, '班组不存在', null];
        }
        $orgName = '';
        if (!empty($team['org_id'])) {
            $orgName = (string)Db::name('tm_org')->where('id', (int)$team['org_id'])->value('name');
        }
        $rows = Db::name('tm_batch_team')->alias('bt')
            ->join('tm_batch b', 'b.id = bt.batch_id')
            ->join('tm_team_result tr', 'tr.batch_id = bt.batch_id AND tr.team_id = bt.team_id', 'LEFT')
            ->where('bt.team_id', $teamId)
            ->whereNull('b.deleted_at')
            ->field('b.id as batch_id, b.name as batch_name, b.start_time, b.end_time, b.status, b.score_status,
                tr.team_score, tr.level_code, tr.submit_cnt, tr.scored_at')
            ->order('b.id desc')
            ->select();
        $levelNames = Db::name('tm_level_threshold')->column('level_name', 'level_code');
        $list = [];
        foreach ($rows as $row) {
            $bid = (int)$row['batch_id'];
            $score = $row['team_score'];
            $rankNo = null;
            if ($score !== null && $score !== '') {
                $higher = (int)Db::name('tm_team_result')
                    ->where('batch_id', $bid)
                    ->where('team_score', '>', $score)
                    ->count();
                $rankNo = $higher + 1;
            }
            $code = $row['level_code'] ?: '';
            $list[] = [
                'batch_id'          => $bid,
                'batch_name'        => $row['batch_name'],
                'start_time'        => $row['start_time'],
                'end_time'          => $row['end_time'],
                'status'            => (int)$row['status'],
                'status_text'       => BatchService::statusText($row['status']),
                'score_status'      => (int)$row['score_status'],
                'score_status_text' => BatchService::scoreStatusText($row['score_status']),
                'team_score'        => $score,
                'level_code'        => $code,
                'level_name'        => ($code && isset($levelNames[$code])) ? $levelNames[$code] : '',
                'submit_cnt'        => $row['submit_cnt'] !== null ? (int)$row['submit_cnt'] : null,
                'scored_at'         => $row['scored_at'],
                'rank_no'           => $rankNo,
            ];
        }
        $memberCnt = (int)Db::name('tm_member')
            ->where('team_id', $teamId)
            ->whereNull('deleted_at')
            ->count();

        return [true, 'ok', [
            'team' => [
                'team_id'    => $teamId,
                'team_name'  => $team['name'],
                'org_id'     => (int)$team['org_id'],
                'org_name'   => $orgName,
                'status'     => (int)$team['status'],
                'member_cnt' => $memberCnt,
            ],
            'list'  => $list,
            'count' => count($list),
        ]];
    }

    /**
     * 获取单班组在某批次下的详细结果（汇总、等级特征、薄弱指标、成员答卷）。
     *
     * @param int $batchId 批次 ID
     * @param int $teamId  班组 ID
     * @return array [bool ok, string msg, array|null data] data 含 batch、team、summary、feature_desc、weak_indicators、papers
     */
    public static function teamDetail($batchId, $teamId)
    {
        $batch = BatchService::find($batchId);
        if (!$batch) {
            return [false, '批次不存在', null];
        }
        $team = Db::name('tm_team')->where('id', (int)$teamId)->whereNull('deleted_at')->find();
        if (!$team) {
            return [false, '班组不存在', null];
        }
        $bind = Db::name('tm_batch_team')
            ->where('batch_id', (int)$batchId)
            ->where('team_id', (int)$teamId)
            ->find();
        if (!$bind) {
            return [false, '该班组不在本批次参评范围', null];
        }

        list($ok, $msg, $data) = self::teamList($batchId);
        if (!$ok) {
            return [false, $msg, null];
        }
        $summary = null;
        foreach ($data['list'] as $row) {
            if ((int)$row['team_id'] === (int)$teamId) {
                $summary = $row;
                break;
            }
        }

        $result = Db::name('tm_team_result')
            ->where('batch_id', (int)$batchId)
            ->where('team_id', (int)$teamId)
            ->find();
        $weak = [];
        $feature = '';
        if ($result) {
            $weak = $result['weak_indicator_json']
                ? json_decode($result['weak_indicator_json'], true)
                : [];
            if (!is_array($weak)) {
                $weak = [];
            }
            $lv = LevelJudgeService::judge($result['team_score']);
            $feature = $lv ? $lv['feature_desc'] : '';
        }

        $papers = self::paperRows($batchId, $teamId);

        return [true, 'ok', [
            'batch'   => $data['batch'],
            'team'    => [
                'team_id'   => (int)$teamId,
                'team_name' => $team['name'],
            ],
            'summary' => $summary,
            'feature_desc' => $feature,
            'weak_indicators' => $weak,
            'papers'  => $papers,
        ]];
    }

    /**
     * 查询批次下个人答卷列表（可按班组、姓名、状态筛选）。
     *
     * @param int         $batchId 批次 ID
     * @param int         $teamId  班组 ID；0 表示不限
     * @param string      $keyword 成员姓名关键字
     * @param int|null    $status  答卷状态；null 表示不限（0 答题中，1 已提交）
     * @return array [bool ok, string msg, array data] data 含 batch_id、list、count
     */
    /**
     * @param int         $batchId
     * @param int         $teamId
     * @param string      $keyword
     * @param mixed       $status
     * @param int         $page  页码；0 表示全部
     * @param int         $limit 每页条数
     */
    public static function paperList($batchId, $teamId = 0, $keyword = '', $status = null, $page = 0, $limit = 0)
    {
        $batch = BatchService::find($batchId);
        if (!$batch) {
            return [false, '批次不存在', null];
        }
        $rows = self::paperRows($batchId, $teamId, $keyword, $status);
        $count = count($rows);
        $page = (int)$page;
        $limit = (int)$limit;
        if ($page > 0 && $limit > 0) {
            $rows = array_slice($rows, ($page - 1) * $limit, $limit);
        }
        return [true, 'ok', [
            'batch_id' => (int)$batchId,
            'list'     => $rows,
            'count'    => $count,
        ]];
    }

    /**
     * 获取单份答卷详情（题目、选项、作答记录等）。
     *
     * @param int $paperId 答卷 ID
     * @return array [bool ok, string msg, array|null detail] 成功时 detail 为 MemberModel::paperDetail 返回值
     */
    public static function paperDetail($paperId)
    {
        $detail = (new MemberModel())->paperDetail((int)$paperId, 0);
        if (!$detail) {
            return [false, '答卷不存在', null];
        }
        return [true, 'ok', $detail];
    }

    /**
     * 导出批次下班组汇总为 Excel 并直接下载。
     *
     * @param int $batchId 批次 ID
     * @return array [bool ok, string msg] 成功时 msg 为 'ok'，并已输出文件流
     */
    public static function exportTeam($batchId)
    {
        list($ok, $msg, $data) = self::teamList($batchId);
        if (!$ok) {
            return [false, $msg];
        }
        $headers = ['排名', '班组', '已提交', '答题中', '班组得分', '成熟度等级', '算分时间'];
        $rows = [];
        foreach ($data['list'] as $r) {
            $rows[] = [
                '排名'     => $r['rank_no'] === null ? '' : $r['rank_no'],
                '班组'     => $r['team_name'],
                '已提交'   => $r['submitted_cnt'],
                '答题中'   => $r['answering_cnt'],
                '班组得分' => ($r['team_score'] === null || $r['team_score'] === '') ? '' : $r['team_score'],
                '成熟度等级' => $r['level_name'] ?: '',
                '算分时间' => $r['scored_at'] ?: '',
            ];
        }
        $name = '班组结果_' . preg_replace('/[\\\\\/:*?"<>|]/', '_', $data['batch']['name']) . '.xls';
        ExcelHelper::download($name, $headers, $rows);
        return [true, 'ok'];
    }

    /**
     * 导出批次下已算分班组的得分排行 Excel 并直接下载。
     *
     * @param int $batchId 批次 ID
     * @return array [bool ok, string msg] 成功时 msg 为 'ok'，并已输出文件流
     */
    public static function exportRank($batchId)
    {
        list($ok, $msg, $data) = self::teamList($batchId);
        if (!$ok) {
            return [false, $msg];
        }
        $headers = ['排名', '班组', '班组得分', '成熟度等级', '提交人数'];
        $rows = [];
        foreach ($data['list'] as $r) {
            if ($r['rank_no'] === null) {
                continue;
            }
            $rows[] = [
                '排名'       => $r['rank_no'],
                '班组'       => $r['team_name'],
                '班组得分'   => $r['team_score'],
                '成熟度等级' => $r['level_name'] ?: '',
                '提交人数'   => $r['submitted_cnt'],
            ];
        }
        $name = '班组排行_' . preg_replace('/[\\\\\/:*?"<>|]/', '_', $data['batch']['name']) . '.xls';
        ExcelHelper::download($name, $headers, $rows);
        return [true, 'ok'];
    }

    /**
     * 从班组列表提取可绘图排行数据。
     *
     * @param array $list
     * @return array
     */
    private static function rankChartList(array $list)
    {
        $out = [];
        foreach ($list as $r) {
            if (!isset($r['team_score']) || $r['team_score'] === null || $r['team_score'] === '') {
                continue;
            }
            if (isset($r['rank_no']) && $r['rank_no'] === null) {
                continue;
            }
            $out[] = [
                'team_name'  => isset($r['team_name']) ? $r['team_name'] : '',
                'team_score' => (float)$r['team_score'],
            ];
        }
        return $out;
    }

    /**
     * 薄弱指标得分率柱图数据（0~100）。
     *
     * @param array $weak
     * @return array
     */
    private static function weakChartList(array $weak)
    {
        $out = [];
        foreach ($weak as $w) {
            $full = isset($w['full_score']) ? (float)$w['full_score'] : 0;
            $avg = isset($w['avg_score']) ? (float)$w['avg_score'] : 0;
            $pct = $full > 0 ? round(100 * $avg / $full, 1) : 0;
            $name = isset($w['indicator_name']) ? $w['indicator_name'] : ('指标#' . (isset($w['indicator_id']) ? $w['indicator_id'] : ''));
            $out[] = ['team_name' => $name, 'team_score' => $pct];
        }
        return $out;
    }

    /**
     * 个人答卷：按指标汇总得分率柱图数据。
     *
     * @param array $answers
     * @return array
     */
    private static function paperIndicatorChartList(array $answers)
    {
        $map = [];
        foreach ($answers as $a) {
            $name = !empty($a['indicator_name']) ? $a['indicator_name'] : '未分类';
            if (!isset($map[$name])) {
                $map[$name] = ['got' => 0.0, 'full' => 0.0];
            }
            $map[$name]['full'] += isset($a['weight']) ? (float)$a['weight'] : 0;
            if (!empty($a['is_answered'])) {
                $map[$name]['got'] += isset($a['item_score']) ? (float)$a['item_score'] : 0;
            }
        }
        $out = [];
        foreach ($map as $name => $v) {
            $pct = $v['full'] > 0 ? round(100 * $v['got'] / $v['full'], 1) : 0;
            $out[] = ['team_name' => $name, 'team_score' => $pct];
        }
        return $out;
    }

    /**
     * 导出批次下班组汇总 PDF（含排行柱图）。
     *
     * @param int $batchId
     * @return array [bool ok, string msg]
     */
    public static function exportTeamPdf($batchId)
    {
        list($ok, $msg, $data) = self::teamList($batchId);
        if (!$ok) {
            return [false, $msg];
        }
        $batch = $data['batch'];
        $chartPath = null;
        try {
            $chartList = self::rankChartList($data['list']);
            if ($chartList) {
                $chartPath = PdfChartHelper::renderRankBars($chartList, 15);
            }
        } catch (\Exception $e) {
            $chartPath = null;
        }
        $pdf = PdfHelper::create('班组测评结果汇总', $batch['name']);
        PdfHelper::writeMeta($pdf, [
            ['label' => '批次', 'value' => $batch['name']],
            ['label' => '测评时段', 'value' => ($batch['start_time'] ?: '') . ' ~ ' . ($batch['end_time'] ?: '')],
            ['label' => '批次状态', 'value' => $batch['status_text'] . ' / 算分：' . $batch['score_status_text']],
            ['label' => '班组数', 'value' => (string)$data['count']],
        ]);
        if ($chartPath) {
            PdfHelper::writeSection($pdf, '班组得分图表');
            PdfHelper::writeImage($pdf, $chartPath, 186);
        }
        PdfHelper::writeSection($pdf, '班组结果列表');
        $rows = [];
        foreach ($data['list'] as $r) {
            $rows[] = [
                $r['rank_no'] === null ? '—' : $r['rank_no'],
                $r['team_name'],
                $r['submitted_cnt'],
                $r['answering_cnt'],
                PdfHelper::fmtScore($r['team_score']),
                $r['level_name'] ?: '—',
                $r['scored_at'] ?: '—',
            ];
        }
        PdfHelper::writeTable(
            $pdf,
            ['排名', '班组', '已提交', '答题中', '得分', '成熟度等级', '算分时间'],
            $rows,
            [8, 22, 10, 10, 12, 18, 20]
        );
        PdfHelper::cleanupImages($chartPath);
        PdfHelper::download($pdf, '班组结果_' . PdfHelper::safeFilename($batch['name']) . '.pdf');
        return [true, 'ok'];
    }

    /**
     * 导出批次下班组汇总 Word（含排行柱图）。
     *
     * @param int $batchId
     * @return array [bool ok, string msg]
     */
    public static function exportTeamDocx($batchId)
    {
        list($ok, $msg, $data) = self::teamList($batchId);
        if (!$ok) {
            return [false, $msg];
        }
        $batch = $data['batch'];
        $chartPath = null;
        try {
            $chartList = self::rankChartList($data['list']);
            if ($chartList) {
                $chartPath = PdfChartHelper::renderRankBars($chartList, 15);
            }
        } catch (\Exception $e) {
            $chartPath = null;
        }
        $doc = DocxHelper::create('班组测评结果汇总', $batch['name']);
        $doc->writeMeta([
            ['label' => '批次', 'value' => $batch['name']],
            ['label' => '测评时段', 'value' => ($batch['start_time'] ?: '') . ' ~ ' . ($batch['end_time'] ?: '')],
            ['label' => '批次状态', 'value' => $batch['status_text'] . ' / 算分：' . $batch['score_status_text']],
            ['label' => '班组数', 'value' => (string)$data['count']],
        ]);
        if ($chartPath) {
            $doc->writeSection('班组得分图表');
            $doc->writeImage($chartPath, 5486400, '班组得分排行');
        }
        $doc->writeSection('班组结果列表');
        $rows = [];
        foreach ($data['list'] as $r) {
            $rows[] = [
                $r['rank_no'] === null ? '—' : $r['rank_no'],
                $r['team_name'],
                $r['submitted_cnt'],
                $r['answering_cnt'],
                PdfHelper::fmtScore($r['team_score']),
                $r['level_name'] ?: '—',
                $r['scored_at'] ?: '—',
            ];
        }
        $doc->writeTable(
            ['排名', '班组', '已提交', '答题中', '得分', '成熟度等级', '算分时间'],
            $rows
        );
        $doc->download('班组结果_' . DocxHelper::safeFilename($batch['name']) . '.docx');
        return [true, 'ok'];
    }

    /**
     * 导出批次班组排行 PDF（含柱图）。
     *
     * @param int $batchId
     * @return array [bool ok, string msg]
     */
    public static function exportRankPdf($batchId)
    {
        list($ok, $msg, $data) = self::teamList($batchId);
        if (!$ok) {
            return [false, $msg];
        }
        $batch = $data['batch'];
        $rows = [];
        $chartList = [];
        foreach ($data['list'] as $r) {
            if ($r['rank_no'] === null) {
                continue;
            }
            $rows[] = [
                $r['rank_no'],
                $r['team_name'],
                PdfHelper::fmtScore($r['team_score']),
                $r['level_name'] ?: '—',
                $r['submitted_cnt'],
            ];
            $chartList[] = [
                'team_name'  => $r['team_name'],
                'team_score' => (float)$r['team_score'],
            ];
        }
        if (!$rows) {
            return [false, '暂无可导出的排行数据'];
        }
        $chartPath = null;
        try {
            $chartPath = PdfChartHelper::renderRankBars($chartList, 15);
        } catch (\Exception $e) {
            $chartPath = null;
        }
        $pdf = PdfHelper::create('班组测评排行榜', $batch['name']);
        PdfHelper::writeMeta($pdf, [
            ['label' => '批次', 'value' => $batch['name']],
            ['label' => '测评时段', 'value' => ($batch['start_time'] ?: '') . ' ~ ' . ($batch['end_time'] ?: '')],
            ['label' => '上榜班组数', 'value' => (string)count($rows)],
        ]);
        if ($chartPath) {
            PdfHelper::writeSection($pdf, '排行图表');
            PdfHelper::writeImage($pdf, $chartPath, 186);
        }
        PdfHelper::writeSection($pdf, '排行明细');
        PdfHelper::writeTable(
            $pdf,
            ['排名', '班组', '班组得分', '成熟度等级', '提交人数'],
            $rows,
            [10, 30, 15, 25, 20]
        );
        PdfHelper::cleanupImages($chartPath);
        PdfHelper::download($pdf, '班组排行_' . PdfHelper::safeFilename($batch['name']) . '.pdf');
        return [true, 'ok'];
    }

    /**
     * 导出批次班组排行 Word（含柱图）。
     *
     * @param int $batchId
     * @return array [bool ok, string msg]
     */
    public static function exportRankDocx($batchId)
    {
        list($ok, $msg, $data) = self::teamList($batchId);
        if (!$ok) {
            return [false, $msg];
        }
        $batch = $data['batch'];
        $rows = [];
        $chartList = [];
        foreach ($data['list'] as $r) {
            if ($r['rank_no'] === null) {
                continue;
            }
            $rows[] = [
                $r['rank_no'],
                $r['team_name'],
                PdfHelper::fmtScore($r['team_score']),
                $r['level_name'] ?: '—',
                $r['submitted_cnt'],
            ];
            $chartList[] = [
                'team_name'  => $r['team_name'],
                'team_score' => (float)$r['team_score'],
            ];
        }
        if (!$rows) {
            return [false, '暂无可导出的排行数据'];
        }
        $chartPath = null;
        try {
            $chartPath = PdfChartHelper::renderRankBars($chartList, 15);
        } catch (\Exception $e) {
            $chartPath = null;
        }
        $doc = DocxHelper::create('班组测评排行榜', $batch['name']);
        $doc->writeMeta([
            ['label' => '批次', 'value' => $batch['name']],
            ['label' => '测评时段', 'value' => ($batch['start_time'] ?: '') . ' ~ ' . ($batch['end_time'] ?: '')],
            ['label' => '上榜班组数', 'value' => (string)count($rows)],
        ]);
        if ($chartPath) {
            $doc->writeSection('排行图表');
            $doc->writeImage($chartPath, 5486400, '班组得分排行');
        }
        $doc->writeSection('排行明细');
        $doc->writeTable(
            ['排名', '班组', '班组得分', '成熟度等级', '提交人数'],
            $rows
        );
        $doc->download('班组排行_' . DocxHelper::safeFilename($batch['name']) . '.docx');
        return [true, 'ok'];
    }

    /**
     * 导出单班组详情 PDF（薄弱指标图 + 成员答卷摘要）。
     *
     * @param int $batchId
     * @param int $teamId
     * @return array [bool ok, string msg]
     */
    public static function exportTeamDetailPdf($batchId, $teamId)
    {
        list($ok, $msg, $data) = self::teamDetail($batchId, $teamId);
        if (!$ok) {
            return [false, $msg];
        }
        $batch = $data['batch'];
        $team = $data['team'];
        $s = $data['summary'] ?: [];
        $titleTeam = $team['team_name'];
        $weak = isset($data['weak_indicators']) ? $data['weak_indicators'] : [];
        $chartPath = null;
        try {
            $chartList = self::weakChartList($weak);
            if ($chartList) {
                $chartPath = PdfChartHelper::renderRankBars($chartList, 12, '薄弱指标得分率');
            } elseif (isset($s['team_score']) && $s['team_score'] !== null && $s['team_score'] !== '') {
                $chartPath = PdfChartHelper::renderRankBars([
                    ['team_name' => $titleTeam, 'team_score' => (float)$s['team_score']],
                ], 1, '班组得分');
            }
        } catch (\Exception $e) {
            $chartPath = null;
        }
        $pdf = PdfHelper::create('班组测评详情', $batch['name'] . ' · ' . $titleTeam);
        PdfHelper::writeMeta($pdf, [
            ['label' => '批次', 'value' => $batch['name']],
            ['label' => '班组', 'value' => $titleTeam],
            ['label' => '班组得分', 'value' => PdfHelper::fmtScore(isset($s['team_score']) ? $s['team_score'] : null)],
            ['label' => '成熟度等级', 'value' => !empty($s['level_name']) ? $s['level_name'] : '尚未算分'],
            ['label' => '排名', 'value' => !empty($s['rank_no']) ? ('第 ' . $s['rank_no'] . ' 名') : '—'],
            ['label' => '提交情况', 'value' => '已提交 ' . (isset($s['submitted_cnt']) ? $s['submitted_cnt'] : 0)
                . ' 人，答题中 ' . (isset($s['answering_cnt']) ? $s['answering_cnt'] : 0) . ' 人'],
            ['label' => '算分时间', 'value' => !empty($s['scored_at']) ? $s['scored_at'] : '—'],
        ]);
        if (!empty($data['feature_desc'])) {
            PdfHelper::writeSection($pdf, '等级特征');
            PdfHelper::writeParagraph($pdf, $data['feature_desc']);
        }
        if ($chartPath) {
            PdfHelper::writeSection($pdf, $weak ? '薄弱指标得分率' : '班组得分');
            PdfHelper::writeImage($pdf, $chartPath, 186);
        }

        PdfHelper::writeSection($pdf, '薄弱指标与赋能措施');
        if (!$weak) {
            PdfHelper::writeParagraph($pdf, '暂无薄弱指标（未算分或均已达标）');
        } else {
            $wrows = [];
            foreach ($weak as $i => $w) {
                $wrows[] = [
                    $i + 1,
                    isset($w['indicator_name']) ? $w['indicator_name'] : ('指标#' . $w['indicator_id']),
                    PdfHelper::fmtScore(isset($w['avg_score']) ? $w['avg_score'] : null)
                        . ' / ' . PdfHelper::fmtScore(isset($w['full_score']) ? $w['full_score'] : null),
                    isset($w['measure']) ? $w['measure'] : '暂无',
                ];
            }
            PdfHelper::writeTable(
                $pdf,
                ['序号', '指标', '均分/满分', '赋能措施'],
                $wrows,
                [8, 28, 16, 48]
            );
        }

        PdfHelper::writeSection($pdf, '成员答卷摘要');
        $prows = [];
        foreach ($data['papers'] as $p) {
            $prows[] = [
                $p['member_name'] ?: '—',
                $p['role_name'] ?: '—',
                $p['status_text'] ?: '—',
                PdfHelper::fmtScore($p['personal_score']),
                $p['personal_level_name'] ?: '—',
                isset($p['answered_cnt']) ? $p['answered_cnt'] : 0,
                $p['submitted_at'] ?: '—',
            ];
        }
        PdfHelper::writeTable(
            $pdf,
            ['姓名', '角色', '状态', '个人分', '个人判级', '已答', '提交时间'],
            $prows,
            [12, 12, 10, 10, 16, 8, 32]
        );
        PdfHelper::cleanupImages($chartPath);
        PdfHelper::download(
            $pdf,
            '班组详情_' . PdfHelper::safeFilename($batch['name'] . '_' . $titleTeam) . '.pdf'
        );
        return [true, 'ok'];
    }

    /**
     * 导出单班组详情 Word（含薄弱指标图）。
     *
     * @param int $batchId
     * @param int $teamId
     * @return array [bool ok, string msg]
     */
    public static function exportTeamDetailDocx($batchId, $teamId)
    {
        list($ok, $msg, $data) = self::teamDetail($batchId, $teamId);
        if (!$ok) {
            return [false, $msg];
        }
        $batch = $data['batch'];
        $team = $data['team'];
        $s = $data['summary'] ?: [];
        $titleTeam = $team['team_name'];
        $weak = isset($data['weak_indicators']) ? $data['weak_indicators'] : [];
        $chartPath = null;
        try {
            $chartList = self::weakChartList($weak);
            if ($chartList) {
                $chartPath = PdfChartHelper::renderRankBars($chartList, 12, '薄弱指标得分率');
            } elseif (isset($s['team_score']) && $s['team_score'] !== null && $s['team_score'] !== '') {
                $chartPath = PdfChartHelper::renderRankBars([
                    ['team_name' => $titleTeam, 'team_score' => (float)$s['team_score']],
                ], 1, '班组得分');
            }
        } catch (\Exception $e) {
            $chartPath = null;
        }
        $doc = DocxHelper::create('班组测评详情', $batch['name'] . ' · ' . $titleTeam);
        $doc->writeMeta([
            ['label' => '批次', 'value' => $batch['name']],
            ['label' => '班组', 'value' => $titleTeam],
            ['label' => '班组得分', 'value' => PdfHelper::fmtScore(isset($s['team_score']) ? $s['team_score'] : null)],
            ['label' => '成熟度等级', 'value' => !empty($s['level_name']) ? $s['level_name'] : '尚未算分'],
            ['label' => '排名', 'value' => !empty($s['rank_no']) ? ('第 ' . $s['rank_no'] . ' 名') : '—'],
            ['label' => '提交情况', 'value' => '已提交 ' . (isset($s['submitted_cnt']) ? $s['submitted_cnt'] : 0)
                . ' 人，答题中 ' . (isset($s['answering_cnt']) ? $s['answering_cnt'] : 0) . ' 人'],
            ['label' => '算分时间', 'value' => !empty($s['scored_at']) ? $s['scored_at'] : '—'],
        ]);
        if (!empty($data['feature_desc'])) {
            $doc->writeSection('等级特征');
            $doc->writeParagraph($data['feature_desc']);
        }
        if ($chartPath) {
            $doc->writeSection($weak ? '薄弱指标得分率' : '班组得分');
            $doc->writeImage($chartPath, 5486400, '薄弱指标得分率');
        }
        $doc->writeSection('薄弱指标与赋能措施');
        if (!$weak) {
            $doc->writeParagraph('暂无薄弱指标（未算分或均已达标）');
        } else {
            $wrows = [];
            foreach ($weak as $i => $w) {
                $wrows[] = [
                    $i + 1,
                    isset($w['indicator_name']) ? $w['indicator_name'] : ('指标#' . $w['indicator_id']),
                    PdfHelper::fmtScore(isset($w['avg_score']) ? $w['avg_score'] : null)
                        . ' / ' . PdfHelper::fmtScore(isset($w['full_score']) ? $w['full_score'] : null),
                    isset($w['measure']) ? $w['measure'] : '暂无',
                ];
            }
            $doc->writeTable(['序号', '指标', '均分/满分', '赋能措施'], $wrows);
        }
        $doc->writeSection('成员答卷摘要');
        $prows = [];
        foreach ($data['papers'] as $p) {
            $prows[] = [
                $p['member_name'] ?: '—',
                $p['role_name'] ?: '—',
                $p['status_text'] ?: '—',
                PdfHelper::fmtScore($p['personal_score']),
                $p['personal_level_name'] ?: '—',
                isset($p['answered_cnt']) ? $p['answered_cnt'] : 0,
                $p['submitted_at'] ?: '—',
            ];
        }
        $doc->writeTable(
            ['姓名', '角色', '状态', '个人分', '个人判级', '已答', '提交时间'],
            $prows
        );
        $doc->download('班组详情_' . DocxHelper::safeFilename($batch['name'] . '_' . $titleTeam) . '.docx');
        return [true, 'ok'];
    }

    /**
     * 导出个人答卷 PDF（含指标得分率图 + 逐题作答）。
     *
     * @param int $paperId
     * @return array [bool ok, string msg]
     */
    public static function exportPaperPdf($paperId)
    {
        list($ok, $msg, $detail) = self::paperDetail($paperId);
        if (!$ok) {
            return [false, $msg];
        }
        $p = $detail['paper'];
        $answers = isset($detail['answers']) ? $detail['answers'] : [];
        $sub = ($p['batch_name'] ?: '') . ' · ' . ($p['team_name'] ?: '') . ' · ' . ($p['member_name'] ?: '');
        $chartPath = null;
        try {
            $indList = self::paperIndicatorChartList($answers);
            if ($indList) {
                $chartPath = PdfChartHelper::renderRankBars($indList, 15, '指标得分率');
            } else {
                $cmp = [];
                if ($p['personal_score'] !== null && $p['personal_score'] !== '') {
                    $cmp[] = ['team_name' => '个人得分', 'team_score' => (float)$p['personal_score']];
                }
                if ($p['team_score'] !== null && $p['team_score'] !== '') {
                    $cmp[] = ['team_name' => '班组得分', 'team_score' => (float)$p['team_score']];
                }
                if ($cmp) {
                    $chartPath = PdfChartHelper::renderRankBars($cmp, 2, '得分对比');
                }
            }
        } catch (\Exception $e) {
            $chartPath = null;
        }
        $pdf = PdfHelper::create('个人测评答卷', $sub);
        PdfHelper::writeMeta($pdf, [
            ['label' => '批次', 'value' => $p['batch_name']],
            ['label' => '班组', 'value' => $p['team_name']],
            ['label' => '成员', 'value' => $p['member_name']],
            ['label' => '答卷状态', 'value' => $p['status_text']],
            ['label' => '提交时间', 'value' => $p['submitted_at'] ?: '—'],
            ['label' => '个人得分', 'value' => PdfHelper::fmtScore($p['personal_score'])
                . ($p['personal_level_name'] ? ('（' . $p['personal_level_name'] . '）') : '')],
            ['label' => '班组得分', 'value' => PdfHelper::fmtScore($p['team_score'])
                . ($p['team_level_name'] ? ('（' . $p['team_level_name'] . '）') : '')],
            ['label' => '答题进度', 'value' => '已答 ' . (int)$detail['answered_cnt']
                . ' / 共 ' . (int)$detail['question_cnt'] . ' 题'],
        ]);
        if (!empty($p['personal_feature'])) {
            PdfHelper::writeSection($pdf, '等级特征');
            PdfHelper::writeParagraph($pdf, $p['personal_feature']);
        }
        if ($chartPath) {
            PdfHelper::writeSection($pdf, '指标得分率');
            PdfHelper::writeImage($pdf, $chartPath, 186);
        }

        PdfHelper::writeSection($pdf, '逐题作答结果');
        $arows = [];
        foreach ($answers as $a) {
            $answered = !empty($a['is_answered']);
            $arows[] = [
                isset($a['sort_no']) ? $a['sort_no'] : '',
                isset($a['indicator_name']) ? $a['indicator_name'] : '—',
                isset($a['question_title']) ? $a['question_title'] : ('题目#' . $a['question_id']),
                $answered
                    ? (isset($a['option_content']) ? $a['option_content'] : '—')
                    : '未作答',
                $answered ? PdfHelper::fmtScore($a['item_score']) : '—',
                PdfHelper::fmtScore($a['weight']),
            ];
        }
        PdfHelper::writeTable(
            $pdf,
            ['题号', '指标', '题目', '所选答案', '得分', '满分'],
            $arows,
            [8, 16, 34, 22, 10, 10]
        );
        PdfHelper::cleanupImages($chartPath);
        PdfHelper::download(
            $pdf,
            '个人答卷_' . PdfHelper::safeFilename(($p['member_name'] ?: 'member') . '_' . ($p['batch_name'] ?: 'batch')) . '.pdf'
        );
        return [true, 'ok'];
    }

    /**
     * 导出个人答卷 Word（含指标得分率图）。
     *
     * @param int $paperId
     * @return array [bool ok, string msg]
     */
    public static function exportPaperDocx($paperId)
    {
        list($ok, $msg, $detail) = self::paperDetail($paperId);
        if (!$ok) {
            return [false, $msg];
        }
        $p = $detail['paper'];
        $answers = isset($detail['answers']) ? $detail['answers'] : [];
        $sub = ($p['batch_name'] ?: '') . ' · ' . ($p['team_name'] ?: '') . ' · ' . ($p['member_name'] ?: '');
        $chartPath = null;
        try {
            $indList = self::paperIndicatorChartList($answers);
            if ($indList) {
                $chartPath = PdfChartHelper::renderRankBars($indList, 15, '指标得分率');
            } else {
                $cmp = [];
                if ($p['personal_score'] !== null && $p['personal_score'] !== '') {
                    $cmp[] = ['team_name' => '个人得分', 'team_score' => (float)$p['personal_score']];
                }
                if ($p['team_score'] !== null && $p['team_score'] !== '') {
                    $cmp[] = ['team_name' => '班组得分', 'team_score' => (float)$p['team_score']];
                }
                if ($cmp) {
                    $chartPath = PdfChartHelper::renderRankBars($cmp, 2, '得分对比');
                }
            }
        } catch (\Exception $e) {
            $chartPath = null;
        }
        $doc = DocxHelper::create('个人测评答卷', $sub);
        $doc->writeMeta([
            ['label' => '批次', 'value' => $p['batch_name']],
            ['label' => '班组', 'value' => $p['team_name']],
            ['label' => '成员', 'value' => $p['member_name']],
            ['label' => '答卷状态', 'value' => $p['status_text']],
            ['label' => '提交时间', 'value' => $p['submitted_at'] ?: '—'],
            ['label' => '个人得分', 'value' => PdfHelper::fmtScore($p['personal_score'])
                . ($p['personal_level_name'] ? ('（' . $p['personal_level_name'] . '）') : '')],
            ['label' => '班组得分', 'value' => PdfHelper::fmtScore($p['team_score'])
                . ($p['team_level_name'] ? ('（' . $p['team_level_name'] . '）') : '')],
            ['label' => '答题进度', 'value' => '已答 ' . (int)$detail['answered_cnt']
                . ' / 共 ' . (int)$detail['question_cnt'] . ' 题'],
        ]);
        if (!empty($p['personal_feature'])) {
            $doc->writeSection('等级特征');
            $doc->writeParagraph($p['personal_feature']);
        }
        if ($chartPath) {
            $doc->writeSection('指标得分率');
            $doc->writeImage($chartPath, 5486400, '指标得分率');
        }
        $doc->writeSection('逐题作答结果');
        $arows = [];
        foreach ($answers as $a) {
            $answered = !empty($a['is_answered']);
            $arows[] = [
                isset($a['sort_no']) ? $a['sort_no'] : '',
                isset($a['indicator_name']) ? $a['indicator_name'] : '—',
                isset($a['question_title']) ? $a['question_title'] : ('题目#' . $a['question_id']),
                $answered
                    ? (isset($a['option_content']) ? $a['option_content'] : '—')
                    : '未作答',
                $answered ? PdfHelper::fmtScore($a['item_score']) : '—',
                PdfHelper::fmtScore($a['weight']),
            ];
        }
        $doc->writeTable(['题号', '指标', '题目', '所选答案', '得分', '满分'], $arows);
        $doc->download(
            '个人答卷_' . DocxHelper::safeFilename(($p['member_name'] ?: 'member') . '_' . ($p['batch_name'] ?: 'batch')) . '.docx'
        );
        return [true, 'ok'];
    }

    /**
     * 组装答卷行数据（含状态文案、个人等级、已答题数等）。
     *
     * @param int         $batchId 批次 ID
     * @param int         $teamId  班组 ID；0 表示不限
     * @param string      $keyword 成员姓名关键字
     * @param int|null    $status  答卷状态；null 表示不限
     * @return array 答卷行列表，每项含 paper_id、member_name、team_name、personal_score、status_text 等
     */
    private static function paperRows($batchId, $teamId = 0, $keyword = '', $status = null)
    {
        $q = Db::name('tm_paper')->alias('p')
            ->join('tm_member m', 'm.id = p.member_id', 'LEFT')
            ->join('tm_team t', 't.id = p.team_id', 'LEFT')
            ->where('p.batch_id', (int)$batchId)
            ->field('p.id as paper_id, p.batch_id, p.team_id, p.member_id, p.status, p.personal_score, p.submitted_at, p.created_at,
                m.name as member_name, m.role_code, t.name as team_name');
        if ($teamId > 0) {
            $q->where('p.team_id', (int)$teamId);
        }
        if ($status !== null && $status !== '') {
            $q->where('p.status', (int)$status);
        }
        if ($keyword !== '') {
            $q->where('m.name', 'like', '%' . $keyword . '%');
        }
        $rows = $q->order('p.personal_score desc,p.id desc')->select();
        if (empty($rows)) {
            return [];
        }

        $paperIds = [];
        foreach ($rows as $row) {
            $paperIds[] = (int)$row['paper_id'];
        }
        $answerCntMap = Db::name('tm_paper_answer')
            ->where('paper_id', 'in', $paperIds)
            ->group('paper_id')
            ->column('COUNT(*)', 'paper_id');

        $roleLabels = tmRoleLabels();
        foreach ($rows as &$row) {
            $pid = (int)$row['paper_id'];
            $row['status'] = (int)$row['status'];
            $row['status_text'] = $row['status'] === 1 ? '已提交' : '答题中';
            $lv = null;
            if ($row['status'] === 1 && $row['personal_score'] !== null && $row['personal_score'] !== '') {
                $lv = LevelJudgeService::judge($row['personal_score']);
            }
            $row['personal_level_code'] = $lv ? $lv['level_code'] : '';
            $row['personal_level_name'] = $lv ? $lv['level_name'] : '';
            $row['role_name'] = isset($roleLabels[$row['role_code']]) ? $roleLabels[$row['role_code']] : ($row['role_code'] ?: '');
            $row['answered_cnt'] = isset($answerCntMap[$pid]) ? (int)$answerCntMap[$pid] : 0;
        }
        unset($row);
        return $rows;
    }
}
