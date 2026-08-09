<?php
namespace app\index\service;

use app\index\model\OrgModel;
use think\Db;

/**
 * 统计分析：成熟度分布、成长趋势、班组排行。
 */
class DashboardService
{
    private static $levelAdvice = [
        'essential'   => '巩固提升',
        'independent' => '重点培育',
        'dependent'   => '加强帮扶',
        'passive'     => '专项整改',
    ];

    /**
     * 列出已有班组算分结果的测评批次。
     *
     * @return array 批次列表，每项含 id、name、start_time、end_time、status、score_status 及对应中文文案
     */
    public static function listBatchesWithResult()
    {
        $ids = Db::name('tm_team_result')->group('batch_id')->column('batch_id');
        if (empty($ids)) {
            return [];
        }
        $list = Db::name('tm_batch')
            ->where('id', 'in', $ids)
            ->whereNull('deleted_at')
            ->field('id, name, start_time, end_time, status, score_status')
            ->order('id desc')
            ->select();
        foreach ($list as &$b) {
            $b['status_text'] = BatchService::statusText($b['status']);
            $b['score_status_text'] = BatchService::scoreStatusText($b['score_status']);
        }
        unset($b);
        return $list;
    }

    /**
     * 获取成熟度等级元数据（阈值配置或内置默认四级）。
     *
     * @return array 等级行列表，每项含 level_code、level_name、sort_no 等
     */
    public static function levelsMeta()
    {
        $levelsMeta = Db::name('tm_level_threshold')
            ->where('status', 1)
            ->order('sort_no desc,id asc')
            ->select();
        if (!$levelsMeta) {
            $levelsMeta = [
                ['level_code' => 'essential', 'level_name' => '本质安全型', 'sort_no' => 4],
                ['level_code' => 'independent', 'level_name' => '自立型', 'sort_no' => 3],
                ['level_code' => 'dependent', 'level_name' => '依赖型', 'sort_no' => 2],
                ['level_code' => 'passive', 'level_name' => '被动型', 'sort_no' => 1],
            ];
        }
        return $levelsMeta;
    }

    /**
     * 统计指定批次（及可选组织）下的成熟度等级分布与班组明细。
     *
     * @param int $batchId 批次 ID；0 表示取最近有算分结果的批次
     * @param int $orgId   组织 ID；0 表示全公司，>0 时仅统计该组织及下级班组
     * @return array 含 batch_id、batch_name、scored_at、org_id、team_total、result_team_cnt、
     *               avg_score、essential_cnt、passive_cnt、levels（各级占比）、teams（班组明细）
     */
    public static function levelDist($batchId = 0, $orgId = 0)
    {
        if ($batchId <= 0) {
            $batchId = (int)Db::name('tm_team_result')->order('scored_at desc,id desc')->value('batch_id');
        }
        $batch = $batchId > 0 ? BatchService::find($batchId) : null;
        $teamIds = null;
        if ($orgId > 0) {
            $orgIds = (new OrgModel())->collectDescendantIds($orgId);
            $teamIds = Db::name('tm_team')
                ->whereNull('deleted_at')
                ->where('status', 1)
                ->where('org_id', 'in', $orgIds)
                ->column('id');
            if (empty($teamIds)) {
                $teamIds = [-1];
            }
        }

        $countMap = [];
        $sum = 0.0;
        $n = 0;
        $scoredAt = '';
        $detailTeams = [];

        if ($batchId > 0) {
            $q = Db::name('tm_team_result')->alias('tr')
                ->join('tm_team t', 't.id = tr.team_id', 'LEFT')
                ->where('tr.batch_id', $batchId)
                ->field('tr.*, t.name as team_name, t.org_id');
            if ($teamIds !== null) {
                $q->where('tr.team_id', 'in', $teamIds);
            }
            $rows = $q->select();
            foreach ($rows as $row) {
                $code = $row['level_code'];
                if (!isset($countMap[$code])) {
                    $countMap[$code] = 0;
                }
                $countMap[$code]++;
                $sum += (float)$row['team_score'];
                $n++;
                if ($scoredAt === '' || $row['scored_at'] > $scoredAt) {
                    $scoredAt = $row['scored_at'];
                }
                $detailTeams[] = [
                    'team_id'    => (int)$row['team_id'],
                    'team_name'  => $row['team_name'],
                    'team_score' => $row['team_score'],
                    'level_code' => $code,
                ];
            }
        }

        $levels = [];
        foreach (self::levelsMeta() as $meta) {
            $code = $meta['level_code'];
            $cnt = isset($countMap[$code]) ? (int)$countMap[$code] : 0;
            $percent = $n > 0 ? round($cnt * 100 / $n, 1) : 0;
            $levels[] = [
                'level_code'   => $code,
                'level_name'   => $meta['level_name'],
                'count'        => $cnt,
                'percent'      => $percent,
                'percent_text' => number_format($percent, 1) . '%',
                'advice'       => isset(self::$levelAdvice[$code]) ? self::$levelAdvice[$code] : '',
            ];
        }

        $levelNames = [];
        foreach (self::levelsMeta() as $m) {
            $levelNames[$m['level_code']] = $m['level_name'];
        }
        foreach ($detailTeams as &$dt) {
            $dt['level_name'] = isset($levelNames[$dt['level_code']]) ? $levelNames[$dt['level_code']] : $dt['level_code'];
        }
        unset($dt);
        usort($detailTeams, function ($a, $b) {
            $sa = (float)$a['team_score'];
            $sb = (float)$b['team_score'];
            if ($sa == $sb) {
                return 0;
            }
            return ($sb > $sa) ? 1 : -1;
        });

        $teamTotal = (int)Db::name('tm_team')->whereNull('deleted_at')->where('status', 1)->count();
        if ($teamIds !== null && $teamIds !== [-1]) {
            $teamTotal = count($teamIds);
        } elseif ($teamIds === [-1]) {
            $teamTotal = 0;
        }

        return [
            'batch_id'        => $batchId ?: null,
            'batch_name'      => $batch ? $batch['name'] : '',
            'scored_at'       => $scoredAt,
            'org_id'          => $orgId ?: null,
            'team_total'      => $teamTotal,
            'result_team_cnt' => $n,
            'avg_score'       => $n > 0 ? round($sum / $n, 1) : null,
            'essential_cnt'   => isset($countMap['essential']) ? (int)$countMap['essential'] : 0,
            'passive_cnt'     => isset($countMap['passive']) ? (int)$countMap['passive'] : 0,
            'levels'          => $levels,
            'teams'           => $detailTeams,
        ];
    }

    /**
     * 生成班组跨批次得分成长趋势（折线图数据）。
     *
     * @param int   $orgId        按组织筛班组；0 表示全公司，未指定 teamIds 时取最近批次高分班组
     * @param array $teamIds      指定班组 ID 列表（优先于 orgId 筛选）
     * @param int   $limitTeams   最多展示的班组数量
     * @param int   $limitBatches 最多纳入的历史批次数
     * @return array 含 batches（横轴批次）、series（各班组 scores 序列）、tip（说明文案）
     */
    public static function trend($orgId = 0, array $teamIds = [], $limitTeams = 8, $limitBatches = 12)
    {
        $batchIds = Db::name('tm_team_result')
            ->group('batch_id')
            ->order('batch_id desc')
            ->limit($limitBatches)
            ->column('batch_id');
        $batchIds = array_reverse(array_map('intval', $batchIds ?: []));
        if (empty($batchIds)) {
            return [
                'batches' => [],
                'series'  => [],
                'tip'     => '暂无已算分批次，完成测评并算分后可看趋势',
            ];
        }

        $batches = Db::name('tm_batch')
            ->where('id', 'in', $batchIds)
            ->whereNull('deleted_at')
            ->field('id, name, start_time')
            ->select();
        $batchMap = [];
        foreach ($batches as $b) {
            $batchMap[(int)$b['id']] = $b;
        }
        // 保持时间顺序
        $orderedBatches = [];
        foreach ($batchIds as $bid) {
            if (isset($batchMap[$bid])) {
                $orderedBatches[] = [
                    'batch_id'   => $bid,
                    'batch_name' => $batchMap[$bid]['name'],
                    'start_time' => $batchMap[$bid]['start_time'],
                ];
            }
        }

        $teamIds = array_values(array_unique(array_filter(array_map('intval', $teamIds))));
        if (empty($teamIds)) {
            if ($orgId > 0) {
                $orgIds = (new OrgModel())->collectDescendantIds($orgId);
                $teamIds = Db::name('tm_team')
                    ->whereNull('deleted_at')
                    ->where('status', 1)
                    ->where('org_id', 'in', $orgIds)
                    ->column('id');
            }
            if (empty($teamIds)) {
                // 取最近批次得分最高的若干班组
                $latest = end($batchIds);
                $teamIds = Db::name('tm_team_result')
                    ->where('batch_id', $latest)
                    ->order('team_score desc')
                    ->limit($limitTeams)
                    ->column('team_id');
            }
        }
        $teamIds = array_slice(array_values(array_unique(array_map('intval', $teamIds))), 0, $limitTeams);
        if (empty($teamIds)) {
            return [
                'batches' => $orderedBatches,
                'series'  => [],
                'tip'     => '暂无可展示班组',
            ];
        }

        $teamNames = Db::name('tm_team')->where('id', 'in', $teamIds)->column('name', 'id');
        $scoreRows = Db::name('tm_team_result')
            ->where('batch_id', 'in', $batchIds)
            ->where('team_id', 'in', $teamIds)
            ->field('batch_id, team_id, team_score, level_code')
            ->select();
        $scoreMap = [];
        foreach ($scoreRows as $r) {
            $scoreMap[(int)$r['team_id']][(int)$r['batch_id']] = [
                'score' => (float)$r['team_score'],
                'level' => $r['level_code'],
            ];
        }

        $series = [];
        foreach ($teamIds as $tid) {
            $points = [];
            foreach ($batchIds as $bid) {
                if (isset($scoreMap[$tid][$bid])) {
                    $points[] = $scoreMap[$tid][$bid]['score'];
                } else {
                    $points[] = null;
                }
            }
            $series[] = [
                'team_id'   => $tid,
                'team_name' => isset($teamNames[$tid]) ? $teamNames[$tid] : ('班组#' . $tid),
                'scores'    => $points,
            ];
        }

        return [
            'batches' => $orderedBatches,
            'series'  => $series,
            'tip'     => '展示各班组跨批次得分变化；空点表示该批次未参评或未算分',
        ];
    }

    /**
     * 按批次生成班组得分排行榜。
     *
     * @param int $batchId 批次 ID；0 表示取最近有算分结果的批次
     * @param int $orgId   组织 ID；0 表示不限，>0 时仅统计该组织及下级班组
     * @param int $limit   返回条数上限
     * @return array 含 batch（批次摘要）、list（排名行：rank_no、team_name、team_score、level 等）、tip
     */
    public static function rank($batchId = 0, $orgId = 0, $limit = 50)
    {
        if ($batchId <= 0) {
            $batchId = (int)Db::name('tm_team_result')->order('scored_at desc,id desc')->value('batch_id');
        }
        $batch = $batchId > 0 ? BatchService::find($batchId) : null;
        if ($batchId <= 0 || !$batch) {
            return [
                'batch' => null,
                'list'  => [],
                'tip'   => '暂无已算分批次',
            ];
        }

        $q = Db::name('tm_team_result')->alias('tr')
            ->join('tm_team t', 't.id = tr.team_id', 'LEFT')
            ->join('tm_org o', 'o.id = t.org_id', 'LEFT')
            ->where('tr.batch_id', $batchId)
            ->field('tr.team_id, tr.team_score, tr.level_code, tr.submit_cnt, tr.scored_at,
                t.name as team_name, o.name as org_name, t.org_id');
        if ($orgId > 0) {
            $orgIds = (new OrgModel())->collectDescendantIds($orgId);
            $q->where('t.org_id', 'in', $orgIds);
        }
        $rows = $q->order('tr.team_score desc, tr.team_id asc')->limit($limit)->select();

        $levelNames = Db::name('tm_level_threshold')->column('level_name', 'level_code');
        $list = [];
        $rank = 0;
        foreach ($rows as $r) {
            $rank++;
            $code = $r['level_code'];
            $list[] = [
                'rank_no'     => $rank,
                'team_id'     => (int)$r['team_id'],
                'team_name'   => $r['team_name'],
                'org_name'    => $r['org_name'] ?: '',
                'team_score'  => $r['team_score'],
                'level_code'  => $code,
                'level_name'  => isset($levelNames[$code]) ? $levelNames[$code] : $code,
                'submit_cnt'  => (int)$r['submit_cnt'],
                'scored_at'   => $r['scored_at'],
                'advice'      => isset(self::$levelAdvice[$code]) ? self::$levelAdvice[$code] : '',
            ];
        }

        return [
            'batch' => [
                'id'         => (int)$batch['id'],
                'name'       => $batch['name'],
                'start_time' => $batch['start_time'],
                'end_time'   => $batch['end_time'],
            ],
            'list' => $list,
            'tip'  => '按班组得分降序；同分按班组 ID 稳定排序',
        ];
    }
}
