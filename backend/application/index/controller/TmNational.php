<?php
namespace app\index\controller;

use app\index\service\NationalDashboardService;
use app\index\service\NationalOverviewService;
use app\index\service\ExcelHelper;
use app\index\service\DocxHelper;
use app\index\service\CompanyDetailService;
use app\index\service\PdfHelper;
use think\Request;

/**
 * 全国运营看板（企业地图 + 企业详情）。
 */
class TmNational extends Base
{
    protected function checkPower()
    {
        $scope=(int)($this->currentAdmin['org_id'] ?? 0);
        if ($scope>0) {
            $actions=['company','companydata','exportcompany','exportquestions'];
            return in_array(strtolower($this->request->action()),$actions,true)
                && (int)$this->request->param('org_id',0)===$scope;
        }
        return parent::checkPower();
    }
    /**
     * 解析公共筛选参数。
     *
     * @return array
     */
    private function filters()
    {
        $req = Request::instance();
        $filters = [
            'industry'   => $req->param('industry', '', 'trim'),
            'province'   => $req->param('province', '', 'trim'),
            'level_code' => $req->param('level_code', '', 'trim'),
            'date_from'  => $req->param('date_from', '', 'trim'),
            'date_to'    => $req->param('date_to', '', 'trim'),
            'keyword'    => $req->param('keyword', '', 'trim'),
        ];
        foreach (['date_from','date_to'] as $key) {
            if ($filters[$key] === '') continue;
            $date=\DateTime::createFromFormat('!Y-m-d',$filters[$key]);
            if (!$date || $date->format('Y-m-d')!==$filters[$key]) abort(400,'日期格式应为 YYYY-MM-DD');
        }
        if ($filters['date_from'] && $filters['date_to'] && $filters['date_from']>$filters['date_to']) abort(400,'开始日期不能晚于结束日期');
        return $filters;
    }

    /**
     * 全国大盘页面。
     *
     * @return mixed
     */
    public function index()
    {
        $opts = NationalDashboardService::filterOptions();
        $this->assign('industries', $opts['industries']);
        $this->assign('provinces', $opts['provinces']);
        $this->assign('levels', $opts['levels']);
        return $this->fetch();
    }

    /**
     * 大盘数据 API。
     *
     * @return \think\response\Json
     */
    public function overview()
    {
        $data = NationalOverviewService::build($this->filters(), false, true);
        return json(DefaultResponse(0, true, 'ok', $data));
    }

    public function exportRaw()
    {
        $data = NationalOverviewService::build($this->filters(), true);
        $rows=[];
        foreach ($data['raw'] as $r) {
            $title=(string)$r['question_title'];
            if (preg_match('/^[\s]*[=+@-]/u',$title)) $title="'".$title;
            $rows[]=[$r['paper_id'],$r['batch_id'],$r['team_id'],$r['question_id'],$title,$r['weight'],$r['item_score'],$r['score_ratio']];
        }
        ExcelHelper::download('行业测评原始答题数据.csv',['答卷ID','批次ID','班组ID','题目ID','原始题目','满分','得分','选项得分比例'],$rows);
    }

    public function exportReport()
    {
        $filters=$this->filters();
        $data=NationalOverviewService::build($filters);
        $doc=DocxHelper::create('全国冶金行业安全成熟度分析报告',date('Y-m-d H:i'));
        $doc->writeParagraph('筛选条件：'.json_encode($filters,JSON_UNESCAPED_UNICODE));
        $doc->writeParagraph($data['tip']);
        $k=$data['kpi'];
        $doc->writeTable(['企业总数','测评班组数','有效样本数','行业平均分'],[[$k['company_cnt'],$k['team_cnt'],$k['paper_cnt'],$k['avg_score_text']]]);
        $doc->writeSection('成熟度分布'); $rows=[];
        foreach ($data['levels'] as $lv) $rows[]=[$lv['level_name'],$lv['count'],$lv['percent_text']];
        $doc->writeTable(['等级','企业数量','占已测评企业比例'],$rows);
        $doc->writeSection('一级指标行业均值'); $rows=[];
        foreach ($data['l1_radar']['indicators'] as $i=>$name) $rows[]=[$name,$data['l1_radar']['values'][$i].'%'];
        $doc->writeTable(['指标','得分率'],$rows);
        $doc->writeSection('二级指标短板与高频问题');
        foreach ($data['weak_top10'] as $w) {
            $doc->writeParagraph($w['indicator_name'].'：失分率 '.$w['loss_rate'].'%');
            foreach (array_slice($w['questions'],0,5) as $q) $doc->writeParagraph($q['title'].'（失分出现 '.$q['count'].' 次）');
        }
        $doc->writeSection('企业测评数据'); $rows=[];
        foreach ($data['companies'] as $c) $rows[]=[$c['name'],$c['province'].$c['city'],$c['industry'],$c['measured_team_cnt'],$c['score_text'],$c['level_name'],implode('、',$c['strengths']),implode('、',$c['weaknesses']),$c['scored_at']];
        $doc->writeTable(['企业','地区','行业','测评班组','综合分','等级','相对优势指标','相对短板指标','算分时间'],$rows);
        $doc->download('全国行业分析报告.docx');
    }

    /**
     * 企业详情页。
     *
     * @return mixed
     */
    public function company()
    {
        $orgId = (int)Request::instance()->param('org_id', 0);
        $opts = NationalDashboardService::filterOptions();
        $this->assign('org_id', $orgId);
        $this->assign('scoped_account', !empty($this->currentAdmin['org_id']) ? 1 : 0);
        $this->assign('levels', $opts['levels']);
        return $this->fetch();
    }

    /**
     * 企业详情 API。
     *
     * @return \think\response\Json
     */
    public function companyData()
    {
        $orgId = (int)Request::instance()->param('org_id', 0);
        if ($orgId <= 0) {
            return json(DefaultResponse(400, false, '请指定企业'));
        }
        list($ok, $msg, $data) = CompanyDetailService::build($orgId, $this->filters());
        if (!$ok) {
            return json(DefaultResponse(400, false, $msg));
        }
        return json(DefaultResponse(0, true, 'ok', $data));
    }

    public function exportQuestions()
    {
        list($ok,$msg,$d)=CompanyDetailService::build((int)$this->request->param('org_id',0),$this->filters());
        if (!$ok) abort(400,$msg);
        $rows=[];
        foreach ($d['questions'] as $q) {
            $title=$q['title'];
            if (preg_match('/^[\s]*[=+@-]/u',$title)) $title="'".$title;
            $rows[]=[$title,$q['count'],$q['team_count'],$d['is_demo']?'演示':'真实测评'];
        }
        ExcelHelper::download('企业高频问题.csv',['问题项','失分出现次数','涉及班组数','数据来源'],$rows);
    }

    public function exportCompany()
    {
        list($ok,$msg,$d)=CompanyDetailService::build((int)$this->request->param('org_id',0),$this->filters());
        if (!$ok) abort(400,$msg);
        $raw=trim((string)$this->request->param('team_ids',''));
        $teams=$d['teams'];
        if ($raw!=='') {
            $ids=array_map('intval',explode(',',$raw));
            $teams=array_values(array_filter($teams,function($t)use($ids){return in_array((int)$t['team_id'],$ids,true);}));
            if (!$teams || count($teams)!==count(array_unique($ids))) abort(400,'选择的班组不属于当前企业或筛选范围');
        }
        $d['report_team_scope'] = $raw !== '';
        $pdf=$this->companyReportPdf($d,$teams);
        $filename = \app\index\service\CompanyReportService::title($d, $teams);
        PdfHelper::download($pdf,$filename.'.pdf');
    }

    protected function companyReportPdf(array $d, array $teams)
    {
        return \app\index\service\CompanyReportService::build($d, $teams);
    }
}
