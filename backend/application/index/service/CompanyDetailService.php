<?php
namespace app\index\service;

use think\Db;

/** 企业详情所有图表、诊断和报告共用的只读数据。 */
class CompanyDetailService
{
    public static function build($orgId, array $filters = [])
    {
        $baseFilters = $filters;
        unset($baseFilters['level_code']);
        list($ok, $msg, $d) = NationalDashboardService::companyDetail($orgId, $baseFilters);
        if (!$ok) return [$ok, $msg, $d];
        $demo = !empty($d['summary']['is_demo']);
        $d['is_demo'] = $demo;
        $d['weak_top5'] = []; $d['questions'] = [];
        $d['period'] = (!empty($filters['date_from']) ? $filters['date_from'] : '不限').' 至 '.(!empty($filters['date_to']) ? $filters['date_to'] : '不限');
        if ($demo) {
            self::demo($d, $filters);
        } else {
            $scope = $baseFilters; $scope['org_id'] = $orgId;
            $overview = NationalOverviewService::build($scope);
            $national = NationalOverviewService::build($baseFilters);
            $d['l1_radar_company'] = $overview['l1_radar'];
            $d['l1_radar_national'] = $national['l1_radar'];
            $ids = array_column($d['teams'], 'team_id');
            $d['weak_top5'] = array_slice($overview['weak_top10'], 0, 5);
            $inds = Db::name('tm_indicator')->column('*', 'id');
            $l2 = []; $questions = [];
            foreach ($d['teams'] as &$t) {
                $q = Db::name('tm_paper')->alias('p')->join('tm_member m','m.id=p.member_id','LEFT')
                    ->where('p.team_id',$t['team_id'])->where('p.batch_id',$t['batch_id'])
                    ->where('p.status',1)->where('p.submitted_at','<=',$t['scored_at']);
                $papers = $q->field('p.id,p.personal_score,m.role_code')->select();
                $leader=[]; $members=[];
                foreach ($papers as $p) if ($p['personal_score'] !== null) {
                    if ($p['role_code']==='leader') $leader[]=(float)$p['personal_score'];
                    else $members[]=(float)$p['personal_score'];
                }
                $t['leader_score']=self::mean($leader); $t['member_score']=self::mean($members);
                $pids=array_column($papers,'id'); $rates=[]; $tq=[];
                $answers=$pids?Db::name('tm_paper_answer')->alias('a')->join('tm_question q','q.id=a.question_id','LEFT')->where('a.paper_id','in',$pids)->field('a.*,q.title')->select():[];
                foreach ($answers as $a) {
                    $cur=$inds[$a['indicator_id']] ?? null; $path=[]; $seen=[];
                    while ($cur && !isset($seen[$cur['id']])) {
                        $seen[$cur['id']]=true; $path[(int)$cur['level_no']]=$cur;
                        $cur=$inds[$cur['parent_id']] ?? null;
                    }
                    if (isset($path[1])) {
                        $key=$path[1]['name'];
                        if (!isset($rates[$key])) $rates[$key]=['got'=>0,'full'=>0];
                        $rates[$key]['got']+=(float)$a['item_score']; $rates[$key]['full']+=(float)$a['weight'];
                    }
                    if (isset($path[2])) {
                        $key=($path[1]['name'] ?? '').'/'.$path[2]['name'];
                        if (!isset($l2[$key])) $l2[$key]=['indicator_name'=>$path[2]['name'],'got'=>0,'full'=>0];
                        $l2[$key]['got']+=(float)$a['item_score']; $l2[$key]['full']+=(float)$a['weight'];
                    }
                    if ((float)$a['weight']>(float)$a['item_score']) {
                        $key=(string)$a['question_id'];
                        if (!isset($questions[$key])) $questions[$key]=['title'=>$a['title'] ?: '题目#'.$key,'count'=>0,'teams'=>[]];
                        $questions[$key]['count']++; $questions[$key]['teams'][$t['team_id']]=true;
                        $tq[$key]=$questions[$key]['title'];
                    }
                }
                $t['indicator_rates']=[];
                foreach ($rates as $name=>$v) if ($v['full']>0) $t['indicator_rates'][]=['name'=>$name,'score'=>round(100*$v['got']/$v['full'],1)];
                usort($t['indicator_rates'],function($a,$b){return $b['score']<=>$a['score'];});
                $t['strengths']=array_column(array_slice($t['indicator_rates'],0,2),'name');
                $t['problems']=array_values($tq);
                $history=Db::name('tm_team_result')->alias('r')->join('tm_batch b','b.id=r.batch_id')->whereNull('b.deleted_at')->where('r.team_id',$t['team_id']);
                if (!empty($filters['date_from'])) $history->where('r.scored_at','>=',$filters['date_from'].' 00:00:00');
                if (!empty($filters['date_to'])) $history->where('r.scored_at','<=',$filters['date_to'].' 23:59:59');
                $t['history']=$history->order('r.scored_at asc,r.id asc')->field('r.team_score,r.level_code,r.scored_at,b.name as batch_name')->select();
                $t['measures']=Db::name('tm_empower_measure')->where('status',1)->where('level_code',$t['level_code'])->order('sort_no asc')->field('title,content')->select();
            }
            unset($t);
            $d['weak_top5']=[];
            foreach ($l2 as $v) if ($v['full']>0 && $v['full']>$v['got']) $d['weak_top5'][]=['indicator_name'=>$v['indicator_name'],'loss_rate'=>round(100*($v['full']-$v['got'])/$v['full'],1)];
            usort($d['weak_top5'],function($a,$b){return $b['loss_rate']<=>$a['loss_rate'];});
            $d['weak_top5']=array_slice($d['weak_top5'],0,5);
            foreach ($questions as $q) { $q['team_count']=count($q['teams']); unset($q['teams']); $d['questions'][]=$q; }
            usort($d['questions'],function($a,$b){return $b['count']<=>$a['count'];});
        }
        $d['completed_count']=count($d['teams']);
        $d['completion_rate']=$d['team_total']?round(100*$d['completed_count']/$d['team_total'],1):0;
        $d['rank']=null; $d['rank_total']=0;
        if (!$demo) {
            $peers=NationalDashboardService::companyAggregates(array_merge($baseFilters,['industry'=>$d['org']['industry']]));
            foreach ($peers as $p) if ($p['score']!==null) {
                $d['rank_total']++;
                if ((int)$p['org_id']===(int)$orgId) $d['rank']=$d['rank_total'];
            }
        }
        if (!empty($filters['level_code'])) $d['teams']=array_values(array_filter($d['teams'],function($t)use($filters){return $t['level_code']===$filters['level_code'];}));
        foreach ($d['levels'] as &$lv) $lv['count']=count(array_filter($d['teams'],function($t)use($lv){return $t['level_code']===$lv['level_code'];}));
        unset($lv);
        return [true,'ok',$d];
    }

    private static function mean(array $v) { return $v?round(array_sum($v)/count($v),1):null; }

    private static function demo(array &$d, array $filters)
    {
        $date=$d['teams']?$d['teams'][0]['scored_at']:null;
        if ($date && ((!empty($filters['date_from']) && $date<$filters['date_from'].' 00:00:00') || (!empty($filters['date_to']) && $date>$filters['date_to'].' 23:59:59'))) {
            $d['teams']=[]; $d['summary']['score_text']='—'; $d['summary']['level_name']='未测评'; $d['summary']['level_code']='';
            $d['l1_radar_company']=['indicators'=>[],'values'=>[]]; return;
        }
        // 演示企业使用一组固定且完整的行业基准，保证所有一级指标均可对标。
        // 数据只存在于演示详情响应中，不写入真实答卷或统计结果。
        $benchmark = [82.4, 80.8, 78.6, 81.5, 79.2, 83.1];
        $benchmarkValues = [];
        foreach ($d['l1_radar_company']['indicators'] as $i=>$name) {
            $benchmarkValues[] = $benchmark[$i % count($benchmark)];
        }
        $d['l1_radar_national'] = [
            'indicators'=>$d['l1_radar_company']['indicators'],
            'values'=>$benchmarkValues,
        ];
        $names=['炼铁','热轧','炼钢','能源动力','设备检修','原料运输'];
        $weak=['安全培训效果评估','隐患排查治理','现场作业程序审批','班组应急物资','班组台账资料管理'];
        foreach ($d['teams'] as $i=>&$t) {
            $t['team_name']=''.$names[$i%6].(intdiv($i,6)+1).'班';
            $t['leader_score']=round(min(100,$t['team_score']+1.7),1); $t['member_score']=round($t['team_score']-0.8,1);
            $t['indicator_rates']=[];
            foreach ($d['l1_radar_company']['indicators'] as $j=>$n) $t['indicator_rates'][]=['name'=>$n,'score'=>round(max(0,min(100,$t['team_score']+(($i+$j)%7-3)*1.2)),1)];
            $t['strengths']=[$d['l1_radar_company']['indicators'][$i%max(1,count($d['l1_radar_company']['indicators']))] ?? '安全管理'];
            $t['weak_top']=[$weak[$i%5]]; $t['problems']=[''.$weak[$i%5].'记录不完整'];
            $t['measures']=[['title'=>'演示整改建议','content'=>'完善记录，明确责任人与整改期限，完成复核。']];
            $t['history']=[['team_score'=>$t['team_score'],'level_code'=>$t['level_code'],'scored_at'=>$t['scored_at'],'batch_name'=>'数据库演示记录']];
        }
        unset($t);
        foreach ($weak as $i=>$n) {
            $d['weak_top5'][]=['indicator_name'=>''.$n,'loss_rate'=>round(32-$i*4.6,1)];
            $d['questions'][]=['title'=>''.$n.'是否落实并留存记录？','count'=>25-$i*3,'team_count'=>min(count($d['teams']),8-$i)];
        }
    }
}
