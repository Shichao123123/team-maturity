<?php
require __DIR__.'/test-national-overview.php';
use app\index\service\CompanyDetailService;
foreach ([$demo[0]['org_id'],$data['companies'][0]['org_id']] as $oid) {
    list($ok,$msg,$detail)=CompanyDetailService::build($oid);
    check($ok,'detail: '.$msg);
    check($detail['completed_count']<= $detail['team_total'],'completion exceeds total');
    if (!empty($detail['is_demo'])) {
        check(count($detail['l1_radar_company']['indicators'])===count($detail['l1_radar_national']['values']),'demo national benchmark is incomplete');
    }
    foreach ($detail['teams'] as $t) {
        check(isset($t['indicator_rates'],$t['history'],$t['problems'],$t['measures']),'missing diagnosis');
        foreach($t['indicator_rates'] as $r)check($r['score']>=0&&$r['score']<=100,'invalid indicator score');
    }
    list($ok,$msg,$old)=CompanyDetailService::build($oid,['date_to'=>'1900-01-01']);
    check($ok && !$old['teams'] && !$old['weak_top5'],'date filter leaked data');
    list($ok,$msg,$filtered)=CompanyDetailService::build($oid,['level_code'=>'essential']);
    foreach($filtered['teams'] as $t)check($t['level_code']==='essential','level filter leaked data');
    echo 'PASS company '.$oid.' teams='.count($detail['teams']).' questions='.count($detail['questions']).PHP_EOL;
}
class CompanyAccessProbe extends \app\index\controller\TmNational {
    public function __construct() {}
    public function pdf($d) { return $this->companyReportPdf($d,array_slice($d['teams'],0,1)); }
    public function probe($scope,$action,$org) {
        $this->currentAdmin=['org_id'=>$scope,'role_id'=>1];
        $this->request=\think\Request::create('/TmNational/'.$action,'GET',['org_id'=>$org]);
        $this->request->action($action);
        return $this->checkPower();
    }
}
$probe=new CompanyAccessProbe();
check($probe->probe(1,'companyData',1),'own company denied');
check(!$probe->probe(1,'companyData',2),'other company allowed');
check(!$probe->probe(1,'overview',1),'national overview allowed');
check(!$probe->probe(1,'exportCompany',2),'other company export allowed');
echo 'PASS company account scope'.PHP_EOL;
$pdf=$probe->pdf($detail);
$bytes=$pdf->Output('company-test.pdf','S');
check(substr($bytes,0,5)==='%PDF-' && strlen($bytes)>1000,'report did not generate a valid PDF');
echo 'PASS company PDF generation ('.strlen($bytes).' bytes)'.PHP_EOL;
