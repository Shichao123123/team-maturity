<?php
namespace app\index\service;

use app\index\model\OrgModel;
use think\Db;

/** 首页所有图表、表格和导出共享同一算分日期与企业筛选范围。 */
class NationalOverviewService
{
    public static function build(array $filters, $includeRaw = false)
    {
        $companies = NationalDashboardService::companyAggregates($filters);
        // 演示企业存放在组织表中，但不创建答卷/算分记录；只在全国首页展示。
        // 真实测评服务通过 is_demo=0 排除它们，因此不会污染真实结果。
        $demoTeamsTotal = 0; $demoPapersTotal = 0; $demoBatchesTotal = 0;
        $demoQ = Db::name('tm_org')->where('org_type', 1)->where('status', 1)->where('is_demo', 1)->whereNull('deleted_at');
        if (!empty($filters['industry'])) $demoQ->where('industry', $filters['industry']);
        if (!empty($filters['province'])) $demoQ->where('province', $filters['province']);
        if (!empty($filters['keyword'])) $demoQ->where('name', 'like', '%' . $filters['keyword'] . '%');
        $demoRows = $demoQ->order('sort_no asc,id asc')->select();
        foreach ($demoRows ?: [] as $demo) {
            $demoDate = isset($demo['demo_scored_at']) ? $demo['demo_scored_at'] : null;
            if (!empty($filters['date_from']) && $demoDate && $demoDate < $filters['date_from'].' 00:00:00') continue;
            if (!empty($filters['date_to']) && $demoDate && $demoDate > $filters['date_to'].' 23:59:59') continue;
            $levelCode = isset($demo['demo_level_code']) ? (string)$demo['demo_level_code'] : '';
            if (!empty($filters['level_code']) && $levelCode !== $filters['level_code']) continue;
            $score = isset($demo['demo_score']) && $demo['demo_score'] !== null ? (float)$demo['demo_score'] : null;
            $levelName = isset($demo['demo_level_name']) && $demo['demo_level_name'] !== '' ? $demo['demo_level_name'] : '演示数据';
            $strengths = json_decode(isset($demo['demo_strengths_json']) ? $demo['demo_strengths_json'] : '[]', true);
            $weaknesses = json_decode(isset($demo['demo_weaknesses_json']) ? $demo['demo_weaknesses_json'] : '[]', true);
            $rates = json_decode(isset($demo['demo_l1_json']) ? $demo['demo_l1_json'] : '[]', true);
            $teams = (int)(isset($demo['demo_team_cnt']) ? $demo['demo_team_cnt'] : 0);
            $papers = (int)(isset($demo['demo_sample_cnt']) ? $demo['demo_sample_cnt'] : 0);
            $evals = (int)(isset($demo['demo_eval_cnt']) ? $demo['demo_eval_cnt'] : 0);
            if (!is_array($strengths)) $strengths = [];
            if (!is_array($weaknesses)) $weaknesses = [];
            if (!is_array($rates)) $rates = [];
            $companies[] = [
                'org_id'=>(int)$demo['id'], 'name'=>(string)$demo['name'],
                'industry'=>(string)$demo['industry'], 'province'=>(string)$demo['province'], 'city'=>(string)$demo['city'],
                'lng'=>isset($demo['lng']) && $demo['lng'] !== null ? (float)$demo['lng'] : null,
                'lat'=>isset($demo['lat']) && $demo['lat'] !== null ? (float)$demo['lat'] : null,
                'score'=>$score, 'score_text'=>$score === null ? '—' : number_format($score, 1, '.', ''),
                'level_code'=>$levelCode, 'level_name'=>$levelName, 'color'=>'#00d8ff',
                'eval_cnt'=>$evals, 'team_cnt'=>$teams, 'scored_team_cnt'=>$teams,
                'paper_cnt'=>$papers, 'scored_at'=>$demoDate, 'measured_team_cnt'=>$teams, 'sample_cnt'=>$papers,
                'strengths'=>$strengths, 'weaknesses'=>$weaknesses, 'indicator_rates'=>$rates, 'is_demo'=>1,
            ];
            $demoTeamsTotal += $teams; $demoPapersTotal += $papers; $demoBatchesTotal += $evals;
        }
        $owners = [];
        $orgModel = new OrgModel();
        foreach ($companies as $index => &$company) {
            $ids = $orgModel->collectDescendantIds($company['org_id']);
            $teams = Db::name('tm_team')->where('org_id', 'in', $ids)->whereNull('deleted_at')->column('id');
            foreach ($teams as $tid) $owners[(int)$tid][] = $index;
            $company['measured_team_cnt'] = 0;
            $company['sample_cnt'] = 0;
            $company['strengths'] = [];
            $company['weaknesses'] = [];
        }
        unset($company);
        $rows = [];
        if ($owners) {
            $q = Db::name('tm_paper_answer')->alias('a')
                ->join('tm_paper p', 'p.id=a.paper_id')
                ->join('tm_batch b', 'b.id=p.batch_id')
                ->join('tm_team_result tr', 'tr.batch_id=p.batch_id AND tr.team_id=p.team_id')
                ->join('tm_question q', 'q.id=a.question_id', 'LEFT')
                ->where('p.team_id', 'in', array_keys($owners))->where('p.status', 1)
                ->whereNull('b.deleted_at')->where('p.submitted_at', '<=', Db::raw('tr.scored_at'));
            if (!empty($filters['date_from'])) $q->where('tr.scored_at', '>=', $filters['date_from'].' 00:00:00');
            if (!empty($filters['date_to'])) $q->where('tr.scored_at', '<=', $filters['date_to'].' 23:59:59');
            $rows = $q->field('p.id as paper_id,p.team_id,p.batch_id,a.question_id,a.indicator_id,a.weight,a.item_score,a.score_ratio,q.title as question_title')->select();
        }
        $indicators = Db::name('tm_indicator')->field('id,parent_id,level_no,code,name')->select();
        $byId = [];
        foreach ($indicators as $i) $byId[$i['id']] = $i;
        $l1 = []; $l2 = []; $companyInd = []; $papers = []; $teams = []; $batches = []; $companyPapers = []; $companyTeams = [];
        foreach ($rows as $r) {
            $papers[$r['paper_id']] = true; $teams[$r['team_id']] = true; $batches[$r['batch_id']] = true;
            foreach ($owners[$r['team_id']] as $owner) {
                $companyPapers[$owner][$r['paper_id']] = true;
                $companyTeams[$owner][$r['team_id']] = true;
            }
            $cur = isset($byId[$r['indicator_id']]) ? $byId[$r['indicator_id']] : null;
            $path = []; $seen = [];
            while ($cur && !isset($seen[$cur['id']])) {
                $seen[$cur['id']] = true; $path[(int)$cur['level_no']] = $cur;
                $cur = isset($byId[$cur['parent_id']]) ? $byId[$cur['parent_id']] : null;
            }
            foreach ([1, 2] as $level) {
                if (!isset($path[$level])) continue;
                $ind = $path[$level];
                // 只有层级路径、编码与名称一致才跨版本合并。
                $key = '';
                for ($depth=1; $depth<=$level; $depth++) {
                    if (isset($path[$depth])) $key .= $path[$depth]['code'].'|'.$path[$depth]['name'].'/';
                }
                if ($level === 1) $bucket =& $l1; else $bucket =& $l2;
                if (!isset($bucket[$key])) $bucket[$key] = ['indicator_id'=>$ind['id'],'indicator_name'=>$ind['name'],'got'=>0.0,'full'=>0.0,'questions'=>[]];
                $bucket[$key]['got'] += (float)$r['item_score']; $bucket[$key]['full'] += (float)$r['weight'];
                if ($level === 2 && (float)$r['weight'] > (float)$r['item_score']) {
                    $title = $r['question_title'] ?: ('题目#'.$r['question_id']);
                    if (!isset($bucket[$key]['questions'][$title])) $bucket[$key]['questions'][$title] = ['title'=>$title,'count'=>0];
                    $bucket[$key]['questions'][$title]['count']++;
                }
                if ($level === 1) foreach ($owners[$r['team_id']] as $owner) {
                    if (!isset($companyInd[$owner][$key])) $companyInd[$owner][$key] = ['name'=>$ind['name'],'got'=>0.0,'full'=>0.0];
                    $companyInd[$owner][$key]['got'] += (float)$r['item_score']; $companyInd[$owner][$key]['full'] += (float)$r['weight'];
                }
                unset($bucket);
            }
        }
        $radar = ['indicators'=>[], 'values'=>[]];
        foreach ($l1 as $i) if ($i['full'] > 0) { $radar['indicators'][]=$i['indicator_name']; $radar['values'][]=round(100*$i['got']/$i['full'],1); }
        $weak = [];
        foreach ($l2 as $i) if ($i['full'] > 0 && $i['full'] > $i['got']) {
            $i['loss_rate']=round(100*($i['full']-$i['got'])/$i['full'],1);
            $i['questions']=array_values($i['questions']);
            usort($i['questions'],function($a,$b){return $b['count'] <=> $a['count'];});
            $weak[]=$i;
        }
        usort($weak,function($a,$b){return $b['loss_rate'] <=> $a['loss_rate'];});
        $levels=[];
        foreach (DashboardService::levelsMeta() as $lv) $levels[$lv['level_code']]=['level_code'=>$lv['level_code'],'level_name'=>$lv['level_name'],'count'=>0,'percent'=>0,'percent_text'=>'0%'];
        $sum=0; $scored=0; $map=[];
        foreach ($companies as $index => &$c) {
            $c['measured_team_cnt']=count(isset($companyTeams[$index])?$companyTeams[$index]:[]);
            $c['sample_cnt']=count(isset($companyPapers[$index])?$companyPapers[$index]:[]);
            $rates=[];
            foreach (isset($companyInd[$index])?$companyInd[$index]:[] as $i) if ($i['full']>0) $rates[]=['name'=>$i['name'],'score'=>round(100*$i['got']/$i['full'],1)];
            usort($rates,function($a,$b){return $b['score'] <=> $a['score'];});
            $c['indicator_rates']=$rates;
            $c['strengths']=array_column(array_slice($rates,0,3),'name');
            $c['weaknesses']=array_column(array_slice(array_reverse($rates),0,3),'name');
            if ($c['score']!==null) { $sum+=$c['score']; $scored++; if (isset($levels[$c['level_code']])) $levels[$c['level_code']]['count']++; }
            if ($c['lng']!==null && $c['lat']!==null) $map[]=array_merge($c,['value'=>[$c['lng'],$c['lat'],$c['score']],'has_score'=>$c['score']!==null]);
        }
        unset($c);
        foreach ($levels as &$lv) { $lv['percent']=$scored?round(100*$lv['count']/$scored,1):0; $lv['percent_text']=$lv['percent'].'%'; }
        unset($lv);
        $result=['kpi'=>['company_cnt'=>count($companies),'scored_cnt'=>$scored,'team_cnt'=>count($teams)+$demoTeamsTotal,'paper_cnt'=>count($papers)+$demoPapersTotal,'eval_cnt'=>count($batches)+$demoBatchesTotal,'avg_score_text'=>$scored?number_format($sum/$scored,1,'.',''):'—'],
            'companies'=>$companies,'map_points'=>$map,'levels'=>array_values($levels),'l1_radar'=>$radar,'weak_top10'=>array_slice($weak,0,10),
            'tip'=>'演示企业仅用于首页展示，未写入答卷和算分结果；真实测评统计、结果查询与统计分析已排除演示数据。'];
        if ($includeRaw) $result['raw']=$rows;
        return $result;
    }
}
