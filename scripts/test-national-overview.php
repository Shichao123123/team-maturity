<?php
define('APP_PATH', dirname(__DIR__) . '/backend/application/');
define('RUNTIME_PATH', dirname(__DIR__) . '/backend/runtime/');
require dirname(__DIR__) . '/backend/thinkphp/base.php';
\think\Loader::addNamespace('app', APP_PATH);
require APP_PATH . 'common.php';
\think\Config::set(include APP_PATH . 'database.php', 'database');
use app\index\service\NationalOverviewService;
use app\index\service\NationalDashboardService;
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$data=NationalOverviewService::build([],true);
$papers=[];$teams=[];
foreach($data['raw'] as $r){$papers[$r['paper_id']]=true;$teams[$r['team_id']]=true;}
check(count($papers)===$data['kpi']['paper_cnt'],'sample count differs from export');
check(count($teams)===$data['kpi']['team_cnt'],'team count differs from export');
foreach($data['weak_top10'] as $w){
    check($w['loss_rate']>=0 && $w['loss_rate']<=100,'invalid loss rate');
    $previous=PHP_INT_MAX;
    foreach($w['questions'] as $q){check($q['count']<=$previous,'frequency order');$previous=$q['count'];}
}
$empty=NationalOverviewService::build(['keyword'=>'__missing_company_928472__'],true);
check(!$empty['companies'] && !$empty['raw'] && !$empty['weak_top10'] && !$empty['l1_radar']['values'],'empty filter leaked results');
$old=NationalOverviewService::build(['date_to'=>'1900-01-01'],true);
check(!$old['raw'] && !$old['weak_top10'] && !$old['l1_radar']['values'],'date filter leaked results');
foreach($data['levels'] as $lv){
    $filtered=NationalOverviewService::build(['level_code'=>$lv['level_code']],true);
    foreach($filtered['companies'] as $c)check($c['level_code']===$lv['level_code'],'level filter leaked company');
}
$home=NationalOverviewService::build([],false,true);
$demo=array_values(array_filter($home['companies'],function($c){return !empty($c['is_demo']);}));
check(count($demo)===24,'homepage demo company count differs');
check($home['kpi']['company_cnt']===count($data['companies'])+24,'homepage demo KPI differs');
list($detailOk,$detailMsg,$detail)=NationalDashboardService::companyDetail($demo[0]['org_id']);
check($detailOk && !empty($detail['summary']['is_demo']) && count($detail['teams'])>0,'demo company detail unavailable: '.$detailMsg);
echo 'PASS: overview, export counts, frequency, empty/date/level filters'.PHP_EOL;
echo json_encode($data['kpi'],JSON_UNESCAPED_UNICODE).PHP_EOL;
echo 'PASS: database demo isolation and company detail'.PHP_EOL;
