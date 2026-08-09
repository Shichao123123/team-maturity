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
     * @return array [bool ok, string msg, array|null data] data 含 batch、list、count
     */
    public static function teamList($batchId, $keyword = '', $levelCode = '')
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
    public static function paperList($batchId, $teamId = 0, $keyword = '', $status = null)
    {
        $batch = BatchService::find($batchId);
        if (!$batch) {
            return [false, '批次不存在', null];
        }
        $rows = self::paperRows($batchId, $teamId, $keyword, $status);
        return [true, 'ok', [
            'batch_id' => (int)$batchId,
            'list'     => $rows,
            'count'    => count($rows),
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