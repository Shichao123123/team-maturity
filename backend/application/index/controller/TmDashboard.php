<?php
namespace app\index\controller;

use app\index\model\OrgModel;
use app\index\service\DashboardService;
use app\index\service\ExcelHelper;
use app\index\service\PdfHelper;
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
        // 班组列表改为按组织 AJAX 加载，避免首屏塞入全量班组/组织树
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
        $page = (int)$req->param('page', 1);
        $limit = (int)$req->param('limit', 10);
        if ($page < 1) {
            $page = 1;
        }
        if ($limit < 1) {
            $limit = 10;
        }
        $data = DashboardService::levelDist($batchId, $orgId, $page, $limit);
        return json([
            'code'    => 0,
            'success' => true,
            'msg'     => 'ok',
            'count'   => isset($data['team_count']) ? (int)$data['team_count'] : count($data['teams']),
            'data'    => $data,
        ]);
    }

    /**
     * 趋势页：按组织加载可选班组（避免首屏渲染全量班组）。
     *
     * @return \think\response\Json
     */
    public function teamsByOrg()
    {
        $orgId = (int)Request::instance()->param('org_id', 0);
        $list = DashboardService::teamsForOrg($orgId);
        return json(DefaultResponse(0, true, 'ok', ['list' => $list, 'count' => count($list)]));
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
        $page = (int)$req->param('page', 1);
        $limit = (int)$req->param('limit', 10);
        if ($page < 1) {
            $page = 1;
        }
        if ($limit < 1) {
            $limit = 10;
        }
        $data = DashboardService::rank($batchId, $orgId, $page, $limit, 15);
        return json([
            'code'    => 0,
            'success' => true,
            'msg'     => 'ok',
            'count'   => isset($data['count']) ? (int)$data['count'] : count($data['list']),
            'data'    => $data,
        ]);
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
        $data = DashboardService::rank($batchId, $orgId, 0, 500);
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

    /**
     * 导出班组排行榜 PDF 文件（含柱状图）。
     *
     * @return void|\think\response\Json
     */
    public function exportRankPdf()
    {
        $req = Request::instance();
        $batchId = (int)$req->param('batch_id', 0);
        $orgId = (int)$req->param('org_id', 0);
        $data = DashboardService::rank($batchId, $orgId, 0, 500);
        if (empty($data['list'])) {
            return json(DefaultResponse(400, false, '暂无可导出的排行数据'));
        }
        $batchName = $data['batch'] ? $data['batch']['name'] : '排行导出';
        $chartPath = null;
        try {
            $chartPath = \app\index\service\PdfChartHelper::renderRankBars($data['list'], 15);
        } catch (\Exception $e) {
            $chartPath = null;
        }
        $pdf = PdfHelper::create('数据分析 · 班组排行', $batchName);
        PdfHelper::writeMeta($pdf, [
            ['label' => '批次', 'value' => $batchName],
            ['label' => '上榜班组数', 'value' => (string)count($data['list'])],
            ['label' => '说明', 'value' => isset($data['tip']) ? $data['tip'] : ''],
        ]);
        if ($chartPath) {
            PdfHelper::writeSection($pdf, '排行图表');
            PdfHelper::writeImage($pdf, $chartPath, 186);
        }
        PdfHelper::writeSection($pdf, '排行明细');
        $rows = [];
        foreach ($data['list'] as $r) {
            $rows[] = [
                $r['rank_no'],
                $r['team_name'],
                $r['org_name'] ?: '—',
                PdfHelper::fmtScore($r['team_score']),
                $r['level_name'] ?: '—',
                $r['submit_cnt'],
                $r['advice'] ?: '—',
            ];
        }
        PdfHelper::writeTable(
            $pdf,
            ['排名', '班组', '所属组织', '得分', '成熟度等级', '提交人数', '管理建议'],
            $rows,
            [8, 18, 18, 10, 14, 10, 22]
        );
        PdfHelper::cleanupImages($chartPath);
        PdfHelper::download($pdf, '班组排行_' . PdfHelper::safeFilename($batchName) . '.pdf');
    }

    /**
     * 导出成熟度看板 PDF（含等级分布图）。
     *
     * @return void|\think\response\Json
     */
    public function exportLevelDistPdf()
    {
        $req = Request::instance();
        $batchId = (int)$req->param('batch_id', 0);
        $orgId = (int)$req->param('org_id', 0);
        $data = DashboardService::levelDist($batchId, $orgId, 0, 0);
        if (empty($data['result_team_cnt'])) {
            return json(DefaultResponse(400, false, '暂无可导出的看板数据'));
        }
        $chartPath = null;
        try {
            $chartPath = \app\index\service\PdfChartHelper::renderPieLevels(isset($data['levels']) ? $data['levels'] : []);
        } catch (\Exception $e) {
            return json(DefaultResponse(500, false, '图表生成失败：' . $e->getMessage()));
        }
        $orgName = '';
        if ($orgId > 0) {
            $orgName = (string)\think\Db::name('tm_org')->where('id', $orgId)->value('name');
        }
        $pdf = PdfHelper::create('数据分析 · 成熟度看板', $data['batch_name'] ?: '');
        PdfHelper::writeMeta($pdf, [
            ['label' => '批次', 'value' => $data['batch_name'] ?: '—'],
            ['label' => '组织', 'value' => $orgName !== '' ? $orgName : '全部组织'],
            ['label' => '算分时间', 'value' => !empty($data['scored_at']) ? $data['scored_at'] : '—'],
            ['label' => '统计班组数', 'value' => (string)$data['result_team_cnt']],
            ['label' => '平均成熟得分', 'value' => PdfHelper::fmtScore($data['avg_score'])],
            ['label' => '本质安全型', 'value' => (string)$data['essential_cnt']],
            ['label' => '被动型', 'value' => (string)$data['passive_cnt']],
        ]);
        PdfHelper::writeSection($pdf, '等级分布图');
        PdfHelper::writeImage($pdf, $chartPath, 186);

        PdfHelper::writeSection($pdf, '等级明细清单');
        $levelRows = [];
        foreach ($data['levels'] as $lv) {
            $levelRows[] = [
                $lv['level_name'],
                $lv['count'],
                $lv['percent_text'],
                isset($lv['advice']) ? $lv['advice'] : '—',
            ];
        }
        PdfHelper::writeTable($pdf, ['等级', '数量', '占比', '管理建议'], $levelRows, [28, 14, 18, 40]);

        PdfHelper::writeSection($pdf, '班组得分清单');
        $teamRows = [];
        foreach ($data['teams'] as $i => $t) {
            $teamRows[] = [
                $i + 1,
                $t['team_name'],
                PdfHelper::fmtScore($t['team_score']),
                isset($t['level_name']) ? $t['level_name'] : '—',
            ];
        }
        PdfHelper::writeTable($pdf, ['序号', '班组', '得分', '成熟度等级'], $teamRows, [12, 40, 20, 28]);
        PdfHelper::cleanupImages($chartPath);
        PdfHelper::download($pdf, '成熟度看板_' . PdfHelper::safeFilename($data['batch_name'] ?: 'export') . '.pdf');
    }

    /**
     * 导出成长趋势 PDF（含折线图）。
     *
     * @return void|\think\response\Json
     */
    public function exportTrendPdf()
    {
        $req = Request::instance();
        $orgId = (int)$req->param('org_id', 0);
        $teamIds = $req->param('team_ids/a', []);
        if (empty($teamIds) && $req->param('team_ids', '') !== '') {
            $raw = $req->param('team_ids', '');
            $teamIds = is_array($raw) ? $raw : explode(',', $raw);
        }
        $data = DashboardService::trend($orgId, $teamIds);
        if (empty($data['batches']) || empty($data['series'])) {
            return json(DefaultResponse(400, false, isset($data['tip']) ? $data['tip'] : '暂无可导出的趋势数据'));
        }
        $chartPath = null;
        try {
            $chartPath = \app\index\service\PdfChartHelper::renderTrendLines($data['batches'], $data['series']);
        } catch (\Exception $e) {
            return json(DefaultResponse(500, false, '图表生成失败：' . $e->getMessage()));
        }
        $orgName = '';
        if ($orgId > 0) {
            $orgName = (string)\think\Db::name('tm_org')->where('id', $orgId)->value('name');
        }
        $teamNames = [];
        foreach ($data['series'] as $s) {
            $teamNames[] = $s['team_name'];
        }
        $pdf = PdfHelper::create('数据分析 · 成长趋势', implode('、', array_slice($teamNames, 0, 4)));
        PdfHelper::writeMeta($pdf, [
            ['label' => '组织', 'value' => $orgName !== '' ? $orgName : '全部组织'],
            ['label' => '班组', 'value' => implode('、', $teamNames)],
            ['label' => '批次跨度', 'value' => count($data['batches']) . ' 个批次'],
            ['label' => '说明', 'value' => isset($data['tip']) ? $data['tip'] : ''],
        ]);
        PdfHelper::writeSection($pdf, '趋势折线图');
        PdfHelper::writeImage($pdf, $chartPath, 186);

        PdfHelper::writeSection($pdf, '得分明细');
        $headers = ['班组'];
        foreach ($data['batches'] as $b) {
            $headers[] = $b['batch_name'];
        }
        $widths = [18];
        $rest = 82;
        $bn = count($data['batches']);
        $each = $bn > 0 ? round($rest / $bn, 2) : $rest;
        for ($i = 0; $i < $bn; $i++) {
            $widths[] = $each;
        }
        $rows = [];
        foreach ($data['series'] as $s) {
            $row = [$s['team_name']];
            foreach ($s['scores'] as $sc) {
                $row[] = ($sc === null || $sc === '') ? '—' : PdfHelper::fmtScore($sc);
            }
            $rows[] = $row;
        }
        PdfHelper::writeTable($pdf, $headers, $rows, $widths);
        PdfHelper::cleanupImages($chartPath);
        PdfHelper::download($pdf, '成长趋势_' . PdfHelper::safeFilename(implode('_', array_slice($teamNames, 0, 3))) . '.pdf');
    }
}
