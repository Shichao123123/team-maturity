<?php
namespace app\index\service;

use app\index\model\OrgModel;
use think\Db;

/**
 * 全国运营看板：企业级聚合、地图点位、企业详情。
 * 「企业」口径：org_type=1（公司/集团）的启用组织。
 */
class NationalDashboardService
{
    /** 省会/直辖市中心点（无精确坐标时回退） */
    private static $provinceCenter = [
        '北京市' => [116.4074, 39.9042],
        '天津市' => [117.2010, 39.0842],
        '河北省' => [114.5025, 38.0455],
        '山西省' => [112.5492, 37.8570],
        '内蒙古自治区' => [111.6708, 40.8183],
        '辽宁省' => [123.4291, 41.7968],
        '吉林省' => [125.3245, 43.8868],
        '黑龙江省' => [126.6425, 45.7569],
        '上海市' => [121.4737, 31.2304],
        '江苏省' => [118.7674, 32.0415],
        '浙江省' => [120.1536, 30.2875],
        '安徽省' => [117.2830, 31.8612],
        '福建省' => [119.3062, 26.0753],
        '江西省' => [115.8922, 28.6765],
        '山东省' => [117.0009, 36.6758],
        '河南省' => [113.6654, 34.7579],
        '湖北省' => [114.2986, 30.5844],
        '湖南省' => [112.9823, 28.1941],
        '广东省' => [113.2644, 23.1291],
        '广西壮族自治区' => [108.3200, 22.8240],
        '海南省' => [110.3312, 20.0319],
        '重庆市' => [106.5516, 29.5630],
        '四川省' => [104.0657, 30.6595],
        '贵州省' => [106.7135, 26.5783],
        '云南省' => [102.7123, 25.0406],
        '西藏自治区' => [91.1322, 29.6604],
        '陕西省' => [108.9480, 34.2632],
        '甘肃省' => [103.8236, 36.0580],
        '青海省' => [101.7789, 36.6232],
        '宁夏回族自治区' => [106.2782, 38.4664],
        '新疆维吾尔自治区' => [87.6177, 43.7928],
    ];

    private static $levelColors = [
        'essential'   => '#52c41a',
        'independent' => '#1677ff',
        'dependent'   => '#faad14',
        'passive'     => '#ff4d4f',
        ''            => '#94a3b8',
    ];

    /**
     * 筛选项：行业、省份、等级。
     *
     * @return array
     */
    public static function filterOptions()
    {
        $industries = Db::name('tm_org')
            ->where('org_type', 1)
            ->where('status', 1)
            ->where('is_demo', 0)
            ->whereNull('deleted_at')
            ->where('industry', '<>', '')
            ->whereNotNull('industry')
            ->group('industry')
            ->column('industry');
        $provinces = Db::name('tm_org')
            ->where('org_type', 1)
            ->where('status', 1)
            ->where('is_demo', 0)
            ->whereNull('deleted_at')
            ->where('province', '<>', '')
            ->whereNotNull('province')
            ->group('province')
            ->column('province');
        $levels = [];
        foreach (DashboardService::levelsMeta() as $lv) {
            $levels[] = [
                'level_code' => $lv['level_code'],
                'level_name' => $lv['level_name'],
            ];
        }
        return [
            'industries' => array_values(array_filter($industries ?: [])),
            'provinces'  => array_values(array_filter($provinces ?: [])),
            'levels'     => $levels,
        ];
    }

    /**
     * 全国大盘总览数据。
     *
     * @param array $filters industry/province/level_code/date_from/date_to/keyword
     * @return array
     */
    public static function overview(array $filters = [])
    {
        $companies = self::companyAggregates($filters);
        $withScore = array_values(array_filter($companies, function ($c) {
            return $c['score'] !== null;
        }));

        $levelMeta = DashboardService::levelsMeta();
        $levelCounts = [];
        foreach ($levelMeta as $lv) {
            $levelCounts[$lv['level_code']] = [
                'level_code' => $lv['level_code'],
                'level_name' => $lv['level_name'],
                'count'      => 0,
                'percent'    => 0,
                'percent_text' => '0%',
            ];
        }
        foreach ($withScore as $c) {
            $code = $c['level_code'] ?: '';
            if ($code !== '' && isset($levelCounts[$code])) {
                $levelCounts[$code]['count']++;
            }
        }
        $scoredCnt = count($withScore);
        foreach ($levelCounts as &$lv) {
            $pct = $scoredCnt > 0 ? round(100 * $lv['count'] / $scoredCnt, 1) : 0;
            $lv['percent'] = $pct;
            $lv['percent_text'] = $pct . '%';
        }
        unset($lv);

        $sumScore = 0.0;
        $evalTotal = 0;
        $paperTotal = 0;
        foreach ($withScore as $c) {
            $sumScore += (float)$c['score'];
            $evalTotal += (int)$c['eval_cnt'];
            $paperTotal += (int)$c['paper_cnt'];
        }
        $avgScore = $scoredCnt > 0 ? round($sumScore / $scoredCnt, 2) : null;

        $mapPoints = [];
        foreach ($companies as $c) {
            if ($c['lng'] === null || $c['lat'] === null) {
                continue;
            }
            $mapPoints[] = [
                'org_id'     => $c['org_id'],
                'name'       => $c['name'],
                'value'      => [(float)$c['lng'], (float)$c['lat'], $c['score'] === null ? 0 : (float)$c['score']],
                'score'      => $c['score'],
                'level_code' => $c['level_code'],
                'level_name' => $c['level_name'],
                'eval_cnt'   => $c['eval_cnt'],
                'industry'   => $c['industry'],
                'province'   => $c['province'],
                'city'       => $c['city'],
                'color'      => self::levelColor($c['level_code']),
                'has_score'  => $c['score'] !== null,
            ];
        }

        $l1Radar = self::l1RadarScores(null, $filters);
        $weakTop10 = self::weakTop10($filters);

        return [
            'kpi' => [
                'company_cnt'   => count($companies),
                'scored_cnt'    => $scoredCnt,
                'eval_cnt'      => $evalTotal,
                'paper_cnt'     => $paperTotal,
                'avg_score'     => $avgScore,
                'avg_score_text'=> $avgScore === null ? '—' : number_format($avgScore, 1, '.', ''),
            ],
            'levels'     => array_values($levelCounts),
            'companies'  => $companies,
            'map_points' => $mapPoints,
            'l1_radar'   => $l1Radar,
            'weak_top10' => $weakTop10,
            'tip'        => '企业=组织类型「公司/集团」；得分=下属班组在筛选期内最近一次算分结果的均分。地图需维护经纬度（或省份回退到省会）。',
        ];
    }

    /**
     * 行业二级指标短板 TOP10，并附带该指标下失分最多的原始问题。
     *
     * @param array $filters
     * @return array
     */
    public static function weakTop10(array $filters = [])
    {
        $orgQ = Db::name('tm_org')->where('org_type', 1)->where('status', 1)->where('is_demo', 0)->whereNull('deleted_at');
        if (!empty($filters['industry'])) $orgQ->where('industry', $filters['industry']);
        if (!empty($filters['province'])) $orgQ->where('province', $filters['province']);
        if (!empty($filters['keyword'])) $orgQ->where('name', 'like', '%' . $filters['keyword'] . '%');
        $orgModel = new OrgModel();
        $teamIds = [];
        foreach ($orgQ->column('id') ?: [] as $orgId) {
            $teamIds = array_merge($teamIds, self::teamIdsUnderOrg((int)$orgId, $orgModel));
        }
        $teamIds = array_values(array_unique(array_map('intval', $teamIds)));
        if (!$teamIds) return [];

        $q = Db::name('tm_paper_answer')->alias('a')
            ->join('tm_paper p', 'p.id = a.paper_id')
            ->join('tm_batch b', 'b.id = p.batch_id')
            ->join('tm_indicator i', 'i.id = a.indicator_id')
            ->where('p.team_id', 'in', $teamIds)
            ->where('p.status', 1)
            ->where('i.status', 1)
            ->field('a.question_id,a.indicator_id,a.item_score,a.weight,i.id as ind_id,i.parent_id,i.level_no,i.name as ind_name,i.version_id');
        if (!empty($filters['date_from'])) $q->where('p.submitted_at', '>=', $filters['date_from'] . ' 00:00:00');
        if (!empty($filters['date_to'])) $q->where('p.submitted_at', '<=', $filters['date_to'] . ' 23:59:59');
        $q = $q->select();
        $versionIds = [];
        foreach ($q as $row) $versionIds[] = (int)$row['version_id'];
        $versionIds = array_values(array_unique($versionIds));
        $indicatorRows = $versionIds ? Db::name('tm_indicator')->where('version_id', 'in', $versionIds)->field('id,parent_id,level_no,name')->select() : [];
        $byId = [];
        foreach ($indicatorRows ?: [] as $row) $byId[(int)$row['id']] = $row;
        $agg = [];
        foreach ($q as $row) {
            $cur = $byId[(int)$row['ind_id']] ?? null;
            $guard = 0;
            while ($cur && (int)$cur['level_no'] > 2 && $guard++ < 6) {
                $cur = isset($byId[(int)$cur['parent_id']]) ? $byId[(int)$cur['parent_id']] : null;
            }
            if (!$cur || (int)$cur['level_no'] !== 2) continue;
            $id = (int)$cur['id'];
            if (!isset($agg[$id])) $agg[$id] = ['indicator_id'=>$id,'indicator_name'=>$cur['name'],'got'=>0.0,'full'=>0.0,'questions'=>[]];
            $agg[$id]['got'] += (float)$row['item_score'];
            $agg[$id]['full'] += (float)$row['weight'];
            $qid = (int)$row['question_id'];
            if (!isset($agg[$id]['questions'][$qid])) $agg[$id]['questions'][$qid] = ['question_id'=>$qid,'loss'=>0.0,'count'=>0];
            $agg[$id]['questions'][$qid]['loss'] += max(0, (float)$row['weight'] - (float)$row['item_score']);
            $agg[$id]['questions'][$qid]['count']++;
        }
        $agg = array_values($agg);
        usort($agg, function ($a, $b) {
            $ra = $a['full'] > 0 ? ($a['full'] - $a['got']) / $a['full'] : 0;
            $rb = $b['full'] > 0 ? ($b['full'] - $b['got']) / $b['full'] : 0;
            return $rb <=> $ra;
        });
        $agg = array_slice($agg, 0, 10);
        $qids = [];
        foreach ($agg as &$item) {
            $item['loss'] = round($item['full'] - $item['got'], 2);
            $item['score_rate'] = $item['full'] > 0 ? round(100 * $item['got'] / $item['full'], 1) : 0;
            $item['loss_rate'] = $item['full'] > 0 ? round(100 * ($item['full'] - $item['got']) / $item['full'], 1) : 0;
            $item['questions'] = array_values($item['questions']);
            usort($item['questions'], function ($a, $b) { return $b['loss'] <=> $a['loss']; });
            $item['questions'] = array_slice($item['questions'], 0, 5);
            foreach ($item['questions'] as $qq) $qids[] = $qq['question_id'];
        }
        unset($item);
        $titles = $qids ? Db::name('tm_question')->where('id', 'in', array_unique($qids))->column('title', 'id') : [];
        foreach ($agg as &$item) foreach ($item['questions'] as &$qq) $qq['title'] = isset($titles[$qq['question_id']]) ? $titles[$qq['question_id']] : ('题目#' . $qq['question_id']);
        return $agg;
    }

    /**
     * 企业详情。
     *
     * @param int   $orgId
     * @param array $filters
     * @return array [bool ok, string msg, array|null data]
     */
    public static function companyDetail($orgId, array $filters = [])
    {
        $orgId = (int)$orgId;
        $org = Db::name('tm_org')->where('id', $orgId)->whereNull('deleted_at')->find();
        if (!$org) {
            return [false, '企业不存在', null];
        }
        if ((int)$org['org_type'] !== 1) {
            // 允许非公司节点下钻，但仍按该节点聚合
        }

        $filters['org_id'] = $orgId;
        $aggFilters = $filters;
        unset($aggFilters['level_code']); // 详情页等级筛只作用于班组列表
        $all = self::companyAggregates($aggFilters, [$org]);
        $summary = isset($all[0]) ? $all[0] : null;
        if (!$summary) {
            return [false, '无法汇总该企业', null];
        }

        $teamIds = self::teamIdsUnderOrg($orgId);
        $latestByTeam = self::latestTeamResults($teamIds, $aggFilters);

        $levelMeta = DashboardService::levelsMeta();
        $levelCounts = [];
        foreach ($levelMeta as $lv) {
            $levelCounts[$lv['level_code']] = [
                'level_code' => $lv['level_code'],
                'level_name' => $lv['level_name'],
                'count'      => 0,
            ];
        }
        $teams = [];
        foreach ($latestByTeam as $r) {
            $code = $r['level_code'] ?: '';
            if ($code !== '' && isset($levelCounts[$code])) {
                $levelCounts[$code]['count']++;
            }
            $weak = [];
            if (!empty($r['weak_indicator_json'])) {
                $decoded = json_decode($r['weak_indicator_json'], true);
                if (is_array($decoded)) {
                    foreach (array_slice($decoded, 0, 3) as $w) {
                        $weak[] = isset($w['indicator_name']) ? $w['indicator_name'] : '';
                    }
                }
            }
            $teams[] = [
                'team_id'        => (int)$r['team_id'],
                'team_name'      => $r['team_name'],
                'team_score'     => $r['team_score'] !== null ? round((float)$r['team_score'], 2) : null,
                'level_code'     => $r['level_code'],
                'level_name'     => $r['level_name'],
                'submit_cnt'     => (int)$r['submit_cnt'],
                'batch_id'       => (int)$r['batch_id'],
                'batch_name'     => $r['batch_name'],
                'scored_at'      => $r['scored_at'],
                'weak_top'       => array_values(array_filter($weak)),
            ];
        }
        if (!empty($filters['level_code'])) {
            $want = $filters['level_code'];
            $teams = array_values(array_filter($teams, function ($t) use ($want) {
                return $t['level_code'] === $want;
            }));
            // 重算等级分布与排名仅基于筛选后班组
            foreach ($levelCounts as &$lv) {
                $lv['count'] = 0;
            }
            unset($lv);
            foreach ($teams as $t) {
                $code = $t['level_code'] ?: '';
                if ($code !== '' && isset($levelCounts[$code])) {
                    $levelCounts[$code]['count']++;
                }
            }
        }
        usort($teams, function ($a, $b) {
            $sa = $a['team_score'] === null ? -1 : $a['team_score'];
            $sb = $b['team_score'] === null ? -1 : $b['team_score'];
            if ($sa == $sb) {
                return strcmp($a['team_name'], $b['team_name']);
            }
            return $sb <=> $sa;
        });
        foreach ($teams as $i => &$t) {
            $t['rank_no'] = $t['team_score'] === null ? null : ($i + 1);
        }
        unset($t);

        $feature = '';
        if (!empty($summary['level_code'])) {
            $row = LevelJudgeService::judge($summary['score']);
            $feature = $row && !empty($row['feature_desc']) ? $row['feature_desc'] : '';
        }

        $companyRadar = self::l1RadarScores($orgId, $filters);
        $nationalRadar = self::l1RadarScores(null, array_diff_key($filters, ['org_id' => 1]));

        return [true, 'ok', [
            'org' => [
                'org_id'   => (int)$org['id'],
                'name'     => $org['name'],
                'industry' => isset($org['industry']) ? $org['industry'] : '',
                'province' => isset($org['province']) ? $org['province'] : '',
                'city'     => isset($org['city']) ? $org['city'] : '',
            ],
            'summary' => $summary,
            'feature_desc' => $feature,
            'levels' => array_values($levelCounts),
            'teams'  => $teams,
            'team_total' => count($teamIds),
            'l1_radar_company'  => $companyRadar,
            'l1_radar_national' => $nationalRadar,
        ]];
    }

    /**
     * 企业聚合列表。
     *
     * @param array      $filters
     * @param array|null $orgRows 限定企业行；null 则查全部公司
     * @return array
     */
    public static function companyAggregates(array $filters = [], $orgRows = null)
    {
        if ($orgRows === null) {
            $q = Db::name('tm_org')
                ->where('org_type', 1)
                ->where('status', 1)
                ->where('is_demo', 0)
                ->whereNull('deleted_at');
            if (!empty($filters['org_id'])) {
                $q->where('id', (int)$filters['org_id']);
            }
            if (!empty($filters['industry'])) {
                $q->where('industry', $filters['industry']);
            }
            if (!empty($filters['province'])) {
                $q->where('province', $filters['province']);
            }
            if (!empty($filters['keyword'])) {
                $q->where('name', 'like', '%' . $filters['keyword'] . '%');
            }
            $orgRows = $q->order('sort_no asc,id asc')->select();
        }

        $out = [];
        $orgModel = new OrgModel();
        foreach ($orgRows ?: [] as $org) {
            $orgId = (int)$org['id'];
            $teamIds = self::teamIdsUnderOrg($orgId, $orgModel);
            $latest = self::latestTeamResults($teamIds, $filters);

            $scores = [];
            $batchIds = [];
            $paperCnt = 0;
            $lastScored = null;
            foreach ($latest as $r) {
                if ($r['team_score'] === null || $r['team_score'] === '') {
                    continue;
                }
                $scores[] = (float)$r['team_score'];
                $batchIds[(int)$r['batch_id']] = true;
                $paperCnt += (int)$r['submit_cnt'];
                if ($lastScored === null || $r['scored_at'] > $lastScored) {
                    $lastScored = $r['scored_at'];
                }
            }
            $score = $scores ? round(array_sum($scores) / count($scores), 2) : null;
            $lv = $score === null ? null : LevelJudgeService::judge($score);
            $levelCode = $lv ? $lv['level_code'] : '';
            $levelName = $lv ? $lv['level_name'] : '未测评';

            if (!empty($filters['level_code']) && $levelCode !== $filters['level_code']) {
                continue;
            }

            list($lng, $lat) = self::resolveCoord($org);

            $out[] = [
                'org_id'      => $orgId,
                'name'        => $org['name'],
                'industry'    => isset($org['industry']) ? (string)$org['industry'] : '',
                'province'    => isset($org['province']) ? (string)$org['province'] : '',
                'city'        => isset($org['city']) ? (string)$org['city'] : '',
                'lng'         => $lng,
                'lat'         => $lat,
                'score'       => $score,
                'score_text'  => $score === null ? '—' : number_format($score, 1, '.', ''),
                'level_code'  => $levelCode,
                'level_name'  => $levelName,
                'color'       => self::levelColor($levelCode),
                'eval_cnt'    => count($batchIds),
                'team_cnt'    => count($teamIds),
                'scored_team_cnt' => count($scores),
                'paper_cnt'   => $paperCnt,
                'scored_at'   => $lastScored,
            ];
        }

        usort($out, function ($a, $b) {
            $sa = $a['score'] === null ? -1 : $a['score'];
            $sb = $b['score'] === null ? -1 : $b['score'];
            if ($sa == $sb) {
                return strcmp($a['name'], $b['name']);
            }
            return $sb <=> $sa;
        });

        return $out;
    }

    /**
     * 一级指标得分率雷达（0~100）。$orgId 为空表示全国（筛选范围内全部企业下属班组）。
     *
     * @param int|null $orgId
     * @param array    $filters
     * @return array {indicators:[], values:[]}
     */
    public static function l1RadarScores($orgId = null, array $filters = [])
    {
        $teamIds = [];
        if ($orgId) {
            $teamIds = self::teamIdsUnderOrg((int)$orgId);
        } else {
            $companies = Db::name('tm_org')
                ->where('org_type', 1)
                ->where('status', 1)
                ->where('is_demo', 0)
                ->whereNull('deleted_at');
            if (!empty($filters['industry'])) {
                $companies->where('industry', $filters['industry']);
            }
            if (!empty($filters['province'])) {
                $companies->where('province', $filters['province']);
            }
            $orgIds = $companies->column('id');
            $orgModel = new OrgModel();
            foreach ($orgIds as $oid) {
                $teamIds = array_merge($teamIds, self::teamIdsUnderOrg((int)$oid, $orgModel));
            }
            $teamIds = array_values(array_unique($teamIds));
        }
        if (!$teamIds) {
            return ['indicators' => [], 'values' => []];
        }

        // 取涉及答卷上的最新指标版本
        $versionQ = Db::name('tm_paper')->alias('p')
            ->join('tm_batch b', 'b.id = p.batch_id')
            ->where('p.team_id', 'in', $teamIds)
            ->where('p.status', 1)
            ->whereNull('b.deleted_at')
            ->order('p.submitted_at desc,p.id desc');
        if (!empty($filters['date_from'])) {
            $versionQ->where('p.submitted_at', '>=', $filters['date_from'] . ' 00:00:00');
        }
        if (!empty($filters['date_to'])) {
            $versionQ->where('p.submitted_at', '<=', $filters['date_to'] . ' 23:59:59');
        }
        $versionId = (int)$versionQ->value('b.version_id');
        if ($versionId <= 0) {
            $versionId = (int)Db::name('tm_indicator_version')->where('status', 1)->order('id desc')->value('id');
        }
        if ($versionId <= 0) {
            return ['indicators' => [], 'values' => []];
        }

        $l1List = Db::name('tm_indicator')
            ->where('version_id', $versionId)
            ->where('level_no', 1)
            ->where('status', 1)
            ->order('sort_no asc,id asc')
            ->field('id,name')
            ->select();
        if (!$l1List) {
            return ['indicators' => [], 'values' => []];
        }

        // L3 -> L1 映射
        $allInd = Db::name('tm_indicator')
            ->where('version_id', $versionId)
            ->field('id,parent_id,level_no')
            ->select();
        $byId = [];
        foreach ($allInd as $row) {
            $byId[(int)$row['id']] = $row;
        }
        $l3ToL1 = [];
        foreach ($allInd as $row) {
            if ((int)$row['level_no'] !== 3) {
                continue;
            }
            $cur = $row;
            $guard = 0;
            while ($cur && (int)$cur['level_no'] > 1 && $guard < 6) {
                $pid = (int)$cur['parent_id'];
                if ($pid <= 0 || !isset($byId[$pid])) {
                    break;
                }
                $cur = $byId[$pid];
                $guard++;
            }
            if ($cur && (int)$cur['level_no'] === 1) {
                $l3ToL1[(int)$row['id']] = (int)$cur['id'];
            }
        }

        $ansQ = Db::name('tm_paper_answer')->alias('a')
            ->join('tm_paper p', 'p.id = a.paper_id')
            ->join('tm_batch b', 'b.id = p.batch_id')
            ->where('p.team_id', 'in', $teamIds)
            ->where('p.status', 1)
            ->where('b.version_id', $versionId)
            ->field('a.indicator_id, SUM(a.item_score) as got, SUM(a.weight) as full');
        if (!empty($filters['date_from'])) {
            $ansQ->where('p.submitted_at', '>=', $filters['date_from'] . ' 00:00:00');
        }
        if (!empty($filters['date_to'])) {
            $ansQ->where('p.submitted_at', '<=', $filters['date_to'] . ' 23:59:59');
        }
        $rows = $ansQ->group('a.indicator_id')->select();

        $bucket = [];
        foreach ($l1List as $l1) {
            $bucket[(int)$l1['id']] = ['got' => 0.0, 'full' => 0.0, 'name' => $l1['name']];
        }
        foreach ($rows ?: [] as $r) {
            $indId = (int)$r['indicator_id'];
            $l1Id = isset($l3ToL1[$indId]) ? $l3ToL1[$indId] : 0;
            if ($l1Id <= 0 || !isset($bucket[$l1Id])) {
                continue;
            }
            $bucket[$l1Id]['got'] += (float)$r['got'];
            $bucket[$l1Id]['full'] += (float)$r['full'];
        }

        $indicators = [];
        $values = [];
        foreach ($l1List as $l1) {
            $id = (int)$l1['id'];
            $indicators[] = $l1['name'];
            $full = $bucket[$id]['full'];
            $values[] = $full > 0 ? round(100 * $bucket[$id]['got'] / $full, 1) : 0;
        }
        return ['indicators' => $indicators, 'values' => $values];
    }

    /**
     * @param int            $orgId
     * @param OrgModel|null  $orgModel
     * @return array
     */
    private static function teamIdsUnderOrg($orgId, $orgModel = null)
    {
        if (!$orgModel) {
            $orgModel = new OrgModel();
        }
        $orgIds = $orgModel->collectDescendantIds((int)$orgId);
        if (!$orgIds) {
            return [];
        }
        $ids = Db::name('tm_team')
            ->where('org_id', 'in', $orgIds)
            ->whereNull('deleted_at')
            ->column('id');
        return array_map('intval', $ids ?: []);
    }

    /**
     * 每个班组取筛选期内最近一次算分结果。
     *
     * @param array $teamIds
     * @param array $filters
     * @return array
     */
    private static function latestTeamResults(array $teamIds, array $filters)
    {
        if (!$teamIds) {
            return [];
        }
        $q = Db::name('tm_team_result')->alias('tr')
            ->join('tm_team t', 't.id = tr.team_id')
            ->join('tm_batch b', 'b.id = tr.batch_id')
            ->where('tr.team_id', 'in', $teamIds)
            ->whereNull('t.deleted_at')
            ->whereNull('b.deleted_at')
            ->field('tr.team_id, tr.batch_id, tr.team_score, tr.level_code, tr.submit_cnt, tr.weak_indicator_json, tr.scored_at,
                t.name as team_name, b.name as batch_name');
        if (!empty($filters['date_from'])) {
            $q->where('tr.scored_at', '>=', $filters['date_from'] . ' 00:00:00');
        }
        if (!empty($filters['date_to'])) {
            $q->where('tr.scored_at', '<=', $filters['date_to'] . ' 23:59:59');
        }
        $rows = $q->order('tr.scored_at desc,tr.id desc')->select();
        $nameMap = LevelJudgeService::nameMap();
        $latest = [];
        foreach ($rows ?: [] as $r) {
            $tid = (int)$r['team_id'];
            if (isset($latest[$tid])) {
                continue;
            }
            $code = $r['level_code'] ?: '';
            $r['level_name'] = isset($nameMap[$code]) ? $nameMap[$code] : $code;
            $latest[$tid] = $r;
        }
        return array_values($latest);
    }

    /**
     * @param array $org
     * @return array [lng|null, lat|null]
     */
    private static function resolveCoord(array $org)
    {
        if (isset($org['lng'], $org['lat']) && $org['lng'] !== null && $org['lng'] !== ''
            && $org['lat'] !== null && $org['lat'] !== '') {
            return [(float)$org['lng'], (float)$org['lat']];
        }
        $province = isset($org['province']) ? $org['province'] : '';
        if ($province !== '' && isset(self::$provinceCenter[$province])) {
            return self::$provinceCenter[$province];
        }
        return [null, null];
    }

    /**
     * @param string $levelCode
     * @return string
     */
    private static function levelColor($levelCode)
    {
        $levelCode = (string)$levelCode;
        return isset(self::$levelColors[$levelCode]) ? self::$levelColors[$levelCode] : self::$levelColors[''];
    }
}
