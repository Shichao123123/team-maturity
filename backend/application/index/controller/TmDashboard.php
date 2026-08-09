<?php
namespace app\index\controller;

use app\index\model\OrgModel;
use app\index\service\DashboardService;
use app\index\service\ExcelHelper;
use think\Request;

class TmDashboard extends Base
{
    private function assignFilters()
    {
        $this->assign('batches', DashboardService::listBatchesWithResult());
        $this->assign('orgs', (new OrgModel())->getSelectOptions());
    }

    /** 成熟度看板 */
    public function index()
    {
        $this->assignFilters();
        return $this->fetch('tm_dashboard/index');
    }

    /** 成长趋势 */
    public function trend()
    {
        $this->assignFilters();
        $teams = \think\Db::name('tm_team')
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->order('id asc')
            ->field('id, name, org_id')
            ->select();
        $this->assign('teams', $teams);
        return $this->fetch('tm_dashboard/trend');
    }

    /** 班组排行榜 */
    public function rank()
    {
        $this->assignFilters();
        return $this->fetch('tm_dashboard/rank');
    }

    public function levelDist()
    {
        $req = Request::instance();
        $batchId = (int)$req->param('batch_id', 0);
        $orgId = (int)$req->param('org_id', 0);
        $data = DashboardService::levelDist($batchId, $orgId);
        return json(DefaultResponse(0, true, 'ok', $data));
    }

    public function trendData()
    {
        $req = Request::instance();
        $orgId = (int)$req->param('org_id', 0);
        $teamIds = $req->param('team_ids/a', []);
        if (empty($teamIds) && $req->param('team_ids', '') !== '') {
            $raw = $req->param('team_ids', '');
            $teamIds = is_array($raw) ? $raw : explode(',', $raw);
        }
        $data = DashboardService::trend($orgId, $teamIds);
        return json(DefaultResponse(0, true, 'ok', $data));
    }

    public function rankData()
    {
        $req = Request::instance();
        $batchId = (int)$req->param('batch_id', 0);
        $orgId = (int)$req->param('org_id', 0);
        $limit = (int)$req->param('limit', 50);
        $data = DashboardService::rank($batchId, $orgId, $limit);
        return json(DefaultResponse(0, true, 'ok', $data));
    }

    public function exportRank()
    {
        $req = Request::instance();
        $batchId = (int)$req->param('batch_id', 0);
        $orgId = (int)$req->param('org_id', 0);
        $data = DashboardService::rank($batchId, $orgId, 500);
        if (empty($data['list'])) {
            return json(DefaultResponse(400, false, '暂无可导出的排行数据'));
        }
        $headers = ['排名', '班组', '所属组织', '班组得分', '成熟度等级', '提交人数', '管理建议'];
        $rows = [];
        foreach ($data['list'] as $r) {
            $rows[] = [
                '排名'       => $r['rank_no'],
                '班组'       => $r['team_name'],
                '所属组织'   => $r['org_name'],
                '班组得分'   => $r['team_score'],
                '成熟度等级' => $r['level_name'],
                '提交人数'   => $r['submit_cnt'],
                '管理建议'   => $r['advice'],
            ];
        }
        $name = '班组排行_' . ($data['batch'] ? preg_replace('/[\\\\\/:*?"<>|]/', '_', $data['batch']['name']) : 'export') . '.xls';
        ExcelHelper::download($name, $headers, $rows);
    }
}
