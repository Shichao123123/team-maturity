<?php
namespace app\index\controller;

use app\index\model\OrgModel;
use app\index\service\DashboardService;
use app\index\service\ExcelHelper;
use think\Request;

class TmDashboard extends Base
{
    /**
     * 为看板页面注入批次与组织筛选项。
     *
     * @return void
     */
    private function assignFilters()
    {
        $this->assign('batches', DashboardService::listBatchesWithResult());
        $this->assign('orgs', (new OrgModel())->getSelectOptions());
    }

    /**
     * 成熟度看板页面。
     *
     * @return mixed 渲染 tm_dashboard/index 视图
     */
    public function index()
    {
        $this->assignFilters();
        return $this->fetch('tm_dashboard/index');
    }

    /**
     * 成长趋势页面。
     *
     * @return mixed 渲染 tm_dashboard/trend 视图
     */
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

    /**
     * 班组排行榜页面。
     *
     * @return mixed 渲染 tm_dashboard/rank 视图
     */
    public function rank()
    {
        $this->assignFilters();
        return $this->fetch('tm_dashboard/rank');
    }

    /**
     * 成熟度等级分布 API（按批次、组织筛选）。
     *
     * @return \think\response\Json 返回各等级班组数量、均分等统计 JSON
     */
    public function levelDist()
    {
        $req = Request::instance();
        $batchId = (int)$req->param('batch_id', 0);
        $orgId = (int)$req->param('org_id', 0);
        $data = DashboardService::levelDist($batchId, $orgId);
        return json(DefaultResponse(0, true, 'ok', $data));
    }

    /**
     * 班组成长趋势数据 API（多班组得分走势）。
     *
     * @return \think\response\Json 返回趋势折线图所需数据
     */
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

    /**
     * 班组排行榜数据 API。
     *
     * @return \think\response\Json 返回指定批次/组织下的排行列表
     */
    public function rankData()
    {
        $req = Request::instance();
        $batchId = (int)$req->param('batch_id', 0);
        $orgId = (int)$req->param('org_id', 0);
        $limit = (int)$req->param('limit', 50);
        $data = DashboardService::rank($batchId, $orgId, $limit);
        return json(DefaultResponse(0, true, 'ok', $data));
    }

    /**
     * 导出班组排行榜 Excel 文件。
     *
     * @return void|\think\response\Json 无数据时返回 JSON 错误，否则直接下载 xls
     */
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
