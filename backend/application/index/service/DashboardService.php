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

    /** @var array|null 请求内等级元数据缓存 */
    private static $levelsMetaCache = null;

    /**
     * 列出已有班组算分结果的测评批次。
     *
     * @return array 批次列表，每项含 id、name、start_time、end_time、status、score_status 及对应中文文案
     */
    public static function listBatchesWithResult()
    {
        // DISTINCT 比先 group 再 in 查询更省一轮
        $list = Db::name('tm_team_result')->alias('tr')
            ->join('tm_batch b', 'b.id = tr.batch_id')
            ->whereNull('b.deleted_at')
            ->field('b.id, b.name, b.start_time, b.end_time, b.status, b.score_status')
            ->group('b.id')
            ->order('b.id desc')
            ->select();
        foreach ($list as &$b) {
            $b['status_text'] = BatchService::statusText($b['status']);
            $b['score_status_text'] = BatchService::scoreStatusText($b['score_status']);
        }
        unset($b);
        return $list ?: [];
    }

    /**
     * 获取成熟度等级元数据（阈值配置或内置默认四级）。
     *
     * @return array 等级行列表，每项含 level_code、level_name、sort_no 等
     */
    public static function levelsMeta()
    {
        if (self::$levelsMetaCache !== null) {
            return self::$levelsMetaCache;
        }
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
        self::$levelsMetaCache = $levelsMeta;
        return self::$levelsMetaCache;
    }

    /**
     * 解析组织下可统计的班组 ID；未选组织返回 null（表示不限）。
     *
     * @param int $orgId
     * @return array|null
     */
    private static function resolveTeamIdsByOrg($orgId)
    {
        $orgId = (int)$orgId;
        if ($orgId <= 0) {
            return null;
        }
        $orgIds = (new OrgModel())->collectDescendantIds($orgId);
        $teamIds = Db::name('tm_team')
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->where('org_id', 'in', $orgIds)
            ->column('id');
        return !empty($teamIds) ? array_map('intval', $teamIds) : [-1];
    }

    /**
     * 组织下可选班组（趋势页勾选用，避免首屏塞全量班组）。
     *
     * @param int $orgId
     * @return array
     */
    public static function teamsForOrg($orgId)
    {
        $orgId = (int)$orgId;
        if ($orgId <= 0) {
            return [];
        }
        $orgIds = (new OrgModel())->collectDescendantIds($orgId);
        $rows = Db::name('tm_team')
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->where('org_id', 'in', $orgIds)
            ->order('id asc')
            ->field('id, name, org_id')
            ->select();
        return $rows ?: [];
    }

    /**
     * 统计指定批次（及可选组织）下的成熟度等级分布与班组明细。
     *
     * 图表/KPI 走 SQL 聚合；班组清单支持分页（page/limit，page=0 返回全部供导出）。
     *
     * @param int $batchId 批次 ID；0 表示取最近有算分结果的批次
     * @param int $orgId   组织 ID；0 表示全公司，>0 时仅统计该组织及下级班组
     * @param int $page    班组清单页码；0 表示全部
     * @param int $limit   每页条数
     * @return array
     */
    public static function levelDist($batchId = 0, $orgId = 0, $page = 1, $limit = 10)
    {
        if ($batchId <= 0) {
            $batchId = (int)Db::name('tm_team_result')->order('scored_at desc,id desc')->value('batch_id');
        }
        $batch = $batchId > 0 ? BatchService::find($batchId) : null;
        $teamIds = self::resolveTeamIdsByOrg($orgId);

        $countMap = [];
        $sum = 0.0;
        $n = 0;
        $scoredAt = '';
        $detailTeams = [];
        $teamListCount = 0;

        if ($batchId > 0) {
            // KPI / 饼图：数据库聚合，避免把全部班组拉进内存
            $aggQ = Db::name('tm_team_result')->alias('tr')
                ->where('tr.batch_id', $batchId);
            if ($teamIds !== null) {
                $aggQ->where('tr.team_id', 'in', $teamIds);
            }
            $aggRows = $aggQ
                ->field('tr.level_code, COUNT(*) as cnt, SUM(tr.team_score) as score_sum, MAX(tr.scored_at) as last_scored')
                ->group('tr.level_code')
                ->select();
            foreach ($aggRows as $ar) {
                $code = $ar['level_code'];
                $cnt = (int)$ar['cnt'];
                $countMap[$code] = $cnt;
                $sum += (float)$ar['score_sum'];
                $n += $cnt;
                if ($scoredAt === '' || (string)$ar['last_scored'] > $scoredAt) {
                    $scoredAt = (string)$ar['last_scored'];
                }
            }

            $listQ = Db::name('tm_team_result')->alias('tr')
                ->join('tm_team t', 't.id = tr.team_id', 'LEFT')
                ->where('tr.batch_id', $batchId)
                ->field('tr.team_id, tr.team_score, tr.level_code, t.name as team_name');
            if ($teamIds !== null) {
                $listQ->where('tr.team_id', 'in', $teamIds);
            }
            $countQ = Db::name('tm_team_result')->alias('tr')->where('tr.batch_id', $batchId);
            if ($teamIds !== null) {
                $countQ->where('tr.team_id', 'in', $teamIds);
            }
            $teamListCount = (int)$countQ->count();

            $listQ->order('tr.team_score desc, tr.team_id asc');
            $page = (int)$page;
            $limit = (int)$limit;
            if ($page > 0 && $limit > 0) {
                $listQ->page($page, $limit);
            }
            $rows = $listQ->select();

            $levelNames = [];
            foreach (self::levelsMeta() as $m) {
                $levelNames[$m['level_code']] = $m['level_name'];
            }
            foreach ($rows as $row) {
                $code = $row['level_code'];
                $detailTeams[] = [
                    'team_id'    => (int)$row['team_id'],
                    'team_name'  => $row['team_name'],
                    'team_score' => $row['team_score'],
                    'level_code' => $code,
                    'level_name' => isset($levelNames[$code]) ? $levelNames[$code] : $code,
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
            'team_count'      => $teamListCount,
        ];
    }

    /**
     * 生成班组跨批次得分成长趋势（折线图数据）。
     *
     * 规则：
     * - 勾选了班组：按勾选全部展示（最多 100，防异常）
     * - 未勾选但选了组织/集团/公司：展示该组织及下级全部班组（不限 8）
     * - 未勾选且未选组织：取最近批次高分班组，最多 $limitTeams 个
     *
     * @param int   $orgId        按组织筛班组；0 表示未选组织
     * @param array $teamIds      指定班组 ID 列表（优先）
     * @param int   $limitTeams   仅「未选组织且未勾班组」时的默认上限
     * @param int   $limitBatches 最多纳入的历史批次数
     * @return array 含 batches、series、tip
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
        $explicitTeams = !empty($teamIds);
        $tipSuffix = '';

        if (!$explicitTeams) {
            if ($orgId > 0) {
                $orgIds = (new OrgModel())->collectDescendantIds($orgId);
                $teamIds = Db::name('tm_team')
                    ->whereNull('deleted_at')
                    ->where('status', 1)
                    ->where('org_id', 'in', $orgIds)
                    ->order('id asc')
                    ->column('id');
            } else {
                $latest = end($batchIds);
                $teamIds = Db::name('tm_team_result')
                    ->where('batch_id', $latest)
                    ->order('team_score desc')
                    ->limit($limitTeams)
                    ->column('team_id');
                $tipSuffix = '；未选组织时默认展示最近批次高分班组（最多 ' . (int)$limitTeams . ' 个）';
            }
        }

        $teamIds = array_values(array_unique(array_map('intval', $teamIds ?: [])));
        // 勾选或组织下钻：图表系列过多会卡，硬顶 40（可勾选缩小范围）
        $maxTeams = ($explicitTeams || $orgId > 0) ? 40 : (int)$limitTeams;
        if (count($teamIds) > $maxTeams) {
            $teamIds = array_slice($teamIds, 0, $maxTeams);
            $tipSuffix .= '；班组过多，仅展示前 ' . $maxTeams . ' 个';
        }
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
            'batches'    => $orderedBatches,
            'series'     => $series,
            'team_count' => count($series),
            'tip'        => '展示各班组跨批次得分变化；空点表示该批次未参评或未算分' . $tipSuffix,
        ];
    }

    /**
     * 按批次生成班组得分排行榜（支持分页；图表用 chart_top 固定取前 N）。
     *
     * @param int $batchId
     * @param int $orgId
     * @param int $page   页码；0 表示按 limit 取前 N（兼容旧调用）
     * @param int $limit  每页/条数上限
     * @param int $chartTop 柱状图专用条数
     * @return array
     */
    public static function rank($batchId = 0, $orgId = 0, $page = 1, $limit = 10, $chartTop = 15)
    {
        if ($batchId <= 0) {
            $batchId = (int)Db::name('tm_team_result')->order('scored_at desc,id desc')->value('batch_id');
        }
        $batch = $batchId > 0 ? BatchService::find($batchId) : null;
        if ($batchId <= 0 || !$batch) {
            return [
                'batch'     => null,
                'list'      => [],
                'chart_top' => [],
                'count'     => 0,
                'tip'       => '暂无已算分批次',
            ];
        }

        $base = function () use ($batchId, $orgId) {
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
            return $q;
        };

        $countQ = Db::name('tm_team_result')->alias('tr')
            ->join('tm_team t', 't.id = tr.team_id', 'LEFT')
            ->where('tr.batch_id', $batchId);
        if ($orgId > 0) {
            $orgIds = (new OrgModel())->collectDescendantIds($orgId);
            $countQ->where('t.org_id', 'in', $orgIds);
        }
        $total = (int)$countQ->count();

        $page = (int)$page;
        $limit = (int)$limit;
        if ($limit < 1) {
            $limit = 10;
        }
        $chartTop = max(1, (int)$chartTop);

        $levelNames = LevelJudgeService::nameMap();
        $mapRow = function ($r, $rankNo) use ($levelNames) {
            $code = $r['level_code'];
            return [
                'rank_no'     => $rankNo,
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
        };

        // 图表固定取全局 Top N（与分页无关）
        $chartRows = $base()->order('tr.team_score desc, tr.team_id asc')->limit($chartTop)->select();
        $chartList = [];
        $cr = 0;
        foreach ($chartRows as $r) {
            $cr++;
            $chartList[] = $mapRow($r, $cr);
        }

        if ($page <= 0) {
            // 兼容导出：取前 $limit 条
            $rows = $base()->order('tr.team_score desc, tr.team_id asc')->limit($limit)->select();
            $offset = 0;
        } else {
            $offset = ($page - 1) * $limit;
            $rows = $base()->order('tr.team_score desc, tr.team_id asc')->limit($offset, $limit)->select();
        }

        $list = [];
        $rank = $offset;
        foreach ($rows as $r) {
            $rank++;
            $list[] = $mapRow($r, $rank);
        }

        return [
            'batch' => [
                'id'         => (int)$batch['id'],
                'name'       => $batch['name'],
                'start_time' => $batch['start_time'],
                'end_time'   => $batch['end_time'],
            ],
            'list'      => $list,
            'chart_top' => $chartList,
            'count'     => $total,
            'tip'       => '按班组得分降序；同分按班组 ID 稳定排序',
        ];
    }
}
